<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Elementor\CssAssetTransaction;
use Stonewright\WpMcp\Elementor\CssRegenerator;
use Stonewright\WpMcp\Elementor\CssTargetResolver;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Security\Permissions;

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
		return __( 'Regenerates one Elementor post or loop CSS file through the official update_file API inside a guarded asset transaction, then returns hashed health evidence. Call after an Elementor apply and before post-write-verify.', 'stonewright' );
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
				'effect_verified'         => [ 'type' => 'boolean' ],
				'before_manifest_sha256'  => [ 'type' => 'string' ],
				'after_manifest_sha256'   => [ 'type' => 'string' ],
				'probes'                  => [ 'type' => 'array' ],
				'backup'                  => [ 'type' => 'object' ],
				'rollback_status'         => [ 'type' => 'string' ],
				'write_receipt'           => [ 'type' => 'object' ],
			],
			'required'             => [ 'ok', 'post_id', 'asset_kind', 'filename', 'effect_verified' ],
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
					$ok        = (bool) ( $operation['ok'] ?? false ) && [] !== $probes;
					$receipt   = isset( $args['write_receipt'] ) && is_array( $args['write_receipt'] )
						? self::sanitize_receipt( $args['write_receipt'] )
						: [];
					if ( [] !== $receipt ) {
						$receipt['verification_status'] = $ok ? 'css_regenerated' : 'failed';
					}

					self::trace( 'audit' );
					return [
						'ok'                     => $ok,
						'post_id'                => $post_id,
						'asset_kind'             => $resolved->kind(),
						'filename'               => $resolved->filename(),
						'path_sha256'            => (string) ( $operation['path_sha256'] ?? hash( 'sha256', $resolved->path() ) ),
						'url_sha256'             => (string) ( $operation['url_sha256'] ?? hash( 'sha256', $resolved->url() ) ),
						'effect_verified'        => $ok,
						'before_manifest_sha256' => (string) ( $evidence['before_manifest_sha256'] ?? '' ),
						'after_manifest_sha256'  => (string) ( $evidence['after_manifest_sha256'] ?? '' ),
						'probes'                 => $probes,
						'backup'                 => [ 'snapshot_id' => $snapshot_id ],
						'rollback_status'        => (string) ( $evidence['rollback_status'] ?? 'not_needed' ),
						'write_receipt'          => $receipt,
					];
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

	private static function trace( string $event ): void {
		if ( ! isset( $GLOBALS['stonewright_test_css_regenerate_events'] ) || ! is_array( $GLOBALS['stonewright_test_css_regenerate_events'] ) ) {
			return;
		}
		$GLOBALS['stonewright_test_css_regenerate_events'][] = $event;
	}
}
