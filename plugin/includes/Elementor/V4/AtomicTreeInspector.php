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

	/**
	 * Architecture of the subtree rooted at an element id.
	 *
	 * @param array<int, mixed> $tree
	 */
	public static function subtree_architecture( array $tree, string $element_id ): string {
		$node = self::find_node( $tree, $element_id );
		if ( null === $node ) {
			return 'not_found';
		}
		return (string) self::inspect( [ $node ] )['architecture'];
	}

	/**
	 * Return maximal V3-only subtrees. In a mixed document these are the
	 * surgical boundaries that V3 abilities may target without touching an
	 * Atomic ancestor or descendant.
	 *
	 * @param array<int, mixed> $tree
	 * @return array{items:list<array<string,mixed>>,truncated:bool}
	 */
	public static function v3_safe_roots( array $tree, int $limit = 100 ): array {
		$items     = [];
		$truncated = false;
		self::collect_v3_safe_roots( $tree, [], $items, $truncated, max( 1, min( 500, $limit ) ) );
		return [
			'items'     => $items,
			'truncated' => $truncated,
		];
	}

	/**
	 * @param array<int, mixed>              $nodes
	 * @param list<int|string>               $path
	 * @param list<array<string,mixed>>      $items
	 */
	private static function collect_v3_safe_roots(
		array $nodes,
		array $path,
		array &$items,
		bool &$truncated,
		int $limit
	): void {
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( count( $items ) >= $limit ) {
				$truncated = true;
				return;
			}

			$current_path = array_merge( $path, [ (int) $index ] );
			$architecture = (string) ( self::inspect( [ $node ] )['architecture'] ?? 'empty' );
			if ( 'v3' === $architecture ) {
				$items[] = [
					'element_id'  => (string) ( $node['id'] ?? '' ),
					'el_type'     => (string) ( $node['elType'] ?? '' ),
					'widget_type' => (string) ( $node['widgetType'] ?? '' ),
					'path'        => $current_path,
				];
				continue;
			}

			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];
			self::collect_v3_safe_roots(
				$children,
				array_merge( $current_path, [ 'elements' ] ),
				$items,
				$truncated,
				$limit
			);
			if ( $truncated ) {
				return;
			}
		}
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, mixed>|null
	 */
	private static function find_node( array $tree, string $element_id ): ?array {
		foreach ( $tree as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['id'] ) && (string) $element['id'] === $element_id ) {
				return $element;
			}
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : [];
			$found    = self::find_node( $children, $element_id );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}
}
