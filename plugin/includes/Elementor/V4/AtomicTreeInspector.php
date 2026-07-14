<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Recursively inventories mixed Elementor V3/V4 trees without rewriting them. */
final class AtomicTreeInspector {

	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, mixed>
	 */
	public static function inspect( array $tree ): array {
		$atomic_tree = [];
		$stats       = [ 'atomic' => 0, 'v3' => 0, 'unknown_atomic' => [] ];
		foreach ( $tree as $index => $element ) {
			if ( ! is_array( $element ) ) {
				++$stats['v3'];
				continue;
			}
			$atomic_tree = array_merge( $atomic_tree, self::inspect_node( $element, [ (int) $index ], $stats ) );
		}

		$architecture = 'empty';
		if ( $stats['atomic'] > 0 && $stats['v3'] > 0 ) {
			$architecture = 'mixed';
		} elseif ( $stats['atomic'] > 0 ) {
			$architecture = 'v4';
		} elseif ( $stats['v3'] > 0 ) {
			$architecture = 'v3';
		}

		return [
			'atomic_tree'         => $atomic_tree,
			'atomic_count'        => $stats['atomic'],
			'non_atomic_count'    => $stats['v3'],
			'unknown_atomic'      => $stats['unknown_atomic'],
			'architecture'        => $architecture,
			'schema_fingerprint'  => AtomicSchemaRepository::fingerprint(),
			'implicit_conversion' => false,
		];
	}

	/**
	 * @param array<string, mixed> $element
	 * @param list<int|string>     $path
	 * @param array<string, mixed> $stats
	 * @return list<array<string, mixed>>
	 */
	private static function inspect_node( array $element, array $path, array &$stats ): array {
		$el_type     = (string) ( $element['elType'] ?? '' );
		$widget_type = (string) ( $element['widgetType'] ?? '' );
		$atomic_type = 'widget' === $el_type ? $widget_type : $el_type;
		$is_atomic   = str_starts_with( $atomic_type, 'e-' );

		$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];
		$atomic_children = [];
		foreach ( $children as $index => $child ) {
			if ( ! is_array( $child ) ) {
				++$stats['v3'];
				continue;
			}
			$atomic_children = array_merge( $atomic_children, self::inspect_node( $child, array_merge( $path, [ 'elements', (int) $index ] ), $stats ) );
		}

		if ( ! $is_atomic ) {
			++$stats['v3'];
			return $atomic_children;
		}

		++$stats['atomic'];
		if ( null === AtomicSchemaRepository::for_atomic_type( $atomic_type ) ) {
			$stats['unknown_atomic'][] = [
				'path'        => $path,
				'atomic_type' => $atomic_type,
				'action'      => 'refresh_live_schema',
			];
		}
		$element['elements'] = $atomic_children;
		return [ $element ];
	}
}
