<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * Pure helpers for reading and mutating a parsed block tree by integer-index path.
 *
 * Paths address a node inside the nested `innerBlocks` structure produced by
 * {@see BlockTree::parse()}. e.g. `[0, 2, 1]` = root → block 0 → its
 * innerBlocks[2] → its innerBlocks[1].
 *
 * Every block read and write (blocks-parse, blocks-update, blocks-remove,
 * blocks-insert, blocks-batch-mutate and the motion applier) builds its tree
 * with {@see BlockTree::parse()}, so a path or index reported by one of them
 * names the same block in all of them.
 */
final class BlockTree {

	/**
	 * Parse post content into the addressable block tree.
	 *
	 * This is `parse_blocks()` without the blank freeform blocks that
	 * WordPress emits for the whitespace between root-level blocks (the line
	 * breaks the block editor writes between two block comments). They hold no
	 * content, so they get no index; every other block keeps its position
	 * among its siblings. Blocks nested in `innerBlocks` never carry such
	 * separators, because the parser keeps inner whitespace in `innerContent`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse( string $content ): array {
		return self::addressable( parse_blocks( $content ) );
	}

	/**
	 * Drop the blank root-level freeform blocks from an already parsed list.
	 *
	 * @param array<int, mixed> $blocks
	 * @return array<int, array<string, mixed>>
	 */
	public static function addressable( array $blocks ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( null === ( $block['blockName'] ?? null ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				continue;
			}
			$out[] = $block;
		}
		return $out;
	}

	/**
	 * Insert a new block at $position inside the parent identified by $path.
	 *
	 * An empty $path inserts among the root blocks. The parent keeps its
	 * wrapper markup: the new child gets a placeholder in the parent's
	 * innerContent next to its sibling placeholders.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @param array<string, mixed>             $new_block
	 * @return array<int, array<string, mixed>>|\WP_Error `invalid_path` when the parent does not exist,
	 *                                                    `unsafe_nested_structure` when the placeholder cannot be placed.
	 */
	public static function insert( array $blocks, array $path, int $position, array $new_block ): array|\WP_Error {
		if ( empty( $path ) ) {
			$position = max( 0, min( $position, count( $blocks ) ) );
			array_splice( $blocks, $position, 0, [ $new_block ] );
			return array_values( $blocks );
		}

		$head = array_shift( $path );
		if ( ! isset( $blocks[ $head ] ) ) {
			return new \WP_Error( 'invalid_path', 'Insert parent path not found.' );
		}

		$shape = self::inner_content( $blocks[ $head ] );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children = self::children( $blocks[ $head ] );

		if ( empty( $path ) ) {
			$position = max( 0, min( $position, count( $children ) ) );
			$content  = self::insert_placeholder( $shape, $position, count( $children ), (string) ( $blocks[ $head ]['innerHTML'] ?? '' ) );
			if ( $content instanceof \WP_Error ) {
				return $content;
			}
			array_splice( $children, $position, 0, [ $new_block ] );
			$blocks[ $head ]['innerBlocks']  = array_values( $children );
			$blocks[ $head ]['innerContent'] = $content;
			return $blocks;
		}

		$next = self::insert( $children, $path, $position, $new_block );
		if ( $next instanceof \WP_Error ) {
			return $next;
		}
		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
		return $blocks;
	}

