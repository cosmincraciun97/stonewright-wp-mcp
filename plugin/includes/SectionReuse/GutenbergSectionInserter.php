<?php
/**
 * Turns a portable Gutenberg section into blocks ready to insert into a post.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Support\BlockTree;

/**
 * The step between a validated {@see PortableSection} and the Gutenberg batch writer. It
 *
 * - keeps a synced pattern a reference (`core/block` with its `ref`) and fails with the exact pattern when it
 *   does not exist, unless the operation asks to detach it, which replaces the reference with a local copy of
 *   the pattern's blocks;
 * - renames an anchor the target post already uses, or that the section uses twice, to the next free
 *   `name-2`, `name-3`, and rewrites the matching `id` and `#link` in the saved markup;
 * - changes nothing else: attributes and markup are carried as they are.
 *
 * It reads the patterns it detaches and writes nothing.
 */
final class GutenbergSectionInserter {

	/** How deep patterns inside detached patterns are copied. */
	private const MAX_DETACH_DEPTH = 3;

	/**
	 * @param array<string, mixed>              $payload         A payload from {@see PortableSection::validate()}.
	 * @param array<int, array<string, mixed>>  $document_blocks The post's current blocks, for anchor uniqueness.
	 * @param bool|list<int>                    $detach          True to detach every synced pattern, or the pattern ids to detach.
	 * @return array{blocks:list<array<string,mixed>>,anchors_renamed:array<string,string>,detached:list<int>,warnings:list<array<string,mixed>>}|\WP_Error
	 */
	public static function instantiate( array $payload, array $document_blocks, bool|array $detach = false ): array|\WP_Error {
		$blocks   = array_values( (array) ( $payload['blocks'] ?? [] ) );
		$detached = [];
		$expanded = self::expand_patterns( $blocks, $detach, $detached, 1 );
		if ( $expanded instanceof \WP_Error ) {
			return $expanded;
		}

		$used    = self::anchors( $document_blocks );
		$renamed = [];
		$expanded = self::rename_anchors( $expanded, $used, $renamed );
		if ( [] !== $renamed ) {
			$expanded = self::rewrite_links( $expanded, $renamed );
		}

		$warnings = [];
		if ( [] !== $renamed ) {
			$warnings[] = [ 'code' => 'anchors_renamed', 'count' => count( $renamed ), 'items' => array_slice( array_map( static fn( string $old, string $new ): string => $old . ' -> ' . $new, array_keys( $renamed ), array_values( $renamed ) ), 0, 5 ) ];
		}

		return [ 'blocks' => $expanded, 'anchors_renamed' => $renamed, 'detached' => array_values( array_unique( $detached ) ), 'warnings' => $warnings ];
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @param bool|list<int>             $detach
	 * @param list<int>                  $detached
	 * @param list<int>                  $counts   Filled with how many blocks each input block became.
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private static function expand_patterns( array $blocks, bool|array $detach, array &$detached, int $depth, array &$counts = [] ): array|\WP_Error {
		$out    = [];
		$counts = [];
		foreach ( $blocks as $block ) {
			if ( 'core/block' === ( $block['blockName'] ?? '' ) ) {
				$ref     = (int) ( $block['attrs']['ref'] ?? 0 );
				$pattern = $ref > 0 ? get_post( $ref ) : null;
				if ( ! is_object( $pattern ) || SectionSource::PATTERN_POST_TYPE !== (string) $pattern->post_type || 'publish' !== (string) $pattern->post_status || ! current_user_can( 'read_post', $ref ) ) {
					return new \WP_Error(
						'stonewright_section_reference_missing',
						sprintf( 'The section refers to synced pattern "%d", which does not exist on this site.', $ref ),
						[ 'status' => 409, 'reference' => [ 'type' => 'synced_pattern', 'id' => (string) $ref, 'at' => [] ], 'missing' => [ [ 'type' => 'synced_pattern', 'id' => (string) $ref, 'at' => [] ] ], 'repair' => 'Choose another section. Nothing was written.' ]
					);
				}
				if ( true === $detach || ( is_array( $detach ) && in_array( $ref, array_map( 'intval', $detach ), true ) ) ) {
					if ( $depth > self::MAX_DETACH_DEPTH ) {
						return new \WP_Error( 'stonewright_section_too_large', 'Synced patterns are nested too deeply to detach.', [ 'status' => 413, 'limit_kind' => 'depth', 'limit' => self::MAX_DETACH_DEPTH ] );
					}
					$copy = self::expand_patterns( BlockTree::parse( (string) $pattern->post_content ), $detach, $detached, $depth + 1 );
					if ( $copy instanceof \WP_Error ) {
						return $copy;
					}
					$detached[] = $ref;
					array_push( $out, ...$copy );
					$counts[] = count( $copy );
					continue;
				}
				$out[]    = $block;
				$counts[] = 1;
				continue;
			}
			$children = array_values( (array) ( $block['innerBlocks'] ?? [] ) );
			if ( [] !== $children ) {
				$child_counts = [];
				$expanded     = self::expand_patterns( $children, $detach, $detached, $depth, $child_counts );
				if ( $expanded instanceof \WP_Error ) {
					return $expanded;
				}
				$block['innerBlocks'] = $expanded;
				if ( $child_counts !== array_fill( 0, count( $children ), 1 ) && is_array( $block['innerContent'] ?? null ) ) {
					$block['innerContent'] = self::placeholders_for( $block['innerContent'], $child_counts );
				}
			}
			$out[]    = $block;
			$counts[] = 1;
		}

		return $out;
	}

	/**
	 * A block's innerContent with the placeholder of each child repeated as many times as that child became.
	 *
	 * @param array<int, string|null> $content
	 * @param list<int>               $counts
	 * @return list<string|null>
	 */
	private static function placeholders_for( array $content, array $counts ): array {
		$out   = [];
		$child = 0;
		foreach ( $content as $piece ) {
			if ( null !== $piece ) {
				$out[] = $piece;
				continue;
			}
			for ( $i = 0; $i < ( $counts[ $child ] ?? 1 ); ++$i ) {
				$out[] = null;
			}
			++$child;
		}

		return $out;
	}

	/**
	 * Every anchor used by a list of blocks, at any depth.
	 *
	 * @param array<int, array<string, mixed>> $blocks
	 * @return array<string, true>
	 */
	private static function anchors( array $blocks ): array {
		$used = [];
		foreach ( $blocks as $block ) {
			$anchor = is_array( $block['attrs'] ?? null ) ? ( $block['attrs']['anchor'] ?? null ) : null;
			if ( is_string( $anchor ) && '' !== $anchor ) {
				$used[ $anchor ] = true;
			}
			$used += self::anchors( array_values( (array) ( $block['innerBlocks'] ?? [] ) ) );
		}

		return $used;
	}

	/**
	 * @param list<array<string, mixed>> $blocks
	 * @param array<string, true>        $used
	 * @param array<string, string>      $renamed Old anchor to new anchor, first rename of each name.
	 * @return list<array<string, mixed>>
	 */
	private static function rename_anchors( array $blocks, array &$used, array &$renamed ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$anchor = is_array( $block['attrs'] ?? null ) ? ( $block['attrs']['anchor'] ?? null ) : null;
			if ( is_string( $anchor ) && '' !== $anchor ) {
				if ( isset( $used[ $anchor ] ) ) {
					$new = self::free_anchor( $anchor, $used );
					$renamed[ $anchor ] ??= $new;
					$block['attrs']['anchor'] = $new;
					$block = self::rewrite_strings( $block, static fn( string $html ): string => str_replace( [ 'id="' . $anchor . '"', "id='" . $anchor . "'" ], [ 'id="' . $new . '"', "id='" . $new . "'" ], $html ) );
					$anchor = $new;
				}
				$used[ $anchor ] = true;
			}
			if ( [] !== ( $block['innerBlocks'] ?? [] ) ) {
				$block['innerBlocks'] = self::rename_anchors( array_values( (array) $block['innerBlocks'] ), $used, $renamed );
			}
			$out[] = $block;
		}

		return $out;
	}

