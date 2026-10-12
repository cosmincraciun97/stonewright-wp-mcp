<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\CssTargetResolver;
use Stonewright\WpMcp\Elementor\PageCachePurger;
use Stonewright\WpMcp\Elementor\Schema\CssValueGuard;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Regenerates one Elementor post or loop CSS file inside a guarded transaction.
 *
 * @stonewright-status stable
 */
final class CssRegenerate extends AbilityKernel {

	private const PROTECTED_DELIVERY_REPAIR_HEAD = 'The CSS file was written and verified on disk, and the page stylesheet version (css_version, the ?ver= of the page link) changed. Do not rebuild or re-save the layout because of this result. ';

	private const UNCHECKED_DELIVERY_REPAIR_HEAD = 'The CSS file was written and verified on disk, and the page stylesheet version changed, but public delivery of the file could not be checked. Do not rebuild the layout. ';

	private const DELIVERY_REPAIR_TAIL = ' Then check the stylesheet URL in a logged-out browser: what sits in front of the uploads folder is a hosting or plugin setting, not an Elementor change. Call this ability again only once, and only if the page still looks unstyled.';

	public function name(): string {
		return 'stonewright/elementor-css-regenerate';
	}

	public function label(): string {
		return __( 'Regenerate Elementor CSS', 'stonewright' );
	}