	/**
	 * Apply a partial mutation (attrs / innerHTML) to the block at $path.
	 * Returns null if the path does not resolve to a block.
	 *
	 * Ancestors of the target keep their innerContent: only the target changes.
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

		$next = self::update( self::children( $blocks[ $head ] ), $path, $mutation );
		if ( null === $next ) {
			return null;
		}

		$blocks[ $head ]['innerBlocks'] = $next;
		return $blocks;
	}

	/**
	 * Remove the block at $path. Returns null if path does not resolve, a
	 * WP_Error (`unsafe_nested_structure`) when the parent's innerContent does
	 * not line up with its children.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @param array<int, int>                  $path
	 * @return array<int, array<string, mixed>>|\WP_Error|null
	 */
	public static function remove( array $blocks, array $path ): array|\WP_Error|null {
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

		$shape = self::inner_content( $blocks[ $head ] );
		if ( $shape instanceof \WP_Error ) {
			return $shape;
		}
		$children = self::children( $blocks[ $head ] );

		if ( 1 === count( $path ) ) {
			$child = $path[0];
			if ( ! isset( $children[ $child ] ) ) {
				return null;
			}
			$content = self::remove_placeholder( $shape, $child );
			array_splice( $children, $child, 1 );
			$blocks[ $head ]['innerBlocks']  = array_values( $children );
			$blocks[ $head ]['innerContent'] = $content;
			return $blocks;
		}

		$next = self::remove( $children, $path );
		if ( null === $next || $next instanceof \WP_Error ) {
			return $next;
		}

		$blocks[ $head ]['innerBlocks']  = $next;
		$blocks[ $head ]['innerContent'] = $shape;
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
	 * @param array<string, mixed> $block
	 * @return array<int, array<string, mixed>>
	 */
	private static function children( array $block ): array {
		return isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? array_values( $block['innerBlocks'] ) : [];
	}

	/**
	 * The block's innerContent, checked against its children: one null
	 * placeholder per inner block and strings that add up to innerHTML. A block
	 * built without an innerContent gets the minimal one (HTML, then one
	 * placeholder per child).
	 *
	 * @param array<string, mixed> $block
	 * @return array<int, string|null>|\WP_Error
	 */
	private static function inner_content( array $block ): array|\WP_Error {
		$children = self::children( $block );
		$content  = $block['innerContent'] ?? null;
		if ( ! is_array( $content ) ) {
			$html    = (string) ( $block['innerHTML'] ?? '' );
			$content = '' !== $html ? [ $html ] : [];
			foreach ( $children as $_ ) {
				$content[] = null;
			}
			return $content;
		}

		$strings = '';
		$nulls   = 0;
		foreach ( $content as $piece ) {
			if ( null === $piece ) {
				++$nulls;
			} elseif ( is_string( $piece ) ) {
				$strings .= $piece;
			} else {
				return new \WP_Error( 'unsafe_nested_structure', 'innerContent may contain only strings and child placeholders.' );
			}
		}
		if ( $nulls !== count( $children ) || $strings !== (string) ( $block['innerHTML'] ?? '' ) ) {
			return new \WP_Error( 'unsafe_nested_structure', 'innerContent does not match innerBlocks and innerHTML.' );
		}
		return array_values( $content );
	}

	/**
	 * @param array<int, string|null> $content
	 * @return array<int, string|null>|\WP_Error
	 */
	private static function insert_placeholder( array $content, int $position, int $child_count, string $inner_html ): array|\WP_Error {
		if ( 0 === $child_count ) {
			if ( '' !== $inner_html ) {
				return new \WP_Error( 'unsafe_nested_structure', 'A child cannot be inserted into non-empty wrapper HTML without an explicit placeholder.' );
			}
			return [ null ];
		}

		$seen = 0;
		foreach ( $content as $index => $piece ) {
			if ( null !== $piece ) {
				continue;
			}
			if ( $seen === $position ) {
				array_splice( $content, $index, 0, [ null ] );
				return array_values( $content );
			}
			++$seen;
			if ( $position === $child_count && $seen === $child_count ) {
				array_splice( $content, $index + 1, 0, [ null ] );
				return array_values( $content );
			}
		}
		return new \WP_Error( 'unsafe_nested_structure', 'The insertion placeholder could not be placed safely.' );
	}

	/**
	 * @param array<int, string|null> $content
	 * @return array<int, string|null>
	 */
	private static function remove_placeholder( array $content, int $position ): array {
		$seen = 0;
		foreach ( $content as $index => $piece ) {
			if ( null !== $piece ) {
				continue;
			}
			if ( $seen === $position ) {
				array_splice( $content, $index, 1 );
				break;
			}
			++$seen;
		}
		return array_values( $content );
	}
}
