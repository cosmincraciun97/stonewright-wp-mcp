<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Transaction envelope for multi-step Elementor writes.
 *
 * Fields:
 * - precondition_hash: optional tree hash that must match before apply
 * - snapshot_id: filled by the runner after Backup::snapshot_post
 * - operations: batch mutate ops (add/update/move/remove)
 * - stop_on_error / rollback_on_error: failure policy
 * - expected_readback: optional structural expectations after write
 *
 * @phpstan-type Envelope array{
 *   precondition_hash: string,
 *   snapshot_id: string,
 *   operations: list<array<string, mixed>>,
 *   stop_on_error: bool,
 *   rollback_on_error: bool,
 *   expected_readback: array<string, mixed>
 * }
 */
final class TransactionEnvelope {

	/**
	 * @param array<string, mixed> $raw
	 * @return Envelope|\WP_Error
	 */
	public static function normalize( array $raw ) {
		$operations = $raw['operations'] ?? [];
		if ( ! is_array( $operations ) || [] === $operations ) {
			return new \WP_Error(
				'stonewright_transaction_invalid',
				__( 'Transaction envelope requires a non-empty operations array.', 'stonewright' ),
				[ 'status' => 400 ]
			);
		}

		$normalized_ops = [];
		foreach ( $operations as $index => $op ) {
			if ( ! is_array( $op ) ) {
				return new \WP_Error(
					'stonewright_transaction_invalid',
					sprintf(
						/* translators: %d: operation index */
						__( 'Transaction operation %d must be an object.', 'stonewright' ),
						(int) $index
					),
					[ 'status' => 400, 'index' => (int) $index ]
				);
			}
			$normalized_ops[] = $op;
		}

		$expected = isset( $raw['expected_readback'] ) && is_array( $raw['expected_readback'] )
			? $raw['expected_readback']
			: [];

		return [
			'precondition_hash' => isset( $raw['precondition_hash'] ) ? (string) $raw['precondition_hash'] : '',
			'snapshot_id'       => isset( $raw['snapshot_id'] ) ? (string) $raw['snapshot_id'] : '',
			'operations'        => array_values( $normalized_ops ),
			'stop_on_error'     => ! array_key_exists( 'stop_on_error', $raw ) || (bool) $raw['stop_on_error'],
			'rollback_on_error' => ! array_key_exists( 'rollback_on_error', $raw ) || (bool) $raw['rollback_on_error'],
			'expected_readback' => $expected,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function schema_fragment(): array {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'operations' ],
			'properties'           => [
				'precondition_hash' => [
					'type'        => 'string',
					'description' => 'Optional tree hash that must match before mutations apply.',
				],
				'snapshot_id'       => [
					'type'        => 'string',
					'description' => 'Filled by the runner after Backup::snapshot_post; ignore on input.',
				],
				'operations'        => [
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 200,
					'items'       => [ 'type' => 'object', 'additionalProperties' => true ],
					'description' => 'Elementor batch operations (add_container, add_widget, update_element, move_element, remove_element).',
				],
				'stop_on_error'     => [ 'type' => 'boolean', 'default' => true ],
				'rollback_on_error' => [
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Restore the pre-write snapshot when apply or readback fails.',
				],
				'expected_readback' => [
					'type'                 => 'object',
					'additionalProperties' => true,
					'description'          => 'Optional post-write expectations: tree_hash, min_elements, max_elements, contains_widget_types.',
					'properties'           => [
						'tree_hash'             => [ 'type' => 'string' ],
						'min_elements'          => [ 'type' => 'integer', 'minimum' => 0 ],
						'max_elements'          => [ 'type' => 'integer', 'minimum' => 0 ],
						'contains_widget_types' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
				],
			],
		];
	}
}
