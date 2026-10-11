<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Rows of a kit list setting (custom_colors, custom_typography) keyed by their `_id`.
 *
 * Elementor addresses a kit row by `_id`, so a write that sends a row for an id the kit already has changes that
 * row; it never adds a second row with the same id.
 */
final class KitRows {

	/**
	 * The rows a kit list holds after a write.
	 *
	 * `replace` stores exactly the rows the call sent. `merge` changes the stored row of each id the call sent, in
	 * place and field by field (a field the call did not send keeps its value), appends the rows of new ids, and
	 * leaves every other row alone. Rows that share an id collapse into one: the call's own rows, and stored rows
	 * of an id the call sent. The last value sent for an id wins.
	 *
	 * @param list<array<string, mixed>> $stored   Rows now in the kit.
	 * @param list<array<string, mixed>> $incoming Rows of the call; each carries `_id` and only the fields the call sent.
	 * @return list<array<string, mixed>>
	 */
	public static function merge( array $stored, array $incoming, string $mode ): array {
		$sent = [];
		foreach ( $incoming as $row ) {
			$id          = (string) ( $row['_id'] ?? '' );
			$sent[ $id ] = array_merge( $sent[ $id ] ?? [], $row );
		}

		if ( 'replace' === $mode ) {
			return array_values( array_map( [ self::class, 'new_row' ], $sent ) );
		}

		$rows = [];
		$seen = [];
		foreach ( $stored as $row ) {
			$id = is_array( $row ) ? (string) ( $row['_id'] ?? '' ) : '';
			if ( ! isset( $sent[ $id ] ) ) {
				$rows[] = $row;
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$rows[]      = array_merge( $row, $sent[ $id ] );
		}
		foreach ( $sent as $id => $row ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$rows[] = self::new_row( $row );
			}
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function new_row( array $row ): array {
		return array_merge( [ '_id' => (string) ( $row['_id'] ?? '' ), 'title' => '' ], $row );
	}
}