	public function description(): string {
		return __( 'Regenerates one Elementor post or loop CSS file through the official Elementor update API inside a guarded asset transaction, advances the stylesheet version (the ?ver= of the page link), purges the cached page HTML of that one post in the page cache plugins it finds (cache_purge reports which ran), then returns hashed health evidence. A page whose styles are empty has no CSS file; the result then reports css_file_status not_produced instead of a file check. When an anonymous request to the file is redirected away, refused, answered by another page or a 5xx, or its URL is on another origin that is never requested, the write still counts: delivery_status says what was found, delivery_cause names the cause, a warning gives the next step, and the layout must not be rebuilt because of it. Call after an Elementor apply and before post-write-verify.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id' ],
			'properties'           => [
				'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
				'asset_kind'         => [
					'type'    => 'string',
					'enum'    => [ 'auto', 'post', 'loop' ],
					'default' => 'auto',
				],
				'confirmation_token' => [ 'type' => 'string' ],
				'write_receipt'      => [ 'type' => 'object' ],
			],
		];
	}

	public function output_schema(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => true,
			'properties'           => [
				'ok'                      => [ 'type' => 'boolean' ],
				'post_id'                 => [ 'type' => 'integer' ],
				'asset_kind'              => [ 'type' => 'string' ],
				'filename'                => [ 'type' => 'string' ],
				'path_sha256'             => [ 'type' => 'string' ],
				'url_sha256'              => [ 'type' => 'string' ],
				'effect_verified'              => [ 'type' => 'boolean' ],
				'generation_status'            => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked' ],
				],
				'delivery_status'              => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked', 'not_applicable' ],
				],
				'css_file_status'              => [
					'type' => 'string',
					'enum' => [ 'present', 'not_produced' ],
				],
				'css_file_reason'              => [
					'type' => 'string',
					'enum' => [ 'empty_css' ],
				],
				'frontend_verification_status' => [
					'type' => 'string',
					'enum' => [ 'verified', 'blocked', 'failed', 'not_checked' ],
				],
				'root_error_code'              => [ 'type' => 'string' ],
				'delivery_cause'               => [
					'type'        => 'string',
					'description' => 'Why public delivery of the written stylesheet was not verified: login_redirect, login_page, http_401, http_403, other_page_html, redirect_to_other_page, http_5xx, no_response, http_<4xx>, not_css or other_origin_not_probed. Absent when delivery was verified.',
				],
				'message'                      => [ 'type' => 'string', 'description' => 'Present only when the call did not complete: the human reason.' ],
				'failed_check'                 => [ 'type' => 'string' ],
				'retryable'                    => [ 'type' => 'boolean' ],
				'css_version'                  => [ 'type' => 'integer' ],
				'css_version_before'           => [ 'type' => 'integer' ],
				'css_version_changed'          => [ 'type' => 'boolean' ],
				'warnings'                     => [ 'type' => 'array' ],
				'cache_purge'                  => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'properties'           => [
						'ran'            => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						'skipped_reason' => [ 'type' => 'string' ],
						'failed'         => [ 'type' => 'array' ],
						'skipped'        => [ 'type' => 'object' ],
					],
					'required'             => [ 'ran' ],
				],
				'repair'                       => [ 'type' => 'string' ],
				'before_manifest_sha256'       => [ 'type' => 'string' ],
				'after_manifest_sha256'   => [ 'type' => 'string' ],
				'probes'                  => [ 'type' => 'array' ],
				'backup'                  => [ 'type' => 'object' ],
				'rollback_status'         => [ 'type' => 'string' ],
				'write_receipt'           => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'post_id', 'asset_kind', 'filename', 'effect_verified', 'generation_status', 'delivery_status', 'frontend_verification_status' ],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		self::trace( 'permission' );
		return Permissions::edit_post( (int) ( $args['post_id'] ?? 0 ) );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit_write(
			$args,
			function ( array $args ): array|\WP_Error {
				self::trace( 'confirmation' );
				$post_id    = (int) ( $args['post_id'] ?? 0 );
				$asset_kind = sanitize_key( (string) ( $args['asset_kind'] ?? 'auto' ) );
				if ( '' === $asset_kind ) {
					$asset_kind = 'auto';
				}

				if ( ! get_post( $post_id ) ) {
					return $this->error( 'not_found', __( 'Post not found.', 'stonewright' ), [ 'status' => 404 ] );
				}

				// Elementor prints stored colour, typography and unit values into the
				// stylesheet unescaped, so a page that stores a value carrying CSS
				// control characters is never handed to the generator.
				$unsafe = CssValueGuard::unsafe_values_in_tree( ElementorData::read( $post_id ) );
				$page   = get_post_meta( $post_id, '_elementor_page_settings', true );
				if ( is_array( $page ) ) {
					$unsafe = array_merge( $unsafe, CssValueGuard::unsafe_values_in_settings( $page, 'page_settings' ) );
				}
				if ( [] !== $unsafe ) {
					$paths = array_slice( array_column( $unsafe, 'path' ), 0, 10 );
					return $this->error(
						'elementor_css_unsafe_value',
						sprintf(
							/* translators: %s: setting path */
							__( 'CSS regeneration refused: %s stores a value with CSS control characters.', 'stonewright' ),
							(string) $paths[0]
						),
						[
							'status'    => 422,
							'retryable' => true,
							'paths'     => $paths,
							'count'     => count( $unsafe ),
							'repair'    => 'Replace the listed settings with a real colour, font family or numeric value through elementor-v3-update-element, then regenerate again.',
						]
					);
				}

				$resolved = ( new CssTargetResolver() )->resolve( $post_id, $asset_kind );
				if ( $resolved instanceof \WP_Error ) {
					return $resolved;
				}

				self::trace( 'backup' );
				$snapshot_id = Backup::snapshot_post( $post_id );
				if ( '' === $snapshot_id ) {
					return $this->error(
						'backup_failed',
						__( 'Backup snapshot failed; CSS regeneration aborted.', 'stonewright' ),
						[ 'status' => 500 ]
					);
				}

				self::trace( 'post_lock' );
				$owner = 'css-' . substr( hash( 'sha256', wp_generate_uuid4() . '|' . $post_id ), 0, 32 );
				$lease = PostWriteLock::acquire( $post_id, $owner, 120 );
				if ( $lease instanceof \WP_Error ) {
					return $lease;
				}

				try {
					self::trace( 'css_lease' );
					$transaction = CssAssetTransaction::run(
						$resolved,
						static function () use ( $resolved ): array {
							self::trace( 'update_css' );
							return CssRegenerator::regenerate( $resolved );
						}
					);
					if ( $transaction instanceof \WP_Error ) {
						return $transaction;
					}

					self::trace( 'health' );
					$operation = is_array( $transaction['operation_result'] ?? null ) ? $transaction['operation_result'] : [];
					$evidence  = is_array( $transaction['css_evidence'] ?? null ) ? $transaction['css_evidence'] : [];
					$probes    = is_array( $evidence['protected_probes_after'] ?? null ) ? $evidence['protected_probes_after'] : [];
					$generation = self::status_token( $evidence['generation_status'] ?? '' );
					$delivery   = self::status_token( $evidence['delivery_status'] ?? '' );
					$file_status = 'not_produced' === ( $evidence['css_file_status'] ?? '' ) ? 'not_produced' : 'present';
					if ( 'not_checked' === $generation ) {
						$generation = (bool) ( $operation['ok'] ?? false ) ? 'verified' : 'failed';
					}
					$root       = sanitize_key( (string) ( $evidence['root_error_code'] ?? '' ) );
					$failed     = sanitize_key( (string) ( $evidence['failed_check'] ?? '' ) );
					$cause      = sanitize_key( (string) ( $evidence['delivery_cause'] ?? '' ) );
					// The file is written and verified on disk, and the page version has moved on. What an anonymous
					// request to its URL got (a login redirect, 401, another page, a 5xx, a CDN origin that is never
					// requested) says where the file is not shown to the public; it never turns the write into a failure.
					$delivery_unverified = 'present' === $file_status && in_array( $delivery, [ 'blocked', 'not_checked', 'failed' ], true );
					$complete            = 'verified' === $generation;
					if ( ! $complete ) {
						if ( '' === $root ) {
							$root = 'stonewright_elementor_css_empty_target';
						}
						if ( '' === $failed ) {
							$failed = 'generation';
						}
					}
					$receipt   = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] )
						? self::sanitize_receipt( $args['write_receipt'] )
						: [];
					if ( [] !== $receipt ) {
						$receipt['verification_status'] = $complete ? 'css_regenerated' : 'failed';
						if ( ! $complete ) {
							$receipt['root_error_code'] = $root;
						}
					}
					$css_version = self::css_version_evidence( $operation );

					self::trace( 'audit' );
					$result = [
						'ok'                           => $complete,
						'post_id'                      => $post_id,
						'asset_kind'                   => $resolved->kind(),
						'filename'                     => $resolved->filename(),
						'path_sha256'                  => (string) ( $operation['path_sha256'] ?? hash( 'sha256', $resolved->path() ) ),
						'url_sha256'                   => (string) ( $operation['url_sha256'] ?? hash( 'sha256', $resolved->url() ) ),
						'effect_verified'              => $complete,
						'generation_status'            => $generation,
						'delivery_status'              => $delivery,
						'css_file_status'              => $file_status,
						'frontend_verification_status' => 'not_checked',
						'before_manifest_sha256'       => (string) ( $evidence['before_manifest_sha256'] ?? '' ),
						'after_manifest_sha256'        => (string) ( $evidence['after_manifest_sha256'] ?? '' ),
						'probes'                       => $probes,
						'backup'                       => [ 'snapshot_id' => $snapshot_id ],
						'rollback_status'              => (string) ( $evidence['rollback_status'] ?? 'not_needed' ),
						'write_receipt'                => $receipt,
						'retryable'                    => false,
					];
					if ( 'not_produced' === $file_status ) {
						$result['css_file_reason'] = (string) ( $evidence['css_file_reason'] ?? 'empty_css' );
					}
					$result = array_merge( $result, $css_version );
					$purge  = self::purge_page_caches( $post_id, $operation, $evidence );
					$result['cache_purge'] = $purge;
					if ( [] !== ( $purge['failed'] ?? [] ) ) {
						$result['warnings'] = self::purge_failure_warnings( $purge['failed'] );
					}
					if ( $delivery_unverified ) {
						$status = self::probe_status( $probes, $resolved->filename() );
						$result['delivery_cause'] = $cause;
						$result['warnings']       = array_merge(
							[
								[
									'code'    => 'blocked' === $delivery ? 'stonewright_elementor_css_delivery_protected' : 'stonewright_elementor_css_delivery_unverified',
									'cause'   => $cause,
									'message' => self::delivery_sentence( $cause, $status ),
								],
							],
							$result['warnings'] ?? []
						);
						$purged           = self::purged_sentence( $purge );
						$result['repair'] = ( 'blocked' === $delivery ? self::PROTECTED_DELIVERY_REPAIR_HEAD : self::UNCHECKED_DELIVERY_REPAIR_HEAD )
							. self::delivery_next_step( $cause )
							. ' ' . $purged
							. ( '' === $purged ? 'If the page still looks unstyled, purge the host or page cache (and the CDN, if one is in use) by hand.' : 'If the page still looks unstyled, purge any other host, CDN or page cache by hand.' )
							. self::DELIVERY_REPAIR_TAIL;
					} elseif ( ! $complete ) {
						$result['root_error_code'] = $root;
						$result['failed_check']    = $failed;
						$result['message']         = sprintf(
							/* translators: 1: failed check, 2: root error code */
							__( 'CSS regeneration did not complete (%1$s check failed: %2$s). The previous files were kept or restored; read rollback_status.', 'stonewright' ),
							'' !== $failed ? $failed : 'unknown',
							$root
						);
					}
					return $result;
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
	}

	/**
	 * HTTP status the anonymous probe of the target stylesheet got, or 0 when it was not requested.
	 *
	 * @param array<int,mixed> $probes Probe rows of the transaction.
	 */
	private static function probe_status( array $probes, string $filename ): int {
		foreach ( $probes as $probe ) {
			if ( is_array( $probe ) && ( $probe['asset'] ?? null ) === $filename && isset( $probe['status'] ) && is_numeric( $probe['status'] ) ) {
				return (int) $probe['status'];
			}
		}
		return 0;
	}

	/**
	 * What the anonymous check of the stylesheet URL found, from the evidence alone (status, kind of answer).
	 * It never names a plugin: it cannot know which one answered.
	 */
	private static function delivery_sentence( string $cause, int $status ): string {
		$head = 'The stylesheet was written and verified on disk and its page version changed, but ';
		return match ( $cause ) {
			'login_redirect'         => $head . 'an anonymous request to its URL was redirected to the login page, so a login or membership rule sits in front of the uploads folder.',
			'login_page'             => $head . 'an anonymous request to its URL was answered with a login page, so a login or membership rule sits in front of the uploads folder.',
			'http_401'               => $head . 'an anonymous request to its URL was answered with HTTP 401, so authentication (such as basic auth on the host) sits in front of the uploads folder.',
			'http_403'               => $head . 'an anonymous request to its URL was answered with HTTP 403, so a firewall, hotlink or security rule refuses the file.',
			'other_page_html'        => $head . 'an anonymous request to its URL was answered with an HTML page instead of CSS (a maintenance or coming-soon page, a security challenge, or a rewrite rule).',
			'redirect_to_other_page' => $head . 'an anonymous request to its URL was redirected to a page that is not the stylesheet.',
			'http_5xx'               => $head . sprintf( 'an anonymous request to its URL was answered with HTTP %d (a maintenance mode, or the host is busy), so public delivery was not verified.', $status > 0 ? $status : 503 ),
			'no_response'            => $head . 'an anonymous request to its URL got no answer (a timeout or a connection error), so public delivery was not verified.',
			'other_origin_not_probed' => $head . 'its URL is on another origin than the site (a CDN or an offload plugin), which Stonewright never requests, so public delivery was not checked.',
			'not_css'                => $head . 'an anonymous request to its URL was answered with something that is not CSS.',
			default                  => 0 !== $status && str_starts_with( $cause, 'http_' )
				? $head . sprintf( 'an anonymous request to its URL was answered with HTTP %d, so the file is not served at that address.', $status )
				: $head . 'an anonymous request to its URL does not end in the CSS file, so public delivery was not verified.',
		};
	}

	/** The next step for a cause of unverified delivery. */
	private static function delivery_next_step( string $cause ): string {
		return match ( $cause ) {
			'login_redirect', 'login_page' => 'Allow anonymous requests to wp-content/uploads/elementor/css in the login or membership plugin (or ignore this when visitors see the page styled).',
			'http_401'                     => 'Exclude wp-content/uploads/elementor/css from the host-level authentication (or ignore it when it only guards a staging copy).',
			'http_403'                     => 'Allow anonymous GET requests to wp-content/uploads/elementor/css in the firewall or security plugin.',
			'other_page_html'              => 'If a maintenance or coming-soon mode is on, the page is not public yet; otherwise exclude wp-content/uploads/elementor/css from the rewrite or challenge rule.',
			'redirect_to_other_page'       => 'Remove the redirect in front of wp-content/uploads/elementor/css.',
			'http_5xx', 'no_response'      => 'Try the stylesheet URL again in a logged-out browser once the host answers normally.',
			'other_origin_not_probed'      => 'Purge the CDN or offload cache of this stylesheet by hand.',
			default                        => 'Make the stylesheet URL answer HTTP 200 text/css to a logged-out request.',
		};
	}

	/**
	 * Purges the cached page HTML of this post once the CSS file is written and its version moved.
	 * Never fails the write: every error becomes a report entry.
	 *
	 * @param array<string,mixed> $operation
	 * @param array<string,mixed> $evidence
	 * @return array<string,mixed>
	 */
	private static function purge_page_caches( int $post_id, array $operation, array $evidence ): array {
		if ( ! (bool) ( $operation['ok'] ?? false ) || 'not_needed' !== (string) ( $evidence['rollback_status'] ?? 'not_needed' ) ) {
			return [ 'ran' => [], 'skipped_reason' => 'css_not_regenerated' ];
		}
		if ( true !== ( $operation['css_version_changed'] ?? null ) ) {
			return [ 'ran' => [], 'skipped_reason' => 'css_version_not_changed' ];
		}
		try {
			return PageCachePurger::purge( $post_id );
		} catch ( \Throwable $error ) {
			return [
				'ran'    => [],
				'failed' => [ [ 'purger' => 'page_cache', 'error_class' => get_class( $error ) ] ],
			];
		}
	}

	/**
	 * @param array<int,mixed> $failed
	 * @return list<array{code:string,message:string}>
	 */
	private static function purge_failure_warnings( array $failed ): array {
		$warnings = [];
		foreach ( $failed as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$id         = sanitize_key( (string) ( $entry['purger'] ?? '' ) );
			$warnings[] = [
				'code'    => 'stonewright_css_cache_purge_failed',
				'message' => sprintf(
					'The page cache purge of %s failed (%s). The CSS file was written and the page version changed; purge that cache by hand.',
					PageCachePurger::label( $id ),
					preg_replace( '/[^A-Za-z0-9_\\\\]/', '', (string) ( $entry['error_class'] ?? '' ) )
				),
			];
		}
		return $warnings;
	}

	/**
	 * The repair sentence that says which page caches Stonewright already purged, or an empty string
	 * when none was purged.
	 *
	 * @param array<string,mixed> $purge
	 */
	private static function purged_sentence( array $purge ): string {
		if ( ! PageCachePurger::page_cache_ran( $purge ) ) {
			return '';
		}
		$names = [];
		foreach ( (array) ( $purge['ran'] ?? [] ) as $id ) {
			if ( PageCachePurger::CORE !== $id ) {
				$names[] = PageCachePurger::label( (string) $id );
			}
		}
		return 'Stonewright already purged the cached page of this post in ' . implode( ', ', $names ) . '. ';
	}

	/**
	 * @param array<string,mixed> $operation
	 * @return array<string,mixed>
	 */
	private static function css_version_evidence( array $operation ): array {
		if ( ! isset( $operation['css_version'] ) || ! is_numeric( $operation['css_version'] ) ) {
			return [];
		}
		return [
			'css_version'         => max( 0, (int) $operation['css_version'] ),
			'css_version_before'  => max( 0, (int) ( $operation['css_version_before'] ?? 0 ) ),
			'css_version_changed' => (bool) ( $operation['css_version_changed'] ?? false ),
		];
	}

	/**
	 * @param array<string,mixed> $receipt
	 * @return array<string,mixed>
	 */
	private static function sanitize_receipt( array $receipt ): array {
		$allowed = [
			'transaction_id',
			'change_set_id',
			'post_id',
			'architecture',
			'snapshot_id',
			'before_hash',
			'after_hash',
			'verification_status',
			'rollback_status',
			'root_error_code',
		];
		$out = [];
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $receipt ) || ! is_scalar( $receipt[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = mb_substr( sanitize_text_field( (string) $receipt[ $key ] ), 0, 255 );
		}
		return $out;
	}

	private static function status_token( string $status ): string {
		$status = sanitize_key( $status );
		return in_array( $status, [ 'verified', 'blocked', 'failed', 'not_checked', 'not_applicable' ], true ) ? $status : 'not_checked';
	}

	private static function trace( string $event ): void {
		if ( ! isset( $GLOBALS['stonewright_test_css_regenerate_events'] ) || ! is_array( $GLOBALS['stonewright_test_css_regenerate_events'] ) ) {
			return;
		}
		$GLOBALS['stonewright_test_css_regenerate_events'][] = $event;
	}
}
