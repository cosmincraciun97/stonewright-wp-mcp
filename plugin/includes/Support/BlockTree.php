<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Pure helpers for mutating a parsed block tree by integer-index path.
 *
 * Paths address a node inside the nested `innerBlocks` structure produced by
 * `parse_blocks()`. e.g. `[0, 2, 1]` = root → block 0 → its innerBlocks[2]
 * → its innerBlocks[1].
 */
final class BlockTree {

	/**
	 * Insert a new block at $position inside the parent identified by $path.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, mixed>             $new_block
	 * @return array<int, array<string, mixed>>
	 */
	public static function insert( array $blocks, array $path, int $position, array $new_block ): array {
		if ( empty( $path ) ) {
			$position = max( 0, min( $position, count( $blocks ) ) );
			array_splice( $blocks, $position, 0, [ $new_block ] );
			return $blocks;
		}

		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return $blocks;
		}

		$children = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] )
			? $blocks[ $head ]['innerBlocks']
			: [];

		$blocks[ $head ]['innerBlocks']  = self::insert( $children, $path, $position, $new_block );
		$blocks[ $head ]['innerContent'] = self::rebuild_inner_content( $blocks[ $head ] );

		return $blocks;
	}

	/**
	 * Apply a partial mutation (attrs / innerHTML) to the block at $path.
	 * Returns null if the path does not resolve to a block.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, mixed>             $mutation
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function update( array $blocks, array $path, array $mutation ): ?array {
		if ( empty( $path ) ) {
			return null;
		}

		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return null;
		}

		if ( empty( $path ) ) {
			$blocks[ $head ] = array_merge( $blocks[ $head ], $mutation );
			return $blocks;
		}

		$children = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] )
			? $blocks[ $head ]['innerBlocks']
			: [];

		$next = self::update( $children, $path, $mutation );
		if ( null === $next ) {
			return null;
		}

		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = self::rebuild_inner_content( $blocks[ $head ] );
		return $blocks;
	}

	/**
	 * Remove the block at $path. Returns null if path does not resolve.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function remove( array $blocks, array $path ): ?array {
		if ( empty( $path ) ) {
			return null;
		}

		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return null;
		}

		if ( empty( $path ) ) {
			array_splice( $blocks, $head, 1 );
			return array_values( $blocks );
		}

		$children = isset( $blocks[ $head ]['innerBlocks'] ) && is_array( $blocks[ $head ]['innerBlocks'] )
			? $blocks[ $head ]['innerBlocks']
			: [];

		$next = self::remove( $children, $path );
		if ( null === $next ) {
			return null;
		}

		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = self::rebuild_inner_content( $blocks[ $head ] );
		return $blocks;
	}

	/**
	 * Look up the block at $path. Returns null if the path is invalid.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @return array<string, mixed>|null
	 */
	public static function get( array $blocks, array $path ): ?array {
		foreach ( $path as $index ) {
			if ( ! isset( $blocks[ $index ] ) ) {
				return null;
			}
			$current = $blocks[ $index ];
			$blocks  = isset( $current['innerBlocks'] ) && is_array( $current['innerBlocks'] ) ? $current['innerBlocks'] : [];
		}
		return $current ?? null;
	}

	/**
	 * After mutating innerBlocks, the parent's innerContent stops matching.
	 * Rebuild a minimal innerContent: leading innerHTML chunk + one null per
	 * child block. This keeps `serialize_blocks()` happy.
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string|null>
	 */
	private static function rebuild_inner_content( array $block ): array {
		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		$children   = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];

		$content = [];
		if ( '' !== $inner_html ) {
			$content[] = $inner_html;
		}
		foreach ( $children as $_ ) {
			$content[] = null;
		}
		return $content;
	}
}
