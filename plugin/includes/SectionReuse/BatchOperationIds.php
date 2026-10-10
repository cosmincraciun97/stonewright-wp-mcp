<?php
/**
 * The op_id check of a batch of write operations.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Later operations of a batch address the elements of an earlier one by its op_id (`@op_id.placeholder`,
 * `section_ref`). Two operations with the same op_id would make every such reference ambiguous, so a batch that
 * carries one is refused before any operation runs.
 */
final class BatchOperationIds {

	/**
	 * @param array<int|string, mixed> $operations The operations of one batch, in order.
	 */
	public static function duplicate( array $operations ): ?\WP_Error {
		$seen = [];
		foreach ( array_values( $operations ) as $index => $operation ) {
			$op_id = is_array( $operation ) && isset( $operation['op_id'] ) && is_string( $operation['op_id'] ) ? $operation['op_id'] : '';
			if ( '' !== $op_id ) {
				$seen[ $op_id ][] = $index;
			}
		}
		foreach ( $seen as $op_id => $indexes ) {
			if ( count( $indexes ) > 1 ) {
				return new \WP_Error(
					'stonewright_duplicate_op_id',
					sprintf( 'Operations %1$s of this batch share the op_id "%2$s"; references to it would be ambiguous. Give each operation its own op_id. Nothing was written.', implode( ' and ', array_map( 'strval', $indexes ) ), (string) $op_id ),
					[ 'status' => 400, 'op_id' => (string) $op_id, 'indexes' => $indexes, 'retryable' => true, 'write_blocked' => true ]
				);
			}
		}

		return null;
	}
}
