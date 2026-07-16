<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Orchestrates the full DesignSpec → Elementor V3 write pipeline:
 *
 * 1. Backup::snapshot_post() — MUST succeed before any mutation.
 * 2. Validator::validate()   — rejects invalid specs.
 * 3. Renderer::render()      — converts spec to Elementor element array.
 * 4. Writes _elementor_data, _elementor_edit_mode, _elementor_version.
 * 5. Structural readback; rollback on empty/invalid tree when transactional.
 * 6. Clears Elementor file cache.
 * 7. Writes audit log entry.
 */
final class ElementorWriter {

	/**
	 * Ability name used in audit log entries.
	 */
	public const ABILITY = 'stonewright/elementor-v3-write-spec';

	/**
	 * @param int                              $post_id     WordPress post ID to write to.
	 * @param array<string, mixed>             $spec        Raw DesignSpec array (not yet validated).
	 * @param array<int, array<string, mixed>> $diagnostics Diagnostics array populated with unsupported-node info.
	 * @return bool|\WP_Error  True on success; WP_Error on backup failure, validation failure, or encode failure.
	 */
	public static function write( int $post_id, array $spec, array &$diagnostics = [] ): bool|\WP_Error {
		$result = self::write_transactional( $post_id, $spec, $diagnostics, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	/**
	 * Full-tree Elementor write with snapshot + readback + optional rollback.
	 *
	 * Blueprints use this path (full DesignSpec render) rather than BatchMutate
	 * operations; the safety contract matches ElementorTransactionRunner.
	 *
	 * @param int                              $post_id
	 * @param array<string, mixed>             $spec
	 * @param array<int, array<string, mixed>> $diagnostics
	 * @return array{ok: bool, snapshot_id: string, element_count: int, rolled_back: bool}|\WP_Error
	 */
	public static function write_transactional( int $post_id, array $spec, array &$diagnostics = [], bool $rollback_on_error = true ) {
		// Step 1: snapshot before any mutation.
		$snapshot_id = Backup::snapshot_post( $post_id );
		if ( '' === $snapshot_id ) {
			return new \WP_Error(
				'stonewright_backup_failed',
				sprintf( 'Backup::snapshot_post failed for post %d. Write aborted.', $post_id )
			);
		}

		// Step 2: validate spec.
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Step 3: render.
		$element_array = Renderer::render( $validated, $diagnostics );
		if ( ! is_array( $element_array ) || [] === $element_array ) {
			return new \WP_Error(
				'stonewright_elementor_render_empty',
				__( 'Elementor renderer produced an empty tree for this DesignSpec.', 'stonewright' ),
				[ 'status' => 500, 'snapshot_id' => $snapshot_id ]
			);
		}

		// Step 4: encode and persist.
		$json = wp_json_encode( $element_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return new \WP_Error(
				'stonewright_json_encode_failed',
				'Failed to JSON-encode the rendered Elementor element array.'
			);
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );

		// Step 5a: set edit mode and version.
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '3.0.0';
		update_post_meta( $post_id, '_elementor_version', $elementor_version );

		// Step 5b: structural readback (transaction contract).
		$read_tree = ElementorData::read( $post_id );
		$flat      = ElementorData::flatten( $read_tree );
		if ( [] === $read_tree || [] === $flat ) {
			$restored = false;
			if ( $rollback_on_error ) {
				$restored = Backup::restore( $post_id, $snapshot_id );
			}
			return new \WP_Error(
				'stonewright_transaction_readback_failed',
				__( 'Elementor write readback failed: empty tree after persist.', 'stonewright' ),
				[
					'status'      => 500,
					'snapshot_id' => $snapshot_id,
					'rolled_back' => $rollback_on_error,
					'restored'    => $restored,
				]
			);
		}

		// Step 6: clear Elementor file cache.
		if ( class_exists( '\\Elementor\\Plugin' ) ) {
			try {
				$instance = \Elementor\Plugin::$instance;
				if ( isset( $instance->files_manager ) ) {
					$instance->files_manager->clear_cache();
				}
			} catch ( \Throwable $e ) {
				// Cache layer best-effort; ignore if unavailable in tests or non-standard builds.
			}
		}

		// Step 7: audit log.
		$spec_sha8 = substr( sha1( (string) wp_json_encode( $validated ) ), 0, 8 );
		self::audit( $post_id, $spec_sha8 );

		return [
			'ok'            => true,
			'snapshot_id'   => $snapshot_id,
			'element_count' => count( $flat ),
			'rolled_back'   => false,
		];
	}

	/**
	 * Write audit log entry via AuditLog if available; fall back to error_log.
	 */
	private static function audit( int $post_id, string $spec_sha8 ): void {
		$args = [
			'post_id'   => $post_id,
			'spec_sha8' => $spec_sha8,
		];

		if ( class_exists( AuditLog::class ) ) {
			try {
				AuditLog::record( self::ABILITY, $args );
				return;
			} catch ( \Throwable $e ) {
				// fall through to error_log.
			}
		}

		error_log(
			sprintf(
				'[stonewright] ability=%s post_id=%d spec_sha8=%s',
				self::ABILITY,
				$post_id,
				$spec_sha8
			)
		);
	}
}
