<?php
/**
 * Block-level diff of two Gutenberg markup strings.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

use Stonewright\WpMcp\Support\BlockTree;

/**
 * Reports the blocks that were added, removed, moved or changed between two
 * versions of block markup.
 *
 * The parser is injected as a callable that turns markup into the array shape of
 * `parse_blocks()` (`blockName`, `attrs`, `innerHTML`, `innerBlocks`), so this
 * class needs no WordPress. {@see WordPressBlockParser} is the adapter for a
 * WordPress request.
 *
 * Result shape (a plain array, safe to encode as JSON):
 *
 *     kind        'blocks'
 *     status      'ok' | 'identical' | 'too_large'
 *     reason      '' | 'bytes' | 'blocks'   (only for too_large)
 *     message     short sentence for too_large, '' otherwise
 *     summary     { added, removed, moved, changed }  exact even when items are cut
 *     old_blocks, new_blocks   int, blocks on each side (0 when not parsed)
 *     truncated   bool, true when anything was left out or the diff was not computed
 *     cut         { items, attrs }  items left out by the cap and attribute changes
 *                 left out of the shown items
 *     masked      int, redacted attribute values and text lines in the shown items
 *     items       list, in document order, of one of
 *         { op: 'added',   name, path, descendants }
 *         { op: 'removed', name, path, descendants }      path is in the old markup
 *         { op: 'moved',   name, from, path }             from is the old path
 *         { op: 'changed', name, path, old_path, attrs, attrs_cut, text, masked }
 *
 * A path is the list of child indexes from the root, as in {@see BlockTree}.
 * `attrs` is the list of attribute changes in the shape of {@see FieldDiff}
 * fields. `text` is a {@see TextDiff} result for the block's own HTML, or null
 * when it did not change. A block whose only difference is in its children is not
 * listed; its children are.
 *
 * Blocks are matched level by level: unchanged runs first, then blocks with the
 * same name and the same own content, then blocks with the same name. A block
 * that left one place and appears unchanged in another is reported once as
 * moved. Whitespace between tags is not a change.
 *
 * Options (each is clamped to a ceiling):
 *
 *     max_bytes         524288  most bytes of markup on either side
 *     max_blocks        5000    most blocks on either side
 *     max_items         100     most items in the output
 *     max_attrs         25      most attribute changes per changed block
 *     max_value_chars   500     longest attribute value text
 *     max_edit_distance 1000    most edits searched for among siblings
 */
final class BlockDiff {

	private const DEFAULTS = [
		'max_bytes'         => 524288,
		'max_blocks'        => 5000,
		'max_items'         => 100,
		'max_attrs'         => 25,
		'max_value_chars'   => 500,
		'max_edit_distance' => 1000,
	];

	private const CEILING = [
		'max_bytes'         => 2097152,
		'max_blocks'        => 20000,
		'max_items'         => 500,
		'max_attrs'         => 100,
		'max_value_chars'   => 2000,
		'max_edit_distance' => 2500,
	];

	/** Options of the text diff shown for a changed block. */
	private const TEXT_OPTIONS = [
		'context'          => 1,
		'max_hunks'        => 5,
		'max_output_lines' => 80,
		'max_output_bytes' => 16384,
		'max_line_chars'   => 300,
	];

	private const MAX_NAME_CHARS = 100;

	/** Deepest nesting read; deeper children are not compared. */
	private const MAX_DEPTH = 100;

	/**
	 * @param string               $old     Markup before.
	 * @param string               $new     Markup after.
	 * @param callable             $parser  `fn( string $markup ): array` with the shape of `parse_blocks()`.
	 * @param array<string, int>   $options See the class description.
	 * @return array<string, mixed>
	 */
	public static function diff( string $old, string $new, callable $parser, array $options = [] ): array {
		$opt = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value       = isset( $options[ $key ] ) && is_int( $options[ $key ] ) ? $options[ $key ] : $default;
			$opt[ $key ] = max( 1, min( $value, self::CEILING[ $key ] ) );
		}

		$result = [
			'kind'       => 'blocks',
			'status'     => 'ok',
			'reason'     => '',
			'message'    => '',
			'summary'    => [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 0 ],
			'old_blocks' => 0,
			'new_blocks' => 0,
			'truncated'  => false,
			'cut'        => [ 'items' => 0, 'attrs' => 0 ],
			'masked'     => 0,
			'items'      => [],
		];

		if ( strlen( $old ) > $opt['max_bytes'] || strlen( $new ) > $opt['max_bytes'] ) {
			return self::too_large( $result, 'bytes', 'Too large to show: more than ' . $opt['max_bytes'] . ' bytes of markup.' );
		}
		if ( $old === $new ) {
			$result['status'] = 'identical';
			return $result;
		}

		$old_count = 0;
		$new_count = 0;
		$old_tree  = self::prepare( BlockTree::addressable( self::parse( $parser, $old ) ), $old_count, 0 );
		$new_tree  = self::prepare( BlockTree::addressable( self::parse( $parser, $new ) ), $new_count, 0 );

