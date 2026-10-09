<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Knowledge\ElementorKnowledgeStore;
use Stonewright\WpMcp\Security\Permissions;

/**
 * `stonewright/elementor-knowledge-refresh`.
 *
 * Self-update path: the LLM (or a cron job) hands Stonewright a URL
 * and Stonewright rewrites the matching article in the private knowledge
 * store (`<uploads>/stonewright-private/knowledge/elementor/<hub>/<slug>.md`)
 * when the content has actually changed. The store is guarded against direct
 * access and is the only place the knowledge readers look.
 *
 * Two fetch modes:
 *   - vanilla wp_remote_get (works for `developers.elementor.com`
 *     and other SSR sites);
 *   - explicit `body` arg — the caller fetched the page with a real
 *     browser automation tool and passes the rendered
 *     DOM text. Elementor help pages can be SPA-rendered,
 *     and plain WebFetch returns nav-only HTML, so this mode is the
 *     production path for those URLs.
 *
 * Pipeline:
 *   1. Validate URL host (elementor.com or a subdomain), hub and body.
 *   2. Resolve target subdirectory (widgets / editor / theme /
 *      developer / custom-widget / help-root) from `hub` or by URL
 *      heuristic.
 *   3. Fetch (vanilla) or accept `body` (browser-rendered).
 *   4. Detect SPA-shell signature when fetched vanilla; bail with
 *      `status: "needs_js_render"` so the caller knows to retry via
 *      the companion harvester.
 *   5. Compute SHA-256 of body bytes. If unchanged from the existing
 *      file's frontmatter, return content_changed:false.
 *   6. Write new markdown body with refreshed frontmatter
 *      (title, source_url, fetched_at, content_hash, applies_to,
 *      related_widgets, harvest_source).
 *   7. Append a one-line entry to `_change_log.md`.
 *
 * @stonewright-status sandboxed
 */
final class KnowledgeRefresh extends AbilityKernel {

	private const ABILITY    = 'stonewright/elementor-knowledge-refresh';

	public function name(): string {
		return self::ABILITY;
	}

	public function label(): string {
		return __( 'Refresh Elementor knowledge base entry', 'stonewright' );
	}

	public function description(): string {
		return __(
			'Self-updates the Stonewright Elementor knowledge base from a canonical URL. Articles are stored in a private, access-guarded folder under the uploads directory (stonewright-private/knowledge/elementor); the knowledge search, explain-editor and describe-widget tools read only that folder and return nothing until the first refresh. USE THIS WHEN: a user asks about a newly-released Elementor feature the cached docs don\'t cover, OR `elementor-describe-widget` returns `stale: true`, OR a doc was edited upstream. Two modes: vanilla wp_remote_get (good for developers.elementor.com SSR) and explicit `body` (caller provides browser-rendered DOM text — the production path for elementor.com/help/* SPA URLs). Returns `{ content_changed, status, file_path, slug, hash, hub }`. When the vanilla fetch returns an SPA shell, replies `status: "needs_js_render"` so the caller can retry via the companion harvester.',
			'stonewright'
		);
	}

