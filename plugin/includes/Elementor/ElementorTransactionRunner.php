<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Abilities\ElementorV3\BatchMutate;
use Stonewright\WpMcp\Elementor\Write\TreeHasher;
use Stonewright\WpMcp\Security\Backup;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * Applies a TransactionEnvelope with snapshot + structural readback + optional rollback.
 */
final class ElementorTransactionRunner {

	/** @var callable|null fn(int $post_id): array */
	private static $read_override = null;

	/**
	 * Test hook: force a custom readback tree after write (simulates mismatch).
	 *
	 * @param callable|null $fn fn(int $post_id): array
	 */
	public static function set_read_override( ?callable $fn ): void {
		self::$read_override = $fn;
	}

	/**
	 * @param array<string, mixed> $raw_envelope
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function run( int $post_id, array $raw_envelope, bool $dry_run = false ) {
		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'stonewright_transaction_not_found',
				__( 'Post not found for Elementor transaction.', 'stonewright' ),
				[ 'status' => 404 ]
			);
		}

		$envelope = TransactionEnvelope::normalize( $raw_envelope );
		if ( $envelope instanceof \WP_Error ) {
			return $envelope;
		}

		$before_tree = ElementorData::read( $post_id );
		$before_hash = TreeHasher::hash( $before_tree );

		if ( '' !== $envelope['precondition_hash'] && ! hash_equals( $envelope['precondition_hash'], $before_hash ) ) {
			return new \WP_Error(
				'stonewright_transaction_precondition',
				__( 'Elementor transaction precondition_hash does not match the current tree.', 'stonewright' ),
				[
					'status'            => 409,
					'expected_hash'     => $envelope['precondition_hash'],
					'actual_hash'       => $before_hash,
				]
			);
		}

		$snapshot_id = '';
		if ( ! $dry_run ) {
			// Hard rule: snapshot before Elementor writes.
			$snapshot_id = Backup::snapshot_post( $post_id );
			if ( '' === $snapshot_id ) {
				return new \WP_Error(
					'stonewright_transaction_snapshot_failed',
					__( 'Could not create a pre-write snapshot for the Elementor transaction.', 'stonewright' ),
					[ 'status' => 500 ]
				);
			}
			$envelope['snapshot_id'] = $snapshot_id;
		}

		$batch = new BatchMutate();
		$result = $batch->execute(
			[
				'post_id'       => $post_id,
				'operations'    => $envelope['operations'],
				'stop_on_error' => $envelope['stop_on_error'],
				'dry_run'       => $dry_run,
			]
		);

		if ( $result instanceof \WP_Error ) {
			if ( ! $dry_run && $envelope['rollback_on_error'] && '' !== $snapshot_id ) {
				$restored = Backup::restore( $post_id, $snapshot_id );
				$result->add_data(
					array_merge(
						(array) $result->get_error_data(),
						[
							'rolled_back' => true,
							'restored'    => $restored,
							'snapshot_id' => $snapshot_id,
						]
					)
				);
			}
			return $result;
		}

		$read_tree = is_callable( self::$read_override )
			? (array) ( self::$read_override )( $post_id )
			: ElementorData::read( $post_id );
		$read_hash = TreeHasher::hash( $read_tree );
		$flat      = ElementorData::flatten( $read_tree );

		$readback_error = self::verify_expected_readback( $envelope['expected_readback'], $read_tree, $read_hash, $flat );
		if ( null !== $readback_error ) {
			$restored = false;
			if ( ! $dry_run && $envelope['rollback_on_error'] && '' !== $snapshot_id ) {
				$restored = Backup::restore( $post_id, $snapshot_id );
			}
			return new \WP_Error(
				'stonewright_transaction_readback_failed',
				$readback_error,
				[
					'status'      => 500,
					'snapshot_id' => $snapshot_id,
					'rolled_back' => ! $dry_run && $envelope['rollback_on_error'],
					'restored'    => $restored,
					'readback'    => [
						'tree_hash'     => $read_hash,
						'element_count' => count( $flat ),
					],
				]
			);
		}

		return [
			'ok'              => true,
			'post_id'         => $post_id,
			'dry_run'         => $dry_run,
			'snapshot_id'     => $snapshot_id,
			'before_hash'     => $before_hash,
			'after_hash'      => $read_hash,
			'readback_hash'   => $read_hash,
			'element_count'   => count( $flat ),
			'batch'           => $result,
			'envelope'        => [
				'stop_on_error'     => $envelope['stop_on_error'],
				'rollback_on_error' => $envelope['rollback_on_error'],
				'precondition_hash' => $envelope['precondition_hash'],
			],
			'rolled_back'     => false,
		];
	}

	/**
	 * @param array<string, mixed>               $expected
	 * @param array<int|string, mixed>           $tree
	 * @param array<int|string, array<string, mixed>|mixed> $flat
	 */
	private static function verify_expected_readback( array $expected, array $tree, string $read_hash, array $flat ): ?string {
		if ( [] === $expected ) {
			return null;
		}

		if ( isset( $expected['tree_hash'] ) && is_string( $expected['tree_hash'] ) && '' !== $expected['tree_hash'] ) {
			if ( ! hash_equals( $expected['tree_hash'], $read_hash ) ) {
				return __( 'Elementor transaction readback tree_hash did not match expected_readback.', 'stonewright' );
			}
		}

		$count = count( $flat );
		if ( isset( $expected['min_elements'] ) && $count < (int) $expected['min_elements'] ) {
			return sprintf(
				/* translators: 1: actual count, 2: minimum */
				__( 'Elementor transaction readback element count %1$d is below min_elements %2$d.', 'stonewright' ),
				$count,
				(int) $expected['min_elements']
			);
		}
		if ( isset( $expected['max_elements'] ) && $count > (int) $expected['max_elements'] ) {
			return sprintf(
				/* translators: 1: actual count, 2: maximum */
				__( 'Elementor transaction readback element count %1$d exceeds max_elements %2$d.', 'stonewright' ),
				$count,
				(int) $expected['max_elements']
			);
		}

		if ( isset( $expected['contains_widget_types'] ) && is_array( $expected['contains_widget_types'] ) ) {
			$types = [];
			foreach ( $flat as $el ) {
				if ( isset( $el['widgetType'] ) ) {
					$types[ (string) $el['widgetType'] ] = true;
				}
			}
			foreach ( $expected['contains_widget_types'] as $need ) {
				$need = (string) $need;
				if ( '' !== $need && ! isset( $types[ $need ] ) ) {
					return sprintf(
						/* translators: %s: widget type */
						__( 'Elementor transaction readback missing required widget type: %s', 'stonewright' ),
						$need
					);
				}
			}
		}

		unset( $tree );
		return null;
	}
}