		$result['old_blocks'] = $old_count;
		$result['new_blocks'] = $new_count;
		if ( $old_count > $opt['max_blocks'] || $new_count > $opt['max_blocks'] ) {
			return self::too_large( $result, 'blocks', 'Too large to show: more than ' . $opt['max_blocks'] . ' blocks.' );
		}

		$state = [ 'items' => [], 'removed' => [], 'added' => [] ];
		self::compare( $old_tree, $new_tree, [], [], $state, $opt );

		$items = self::finish( $state );
		if ( [] === $items ) {
			$result['status'] = 'identical';
			return $result;
		}

		foreach ( $items as $item ) {
			++$result['summary'][ $item['op'] ];
		}
		usort( $items, [ self::class, 'order' ] );

		$shown = array_slice( $items, 0, $opt['max_items'] );
		$cut   = count( $items ) - count( $shown );

		$attrs_cut = 0;
		$masked    = 0;
		$truncated = $cut > 0;
		foreach ( $shown as $item ) {
			if ( 'changed' === $item['op'] ) {
				$attrs_cut += $item['attrs_cut'];
				$masked    += $item['masked'];
				$truncated  = $truncated || $item['attrs_cut'] > 0 || ( is_array( $item['text'] ) && ! empty( $item['text']['truncated'] ) );
			}
		}

		$result['items']     = $shown;
		$result['cut']       = [ 'items' => $cut, 'attrs' => $attrs_cut ];
		$result['masked']    = $masked;
		$result['truncated'] = $truncated;

