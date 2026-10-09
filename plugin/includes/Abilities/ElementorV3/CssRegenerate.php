<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\CssTargetResolver;
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

	public function name(): string {
		return 'stonewright/elementor-css-regenerate';
	}

	public function label(): string {
		return __( 'Regenerate Elementor CSS', 'stonewright' );
	}

	public function description(): string {
		return __( 'Regenerates one Elementor post or loop CSS file through the official update_file API inside a guarded asset transaction, then returns hashed health evidence. A page whose styles are empty has no CSS file; the result then reports css_file_status not_produced instead of a file check. Call after an Elementor apply and before post-write-verify.', 'stonewright' );
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
				'failed_check'                 => [ 'type' => 'string' ],
				'retryable'                    => [ 'type' => 'boolean' ],
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
							self::trace( 'update_file' );
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
					$no_file_ok = 'not_produced' === $file_status && 'not_applicable' === $delivery;
					$complete   = 'verified' === $generation && ( 'verified' === $delivery || $no_file_ok );
					$root     = sanitize_key( (string) ( $evidence['root_error_code'] ?? '' ) );
					$failed   = sanitize_key( (string) ( $evidence['failed_check'] ?? '' ) );
					if ( ! $complete ) {
						if ( '' === $root ) {
							$root = 'blocked' === $delivery
								? 'stonewright_elementor_css_delivery_protected'
								: ( 'verified' !== $generation ? 'stonewright_elementor_css_empty_target' : 'stonewright_elementor_css_probe_failed' );
						}
						if ( '' === $failed ) {
							$failed = 'verified' !== $generation ? 'generation' : 'delivery';
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
					if ( ! $complete ) {
						$result['root_error_code'] = $root;
						$result['failed_check']    = $failed;
					}
					return $result;
				} finally {
					PostWriteLock::release( $post_id, $owner );
				}
			}
		);
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
