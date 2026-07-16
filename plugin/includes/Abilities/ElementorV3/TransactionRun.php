<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Abilities\ElementorV3;

use Stonewright\WpMcp\Abilities\AbilityKernel;
use Stonewright\WpMcp\Abilities\Common\ConfirmationGuard;
use Stonewright\WpMcp\Elementor\ElementorTransactionRunner;
use Stonewright\WpMcp\Elementor\TransactionEnvelope;
use Stonewright\WpMcp\Security\Permissions;

/**
 * Run an Elementor V3 mutation transaction with snapshot + readback + rollback.
 *
 * @stonewright-status stable
 */
final class TransactionRun extends AbilityKernel {
	use ConfirmationGuard;

	public function name(): string {
		return 'stonewright/elementor-v3-transaction-run';
	}

	public function label(): string {
		return __( 'Run Elementor V3 transaction', 'stonewright' );
	}

	public function description(): string {
		return __( 'Applies an Elementor V3 transaction envelope: precondition hash, pre-write snapshot, batch operations, structural readback, and optional rollback on failure.', 'stonewright' );
	}

	public function category(): string {
		return 'elementor';
	}

	public function input_schema(): array {
		$envelope = TransactionEnvelope::schema_fragment();
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'post_id', 'operations' ],
			'properties'           => array_merge(
				[
					'post_id'            => [ 'type' => 'integer', 'minimum' => 1 ],
					'dry_run'            => [ 'type' => 'boolean', 'default' => false ],
					'confirmation_token' => [ 'type' => 'string' ],
					// Allow envelope nested under "envelope" or flattened at root.
					'envelope'           => $envelope,
				],
				$envelope['properties']
			),
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'ok'            => [ 'type' => 'boolean' ],
				'post_id'       => [ 'type' => 'integer' ],
				'dry_run'       => [ 'type' => 'boolean' ],
				'snapshot_id'   => [ 'type' => 'string' ],
				'before_hash'   => [ 'type' => 'string' ],
				'after_hash'    => [ 'type' => 'string' ],
				'readback_hash' => [ 'type' => 'string' ],
				'element_count' => [ 'type' => 'integer' ],
				'rolled_back'   => [ 'type' => 'boolean' ],
				'batch'         => [ 'type' => 'object' ],
				'envelope'      => [ 'type' => 'object' ],
			],
		];
	}

	public function permission_callback( array $args ): bool|\WP_Error {
		$id = (int) ( $args['post_id'] ?? 0 );
		return Permissions::edit_post( $id );
	}

	public function execute( array $args ): array|\WP_Error {
		return $this->audit(
			$args,
			function ( array $args ) {
				$gate = $this->confirmation_token_error( $args, $args );
				if ( null !== $gate ) {
					return $gate;
				}

				$post_id = (int) ( $args['post_id'] ?? 0 );
				$dry_run = ! empty( $args['dry_run'] );
				$raw     = isset( $args['envelope'] ) && is_array( $args['envelope'] )
					? $args['envelope']
					: $args;

				return ElementorTransactionRunner::run( $post_id, $raw, $dry_run );
			}
		);
	}
}
