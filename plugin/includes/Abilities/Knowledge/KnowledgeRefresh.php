<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\Knowledge;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Phase H.1 — `stonewright/elementor-knowledge-refresh`.
 *
 * Self-update path: the LLM (or a cron job) hands Stonewright a URL
 * and Stonewright rewrites the matching `docs/knowledge/elementor/`
 * file when the content has actually changed.
 *
 * Two fetch modes:
 *   - vanilla wp_remote_get (works for `developers.elementor.com`
 *     and other SSR sites);
 *   - explicit `body` arg — the caller fetched the page with a real
 *     browser automation tool and passes the rendered
 *     DOM text. Phase 0 surfaced that elementor.com/help/* is a SPA
 *     and plain WebFetch returns nav-only HTML, so this mode is the
 *     production path for those URLs.
 *
 * Pipeline:
 *   1. Validate URL / hub / body.
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
 *   7. Append a one-line entry to `_change_log.md` (H.3).
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
			'Self-updates the Stonewright Elementor knowledge base from a canonical URL. USE THIS WHEN: a user asks about a newly-released Elementor feature the cached docs don\'t cover, OR `elementor-describe-widget` returns `stale: true`, OR a doc was edited upstream. Two modes: vanilla wp_remote_get (good for developers.elementor.com SSR) and explicit `body` (caller provides browser-rendered DOM text — the production path for elementor.com/help/* SPA URLs). Returns `{ content_changed, status, file_path, slug, hash, hub }`. When the vanilla fetch returns an SPA shell, replies `status: "needs_js_render"` so the caller can retry via the companion harvester.',
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
				'url'  => [
					'type'        => 'string',
					'pattern'     => '^https?://',
					'description' => 'Canonical URL to refresh. Must be on elementor.com or developers.elementor.com.',
				],
				'hub'  => [
					'type'        => 'string',
					'enum'        => [ 'widgets', 'editor', 'theme', 'developer', 'custom-widget', 'help-root' ],
					'description' => 'Hub to file the article under. Inferred from URL when omitted.',
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
		return $this->audit(
			$args,
			function ( array $a ): array|\WP_Error {
				$url = isset( $a['url'] ) && is_string( $a['url'] ) ? trim( $a['url'] ) : '';
				if ( $url === '' || ! preg_match( '#^https?://#', $url ) ) {
					return $this->error( 'invalid_url', __( 'A valid http(s) URL is required.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$host = parse_url( $url, PHP_URL_HOST ) ?: '';
				$host = strtolower( $host );
				if ( ! ( str_ends_with( $host, 'elementor.com' ) || str_ends_with( $host, 'developers.elementor.com' ) ) ) {
					return $this->error( 'invalid_host', __( 'URL must be on elementor.com or developers.elementor.com.', 'stonewright' ), [ 'status' => 400 ] );
				}

				$hub = isset( $a['hub'] ) && is_string( $a['hub'] ) ? $a['hub'] : self::infer_hub_from_url( $url );

				$body            = isset( $a['body'] ) && is_string( $a['body'] ) ? $a['body'] : null;
				$title_override  = isset( $a['title'] ) && is_string( $a['title'] ) ? $a['title'] : null;
				$related_widgets = ( isset( $a['related_widgets'] ) && is_array( $a['related_widgets'] ) )
					? array_values( array_filter( $a['related_widgets'], 'is_string' ) )
					: [];

				$harvest_source = 'wp-remote-get';
				if ( $body === null ) {
					// Vanilla fetch path.
					$resp = wp_remote_get( $url, [
						'timeout'  => 20,
						'user-agent' => 'StonewrightMCP/1.0 (+https://github.com/cosmincraciun97/stonewright-wp-mcp)',
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

				$slug  = self::slug_from_url( $url );
				$dir   = self::knowledge_dir() . '/' . $hub;
				$file  = $dir . '/' . $slug . '.md';

				$previous_hash = null;
				if ( is_file( $file ) ) {
					$previous_hash = self::extract_hash_from_frontmatter( (string) file_get_contents( $file ) );
					if ( $previous_hash === $hash ) {
						return [
							'ok'              => true,
							'content_changed' => false,
							'status'          => 'unchanged',
							'file_path'       => self::relative( $file ),
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

				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}

				$written = file_put_contents( $file, $frontmatter ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				if ( false === $written ) {
					return $this->error( 'write_failed', sprintf( 'Could not write %s', self::relative( $file ) ), [ 'status' => 500 ] );
				}

				// Append change log (H.3).
				self::append_change_log( $url, $hub, $slug, $hash, $previous_hash, $fetched_at );

				return [
					'ok'              => true,
					'content_changed' => true,
					'status'          => $previous_hash === null ? 'created' : 'updated',
					'file_path'       => self::relative( $file ),
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

	private static function knowledge_dir(): string {
		return dirname( __DIR__, 3 ) . '/../docs/knowledge/elementor';
	}

	private static function relative( string $path ): string {
		$root = realpath( dirname( __DIR__, 3 ) . '/..' );
		if ( $root === false ) {
			return $path;
		}
		$norm = str_replace( '\\', '/', $path );
		$root = str_replace( '\\', '/', $root );
		if ( str_starts_with( $norm, $root . '/' ) ) {
			return substr( $norm, strlen( $root ) + 1 );
		}
		return $norm;
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
		$slug = trim( $slug, '-' );
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
		$log_path = self::knowledge_dir() . '/_change_log.md';
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
