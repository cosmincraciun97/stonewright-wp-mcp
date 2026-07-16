<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\DesignSpec\Validator;
use Stonewright\WpMcp\Security\AuditLog;
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
		// Preserve historical error code for missing posts (tests + clients).
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'stonewright_backup_failed',
				sprintf( 'Backup::snapshot_post failed for post %d. Write aborted.', $post_id )
			);
		}

		// Validate first (hard rule).
		$validated = Validator::validate( $spec );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		// Render DesignSpec → Elementor tree.
		$element_array = Renderer::render( $validated, $diagnostics );
		if ( ! is_array( $element_array ) || [] === $element_array ) {
			return new \WP_Error(
				'stonewright_elementor_render_empty',
				__( 'Elementor renderer produced an empty tree for this DesignSpec.', 'stonewright' ),
				[ 'status' => 500 ]
			);
		}

		$flat_preview = ElementorData::flatten( $element_array );
		$expected     = [
			'min_elements' => max( 1, (int) ceil( count( $flat_preview ) * 0.5 ) ),
		];

		// Transaction path: ElementorTransactionRunner (replace_tree / full_tree).
		$txn = ElementorTransactionRunner::run(
			$post_id,
			[
				'operations'        => [
					[
						'action' => 'replace_tree',
						'tree'   => $element_array,
					],
				],
				'stop_on_error'     => true,
				'rollback_on_error' => $rollback_on_error,
				'expected_readback' => $expected,
			],
			false
		);
		if ( is_wp_error( $txn ) ) {
			return $txn;
		}

		// Ensure edit mode / version meta even when ElementorData::write already set them.
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		$elementor_version = defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : '3.0.0';
		update_post_meta( $post_id, '_elementor_version', $elementor_version );

		$spec_sha8 = substr( sha1( (string) wp_json_encode( $validated ) ), 0, 8 );
		self::audit( $post_id, $spec_sha8 );

		return [
			'ok'            => true,
			'snapshot_id'   => (string) ( $txn['snapshot_id'] ?? '' ),
			'element_count' => (int) ( $txn['element_count'] ?? count( $flat_preview ) ),
			'rolled_back'   => false,
			'transaction'   => [
				'mode'        => (string) ( $txn['mode'] ?? 'full_tree' ),
				'before_hash' => (string) ( $txn['before_hash'] ?? '' ),
				'after_hash'  => (string) ( $txn['after_hash'] ?? '' ),
			],
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
