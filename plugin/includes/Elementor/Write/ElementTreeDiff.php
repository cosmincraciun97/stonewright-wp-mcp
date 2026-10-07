<?php
/**
 * Element-level comparison of two Elementor trees.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Write;

/**
 * Finds the changes between two trees that a write did not plan.
 *
 * An element is identified by its id and compared by its own content (every key
 * except `elements`) and its parent, so a container does not change because its
 * children did, and sibling order alone is not a change.
 */
final class ElementTreeDiff {

	/** Longest report, equal to the bound of a change list. */
	public const MAX_REPORTED = 50;

	/**
	 * Elements that changed, appeared or disappeared although the plan did not
	 * touch them.
	 *
	 * @param array<int, mixed> $before  Tree before the write.
	 * @param array<int, mixed> $after   Tree read back after the write.
	 * @param list<string>      $touched Ids the plan adds, updates or moves.
	 * @param list<string>      $removed Ids the plan removes; their subtree goes with them.
	 * @return list<array{ref: string, action: string}>
	 */
	public static function unexpected( array $before, array $after, array $touched, array $removed = [] ): array {
		$was     = self::index( $before );
		$now     = self::index( $after );
		$allowed = array_fill_keys( array_map( 'strval', $touched ), true );
		foreach ( $removed as $root ) {
			foreach ( self::subtree_ids( $before, (string) $root ) as $id ) {
				$allowed[ $id ] = true;
			}
		}

		$out = [];
		foreach ( $now as $id => $record ) {
			$id = (string) $id;
			if ( isset( $allowed[ $id ] ) ) {
				continue;
			}
			if ( ! isset( $was[ $id ] ) ) {
				$out[] = [ 'ref' => $id, 'action' => 'add' ];
			} elseif ( $was[ $id ] !== $record ) {
				$out[] = [ 'ref' => $id, 'action' => 'update' ];
			}
		}
		foreach ( array_keys( $was ) as $id ) {
			$id = (string) $id;
			if ( ! isset( $now[ $id ] ) && ! isset( $allowed[ $id ] ) ) {
				$out[] = [ 'ref' => $id, 'action' => 'remove' ];
			}
		}

		return array_slice( $out, 0, self::MAX_REPORTED );
	}

	/**
	 * A stored `_elementor_data` value as a tree: a JSON string, an already decoded
	 * array, or a string that was encoded twice. Anything else is an empty tree.
	 *
	 * @return array<int, mixed>
	 */
	public static function tree_from_meta( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( is_string( $decoded ) ) {
			$decoded = json_decode( $decoded, true );
		}
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return array<string, array{hash: string, parent: string}>
	 */
	private static function index( array $tree ): array {
		$index = [];
		self::walk( $tree, '', $index );
		return $index;
	}

	/**
	 * @param array<int, mixed>                                  $nodes
	 * @param array<string, array{hash: string, parent: string}> $index
	 */
	private static function walk( array $nodes, string $parent, array &$index ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id       = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
			$children = is_array( $node['elements'] ?? null ) ? $node['elements'] : [];
			if ( '' !== $id ) {
				$own           = $node;
				unset( $own['elements'] );
				$index[ $id ] = [ 'hash' => TreeHasher::hash( $own ), 'parent' => $parent ];
			}
			self::walk( $children, '' !== $id ? $id : $parent, $index );
		}
	}

	/**
	 * @param array<int, mixed> $tree
	 * @return list<string>
	 */
	private static function subtree_ids( array $tree, string $root ): array {
		$found = self::find( $tree, $root );
		if ( null === $found ) {
			return [ $root ];
		}
		$ids = [];
		self::collect( [ $found ], $ids );
		return $ids;
	}

	/**
	 * @param array<int, mixed> $nodes
	 * @return array<string, mixed>|null
	 */
	private static function find( array $nodes, string $id ): ?array {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && (string) $node['id'] === $id ) {
				return $node;
			}
			$inner = self::find( is_array( $node['elements'] ?? null ) ? $node['elements'] : [], $id );
			if ( null !== $inner ) {
				return $inner;
			}
		}
		return null;
	}

	/**
	 * @param array<int, mixed> $nodes
	 * @param list<string>      $ids
	 */
	private static function collect( array $nodes, array &$ids ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['id'] ) && is_scalar( $node['id'] ) && '' !== (string) $node['id'] ) {
				$ids[] = (string) $node['id'];
			}
			self::collect( is_array( $node['elements'] ?? null ) ? $node['elements'] : [], $ids );
		}
	}
}