	public function category(): string {
		return 'knowledge';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'url' ],
			'properties'           => [
				'confirmation_token' => [ 'type' => 'string' ],
				'url'  => [
					'type'        => 'string',
					'pattern'     => '^https?://',
					'description' => 'Canonical URL to refresh. The host must be elementor.com or a subdomain of it, such as developers.elementor.com.',
				],
				'hub'  => [
					'type'        => 'string',
					'enum'        => [ 'widgets', 'editor', 'theme', 'developer', 'custom-widget', 'help-root' ],
					'description' => 'Hub folder to file the article under. Inferred from the URL when omitted.',
				],
				'body' => [
					'type'        => 'string',
					'description' => 'Optional: pre-rendered DOM text body. When supplied, Stonewright skips the wp_remote_get fetch and uses this verbatim — the only way to refresh SPA-rendered URLs.',
				],
				'title' => [
					'type'        => 'string',
					'description' => 'Override the article title parsed from the body.',
				],
				'related_widgets' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Widget slugs the article relates to. Becomes the `related_widgets` frontmatter list.',
				],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'ok', 'content_changed', 'status' ],
			'properties' => [
				'ok'              => [ 'type' => 'boolean' ],
				'content_changed' => [ 'type' => 'boolean' ],
				'status'          => [ 'type' => 'string' ],
				'file_path'       => [ 'type' => [ 'string', 'null' ] ],
				'slug'            => [ 'type' => [ 'string', 'null' ] ],
				'hash'            => [ 'type' => [ 'string', 'null' ] ],
				'hub'             => [ 'type' => [ 'string', 'null' ] ],
				'previous_hash'   => [ 'type' => [ 'string', 'null' ] ],
				'fetched_at'      => [ 'type' => [ 'string', 'null' ] ],
				'reason'          => [ 'type' => [ 'string', 'null' ] ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		return Permissions::manage_options();
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $a ): array|\WP_Error {
				$url = isset( $a['url'] ) && is_string( $a['url'] ) ? trim( $a['url'] ) : '';
				if ( $url === '' || ! preg_match( '#^https?://#', $url ) ) {
					return $this->error( 'invalid_url', __( 'A valid http(s) URL is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$host = parse_url( $url, PHP_URL_HOST );
				if ( ! is_string( $host ) || ! self::is_allowed_host( $host ) ) {
					return $this->error( 'invalid_host', __( 'URL must be on elementor.com or one of its subdomains, such as developers.elementor.com.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$hub = array_key_exists( 'hub', $a ) && null !== $a['hub'] ? $a['hub'] : self::infer_hub_from_url( $url );
				if ( ! ElementorKnowledgeStore::is_valid_hub( $hub ) ) {
					return $this->error(
						'invalid_hub',
						sprintf(
							/* translators: %s: comma-separated list of hub names. */
							__( 'hub must be one of: %s.', 'stonewright' ),
							implode( ', ', ElementorKnowledgeStore::HUBS )
						),
						[ 'status' => 400 ]
					);
				}

				$body            = isset( $a['body'] ) && is_string( $a['body'] ) ? $a['body'] : null;
				$title_override  = isset( $a['title'] ) && is_string( $a['title'] ) ? $a['title'] : null;
				$related_widgets = ( isset( $a['related_widgets'] ) && is_array( $a['related_widgets'] ) )
					? array_values( array_filter( $a['related_widgets'], 'is_string' ) )
					: [];

				$harvest_source = 'wp-remote-get';
				if ( $body === null ) {
					// Vanilla fetch path.
					// reject_unsafe_urls refuses local and private addresses, for the URL and for every redirect it follows.
					$resp = wp_remote_get( $url, [
						'timeout'            => 20,
						'redirection'        => 3,
						'reject_unsafe_urls' => true,
						'user-agent'         => 'StonewrightMCP/1.0 (+https://github.com/cosmincraciun97/stonewright-wp-mcp)',
					] );
					if ( is_wp_error( $resp ) ) {
						return $this->error( 'fetch_failed', $resp->get_error_message(), [ 'status' => 502 ] );
					}
					$code = (int) wp_remote_retrieve_response_code( $resp );
					if ( $code < 200 || $code >= 300 ) {
						return $this->error( 'fetch_failed', sprintf( 'Upstream returned HTTP %d.', $code ), [ 'status' => 502, 'http_code' => $code ] );
					}
					$body = (string) wp_remote_retrieve_body( $resp );

					if ( self::looks_like_spa_shell( $body ) ) {
						return [
							'ok'              => true,
							'content_changed' => false,
							'status'          => 'needs_js_render',
							'file_path'       => null,
							'slug'            => null,
							'hash'            => null,
							'hub'             => $hub,
							'previous_hash'   => null,
							'fetched_at'      => null,
							'reason'          => __( 'Vanilla fetch returned an SPA shell. Re-run with a browser-rendered harvester and resubmit with `body`.', 'stonewright' ),
						];
					}
				} else {
					$harvest_source = 'caller-rendered';
				}

				$body_text = self::extract_body_text( $body );
				$hash      = 'sha256-' . hash( 'sha256', $body_text );

				$slug = self::slug_from_url( $url );
				$file = ElementorKnowledgeStore::file_path( $hub, $slug );
				if ( null === $file ) {
					return $this->error( 'store_unavailable', __( 'The private Elementor knowledge folder under uploads could not be created or protected, or the target path is not inside it.', 'stonewright' ), [ 'status' => 500 ] );
				}

				$previous_hash = null;
				if ( is_file( $file ) ) {
					$previous_hash = self::extract_hash_from_frontmatter( (string) file_get_contents( $file ) );
					if ( $previous_hash === $hash ) {
						return [
							'ok'              => true,
							'content_changed' => false,
							'status'          => 'unchanged',
							'file_path'       => ElementorKnowledgeStore::relative( $file ),
							'slug'            => $slug,
							'hash'            => $hash,
							'hub'             => $hub,
							'previous_hash'   => $previous_hash,
							'fetched_at'      => self::extract_field_from_frontmatter( (string) file_get_contents( $file ), 'fetched_at' ),
							'reason'          => null,
						];
					}
				}

				// Write the new markdown file.
				$title       = $title_override ?? self::extract_title( $body_text, $slug );
				$fetched_at  = gmdate( 'c' );
				$frontmatter = "---\n"
					. "title: " . self::yaml_escape( $title ) . "\n"
					. "source_url: " . $url . "\n"
					. "fetched_at: " . $fetched_at . "\n"
					. "content_hash: " . $hash . "\n"
					. "applies_to: [" . self::yaml_list( self::infer_applies_to( $hub, $slug ) ) . "]\n"
					. "related_widgets: [" . self::yaml_list( $related_widgets ) . "]\n"
					. "harvest_source: " . $harvest_source . "\n"
					. "---\n\n"
					. trim( $body_text ) . "\n";

				$written = file_put_contents( $file, $frontmatter ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				if ( false === $written ) {
					return $this->error( 'write_failed', sprintf( 'Could not write %s', ElementorKnowledgeStore::relative( $file ) ), [ 'status' => 500 ] );
				}

				// Append change log (H.3).
				self::append_change_log( $url, $hub, $slug, $hash, $previous_hash, $fetched_at );

				return [
					'ok'              => true,
					'content_changed' => true,
					'status'          => $previous_hash === null ? 'created' : 'updated',
					'file_path'       => ElementorKnowledgeStore::relative( $file ),
					'slug'            => $slug,
					'hash'            => $hash,
					'hub'             => $hub,
					'previous_hash'   => $previous_hash,
					'fetched_at'      => $fetched_at,
					'reason'          => null,
				];
			}
		);
	}

	// -----------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------

	/** True for elementor.com and its subdomains; never for a host that merely ends in the same letters. */
	private static function is_allowed_host( string $host ): bool {
		$host = strtolower( $host );
		return 'elementor.com' === $host || str_ends_with( $host, '.elementor.com' );
	}

	private static function infer_hub_from_url( string $url ): string {
		if ( str_contains( $url, 'developers.elementor.com' ) ) {
			return 'developer';
		}
		if ( str_contains( $url, '/help/build-with-the-editor/widgets/' ) ) {
			return 'widgets';
		}
		if ( str_contains( $url, '/widgets/' ) ) {
			return 'widgets';
		}
		if ( str_contains( $url, '/help/build-with-the-editor/getting-started-editor' ) || str_contains( $url, '/help/build-with-the-editor/' ) ) {
			return 'editor';
		}
		if ( str_contains( $url, '/help/design-your-theme/' ) || str_contains( $url, '/help/theme-builder/' ) ) {
			return 'theme';
		}
		if ( str_contains( $url, '/blog/custom-wordpress-widget' ) || str_contains( $url, '/custom-widget' ) ) {
			return 'custom-widget';
		}
		return 'help-root';
	}

	private static function slug_from_url( string $url ): string {
		$path = parse_url( $url, PHP_URL_PATH ) ?: '';
		$parts = array_values( array_filter( explode( '/', trim( $path, '/' ) ), static fn( $p ) => $p !== '' ) );
		$slug = end( $parts ) ?: 'untitled';
		$slug = preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $slug ) ?? 'untitled';
		// A slug starts with a letter or digit, so it can never be a dot segment or an underscore-prefixed file.
		$slug = substr( ltrim( $slug, '.-_' ), 0, 120 );
		$slug = rtrim( $slug, '-' );
		return $slug !== '' ? $slug : 'untitled';
	}

	private static function looks_like_spa_shell( string $html ): bool {
		// Empirical signature: the SPA wrapper for elementor.com/help/* has
		// only the nav skeleton + a root div + a chunked JS bundle; the
		// article body never appears in initial HTML. ~500 visible text
		// chars worth of content, mostly nav menus.
		$plain = trim( strip_tags( $html ) );
		$plain = preg_replace( '/\s+/', ' ', $plain ) ?? '';
		if ( strlen( $plain ) < 600 ) {
			return true;
		}
		// Common SPA root markers — Next.js or React app shell.
		if ( str_contains( $html, '<div id="__next"' ) || str_contains( $html, 'data-react-helmet' ) ) {
			// Combined with short body — likely shell.
			if ( strlen( $plain ) < 1500 ) {
				return true;
			}
		}
		return false;
	}

	private static function extract_body_text( string $raw ): string {
		// If caller passed something that already looks like a markdown body
		// (has `## ` headings), pass through. Else strip HTML tags.
		if ( preg_match( '/^##\s+/m', $raw ) ) {
			return trim( $raw );
		}
		$text = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $raw ) ?? $raw;
		$text = preg_replace( '/<style\b[^>]*>.*?<\/style>/is', '', $text ) ?? $text;
		$text = strip_tags( $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text ) ?? '';
		return trim( $text );
	}

	private static function extract_title( string $body, string $fallback ): string {
		if ( preg_match( '/^\s*([^.\n]{6,120})/u', $body, $m ) ) {
			return trim( $m[1] );
		}
		return ucwords( str_replace( '-', ' ', $fallback ) );
	}

	private static function extract_hash_from_frontmatter( string $content ): ?string {
		return self::extract_field_from_frontmatter( $content, 'content_hash' );
	}

	private static function extract_field_from_frontmatter( string $content, string $field ): ?string {
		if ( ! preg_match( '/^---\s*\R(.*?)\R---/s', $content, $m ) ) {
			return null;
		}
		$frontmatter = $m[1];
		if ( preg_match( '/^' . preg_quote( $field, '/' ) . ':\s*(.+)$/m', $frontmatter, $fm ) ) {
			return trim( $fm[1] );
		}
		return null;
	}

	/** @return array<int, string> */
	private static function infer_applies_to( string $hub, string $slug ): array {
		switch ( $hub ) {
			case 'widgets':
				return [ 'widget:' . preg_replace( '/-widget(-pro)?$/', '', $slug ) ];
			case 'editor':
				return [ 'editor:v3', 'editor:v4' ];
			case 'theme':
				return [ 'theme-builder' ];
			case 'developer':
				return [ 'developer-api' ];
			case 'custom-widget':
				return [ 'custom-widget' ];
			default:
				return [];
		}
	}

	private static function yaml_escape( string $v ): string {
		if ( $v === '' ) {
			return '""';
		}
		if ( preg_match( "/[:#\n\r\"']|^[-?]/", $v ) ) {
			$v = str_replace( '"', '\"', $v );
			return '"' . $v . '"';
		}
		return $v;
	}

	private static function yaml_list( array $items ): string {
		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) || $item === '' ) {
				continue;
			}
			$out[] = self::yaml_escape( $item );
		}
		return implode( ', ', $out );
	}

	private static function append_change_log( string $url, string $hub, string $slug, string $hash, ?string $previous_hash, string $fetched_at ): void {
		$log_path = ElementorKnowledgeStore::change_log_path();
		if ( null === $log_path ) {
			return;
		}
		$entry    = sprintf(
			"- %s — `%s/%s.md` (%s) — %s\n",
			$fetched_at,
			$hub,
			$slug,
			$previous_hash === null ? 'created' : 'updated',
			$url
		);
		if ( ! is_file( $log_path ) ) {
			$entry = "# Elementor knowledge base — change log\n\n" . $entry;
		}
		file_put_contents( $log_path, $entry, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}