		return $result;
	}

	/**
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	private static function too_large( array $result, string $reason, string $message ): array {
		$result['status']    = 'too_large';
		$result['reason']    = $reason;
		$result['message']   = $message;
		$result['truncated'] = true;
		return $result;
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function parse( callable $parser, string $markup ): array {
		$blocks = $parser( $markup );
		return is_array( $blocks ) ? $blocks : [];
	}

	/**
	 * Reduces parsed blocks to the fields the comparison needs, with a hash of each
	 * block's own content and of its whole subtree.
	 *
	 * @param array<int, mixed> $blocks
	 * @return list<array<string, mixed>>
	 */
	private static function prepare( array $blocks, int &$count, int $depth ): array {
		$out = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			++$count;
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) && '' !== $block['blockName'] ? $block['blockName'] : '(freeform)';
			$name  = DiffMask::clip( $name, self::MAX_NAME_CHARS );
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
			$html  = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$kids  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && $depth < self::MAX_DEPTH
				? self::prepare( $block['innerBlocks'], $count, $depth + 1 )
				: [];

			$own   = md5( $name . "\0" . self::canonical( $attrs ) . "\0" . self::tidy( $html ) );
			$out[] = [
				'name'  => $name,
				'attrs' => $attrs,
				'html'  => $html,
				'kids'  => $kids,
				'own'   => $own,
				'key'   => md5( $own . "\0" . implode( ',', array_column( $kids, 'key' ) ) ),
				'size'  => 1 + array_sum( array_column( $kids, 'size' ) ),
			];
		}
		return $out;
	}

	/**
	 * Block HTML without the whitespace between tags, which does not render.
	 */
	private static function tidy( string $html ): string {
		return trim( (string) preg_replace( '/>\s+</', '><', $html ) );
	}

	private static function canonical( mixed $value ): string {
		$json = json_encode( self::sorted( $value ), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
		return is_string( $json ) ? $json : '';
	}

	private static function sorted( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::sorted( $item );
		}
		return $value;
	}

	/**
	 * Compares two sibling lists and recurses into the pairs that differ.
	 *
	 * @param list<array<string, mixed>> $old
	 * @param list<array<string, mixed>> $new
	 * @param list<int>                  $old_path Path of the parent in the old tree.
	 * @param list<int>                  $new_path Path of the parent in the new tree.
	 * @param array<string, mixed>       $state
	 * @param array<string, int>         $opt
	 */
	private static function compare( array $old, array $new, array $old_path, array $new_path, array &$state, array $opt ): void {
		$pairs   = Myers::matches( array_column( $old, 'key' ), array_column( $new, 'key' ), $opt['max_edit_distance'] ) ?? [];
		$pairs[] = [ count( $old ), count( $new ) ];

		$i = 0;
		$j = 0;
		foreach ( $pairs as [ $x, $y ] ) {
			self::gap( $old, $new, $i, $x, $j, $y, $old_path, $new_path, $state, $opt );
			$i = $x + 1;
			$j = $y + 1;
		}
	}

	/**
	 * Pairs the blocks between two unchanged runs: same name and own content first,
	 * then same name. A pair is compared; blocks without a partner are leftovers.
	 *
	 * @param list<array<string, mixed>> $old
	 * @param list<array<string, mixed>> $new
	 * @param list<int>                  $old_path
	 * @param list<int>                  $new_path
	 * @param array<string, mixed>       $state
	 * @param array<string, int>         $opt
	 */
	private static function gap( array $old, array $new, int $from_a, int $to_a, int $from_b, int $to_b, array $old_path, array $new_path, array &$state, array $opt ): void {
		$partner = [];
		$taken   = [];
		foreach ( [ true, false ] as $same_own ) {
			for ( $a = $from_a; $a < $to_a; $a++ ) {
				if ( isset( $partner[ $a ] ) ) {
					continue;
				}
				for ( $b = $from_b; $b < $to_b; $b++ ) {
					if ( isset( $taken[ $b ] ) || $old[ $a ]['name'] !== $new[ $b ]['name'] ) {
						continue;
					}
					if ( $same_own && $old[ $a ]['own'] !== $new[ $b ]['own'] ) {
						continue;
					}
					$partner[ $a ] = $b;
					$taken[ $b ]   = true;
					break;
				}
			}
		}

		for ( $a = $from_a; $a < $to_a; $a++ ) {
			$path_a = array_merge( $old_path, [ $a ] );
			if ( isset( $partner[ $a ] ) ) {
				$b = $partner[ $a ];
				self::pair( $old[ $a ], $new[ $b ], $path_a, array_merge( $new_path, [ $b ] ), $state, $opt );
			} else {
				$state['removed'][] = [ 'path' => $path_a, 'block' => $old[ $a ] ];
			}
		}
		for ( $b = $from_b; $b < $to_b; $b++ ) {
			if ( ! isset( $taken[ $b ] ) ) {
				$state['added'][] = [ 'path' => array_merge( $new_path, [ $b ] ), 'block' => $new[ $b ] ];
			}
		}
	}

	/**
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 * @param list<int>            $old_path
	 * @param list<int>            $new_path
	 * @param array<string, mixed> $state
	 * @param array<string, int>   $opt
	 */
	private static function pair( array $a, array $b, array $old_path, array $new_path, array &$state, array $opt ): void {
		if ( $a['own'] !== $b['own'] ) {
			$attrs        = FieldDiff::diff( $a['attrs'], $b['attrs'], [ 'max_fields' => $opt['max_attrs'], 'max_value_chars' => $opt['max_value_chars'] ] );
			$html_changed = self::tidy( $a['html'] ) !== self::tidy( $b['html'] );
			$text         = $html_changed ? TextDiff::diff( $a['html'], $b['html'], self::TEXT_OPTIONS ) : null;

			if ( 'identical' !== $attrs['status'] || $html_changed ) {
				$state['items'][] = [
					'op'        => 'changed',
					'name'      => $b['name'],
					'path'      => $new_path,
					'old_path'  => $old_path,
					'attrs'     => $attrs['fields'],
					'attrs_cut' => $attrs['cut']['fields'],
					'text'      => $text,
					'masked'    => $attrs['masked'] + ( is_array( $text ) ? (int) $text['masked'] : 0 ),
				];
			}
		}
		self::compare( $a['kids'], $b['kids'], $old_path, $new_path, $state, $opt );
	}

	/**
	 * Turns the leftovers into items: a removed and an added block with the same
	 * subtree are one move.
	 *
	 * @param array<string, mixed> $state
	 * @return list<array<string, mixed>>
	 */
	private static function finish( array $state ): array {
		$items  = $state['items'];
		$by_key = [];
		foreach ( $state['removed'] as $index => $entry ) {
			$by_key[ $entry['block']['key'] ][] = $index;
		}
		$used = [];
		foreach ( $state['added'] as $entry ) {
			$key = $entry['block']['key'];
			if ( ! empty( $by_key[ $key ] ) ) {
				$index          = array_shift( $by_key[ $key ] );
				$used[ $index ] = true;
				$items[]        = [ 'op' => 'moved', 'name' => $entry['block']['name'], 'path' => $entry['path'], 'from' => $state['removed'][ $index ]['path'] ];
				continue;
			}
			$items[] = [ 'op' => 'added', 'name' => $entry['block']['name'], 'path' => $entry['path'], 'descendants' => $entry['block']['size'] - 1 ];
		}
		foreach ( $state['removed'] as $index => $entry ) {
			if ( ! isset( $used[ $index ] ) ) {
				$items[] = [ 'op' => 'removed', 'name' => $entry['block']['name'], 'path' => $entry['path'], 'descendants' => $entry['block']['size'] - 1 ];
			}
		}
		return $items;
	}

	/**
	 * Document order: by path, then removed blocks after the others at the same path.
	 *
	 * @param array<string, mixed> $x
	 * @param array<string, mixed> $y
	 */
	private static function order( array $x, array $y ): int {
		$length = min( count( $x['path'] ), count( $y['path'] ) );
		for ( $i = 0; $i < $length; $i++ ) {
			if ( $x['path'][ $i ] !== $y['path'][ $i ] ) {
				return $x['path'][ $i ] <=> $y['path'][ $i ];
			}
		}
		if ( count( $x['path'] ) !== count( $y['path'] ) ) {
			return count( $x['path'] ) <=> count( $y['path'] );
		}
		return ( 'removed' === $x['op'] ? 1 : 0 ) <=> ( 'removed' === $y['op'] ? 1 : 0 );
	}
}