	/** @param array<string, true> $used */
	private static function free_anchor( string $anchor, array $used ): string {
		for ( $n = 2; $n < 10000; ++$n ) {
			if ( ! isset( $used[ $anchor . '-' . $n ] ) ) {
				return $anchor . '-' . $n;
			}
		}

		return $anchor . '-' . bin2hex( random_bytes( 3 ) );
	}

	/**
	 * Points the `#anchor` links of the section at the renamed anchors.
	 *
	 * @param list<array<string, mixed>> $blocks
	 * @param array<string, string>      $renamed
	 * @return list<array<string, mixed>>
	 */
	private static function rewrite_links( array $blocks, array $renamed ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			$block = self::rewrite_strings(
				$block,
				static function ( string $html ) use ( $renamed ): string {
					foreach ( $renamed as $old => $new ) {
						$html = str_replace( [ 'href="#' . $old . '"', "href='#" . $old . "'" ], [ 'href="#' . $new . '"', "href='#" . $new . "'" ], $html );
					}
					return $html;
				}
			);
			if ( [] !== ( $block['innerBlocks'] ?? [] ) ) {
				$block['innerBlocks'] = self::rewrite_links( array_values( (array) $block['innerBlocks'] ), $renamed );
			}
			$out[] = $block;
		}

		return $out;
	}

	/**
	 * Applies a text rewrite to a block's own saved markup and keeps innerHTML equal to its string pieces.
	 *
	 * @param array<string, mixed>   $block
	 * @param callable(string):string $rewrite
	 * @return array<string, mixed>
	 */
	private static function rewrite_strings( array $block, callable $rewrite ): array {
		if ( is_array( $block['innerContent'] ?? null ) ) {
			$block['innerContent'] = array_map( static fn( mixed $piece ): mixed => is_string( $piece ) ? $rewrite( $piece ) : $piece, $block['innerContent'] );
			$block['innerHTML']    = implode( '', array_filter( $block['innerContent'], 'is_string' ) );

			return $block;
		}
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $rewrite( $block['innerHTML'] );
		}

		return $block;
	}
}
