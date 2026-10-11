<?php
/**
 * Element-level diff of two Elementor trees, V3 and V4.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

use Stonewright\WpMcp\Elementor\Write\ElementTreeDiff;

/**
 * Reports what changed between two versions of an Elementor document, per element.
 *
 * Elements are matched by id, as in {@see ElementTreeDiff}, and compared by their
 * own content, so a container is not listed because its children changed. The
 * trees are read with {@see ElementTreeDiff::tree_from_meta()}, so a decoded
 * array, a JSON string and a string that was encoded twice are all accepted.
 *
 * Result shape (a plain array, safe to encode as JSON):
 *
 *     kind        'elementor'
 *     status      'ok' | 'identical' | 'too_large'
 *     reason      '' | 'nodes'   (only for too_large)
 *     message     short sentence for too_large, '' otherwise
 *     summary     { added, removed, moved, changed }  exact even when elements are cut.
 *                 An element that moved and was edited counts in both.
 *     old_nodes, new_nodes   int, elements on each side
 *     truncated   bool, true when anything was left out or the diff was not computed
 *     cut         { elements }  elements the cap left out
 *     masked      int, redacted values in the shown elements
 *     elements    list, in document order (removed elements last), of
 *         { op: 'added',   id, type, parent, index, label? }
 *         { op: 'removed', id, type, parent, index, label? }       parent and index are old
 *         { op: 'moved',   id, type, reason: 'parent' | 'order',
 *                          from_parent, to_parent, from_index, to_index, label?,
 *                          settings?, other?, fields_cut? }
 *         { op: 'changed', id, type, label?, settings, other, fields_cut }
 *
 * `parent` is the id of the parent element, null at the root; `index` is the
 * position among its siblings. `type` is the widget type, or the element type for
 * containers. `label` is a short text taken from a title-like setting.
 * `settings` and `other` are lists of changes in the shape of {@see FieldDiff}
 * fields: `settings` for the element's settings, `other` for every other key of
 * the element (`widgetType`, `styles`, ...). Paths are relative to each. A V4
 * typed prop (`$$type`) is one field whose values read as the prop's content,
 * for example `Hello`, `16px` or `e-a, e-b`. `fields_cut` is the number of
 * changes left out of the element by `max_fields`.
 *
 * A sibling counts as moved by order when it is not part of the longest run of
 * siblings that kept their relative order, so inserting an element never moves
 * the others.
 *
 * Options (each is clamped to a ceiling):
 *
 *     max_elements      100    most elements in the output
 *     max_fields        25     most changes per element and list
 *     max_value_chars   500    longest value text
 *     max_nodes         10000  most elements on either side
 *     max_edit_distance 1000   most edits searched for among siblings
 */
final class ElementorTreeDiff {

	private const DEFAULTS = [
		'max_elements'      => 100,
		'max_fields'        => 25,
		'max_value_chars'   => 500,
		'max_nodes'         => 10000,
		'max_edit_distance' => 1000,
	];

	private const CEILING = [
		'max_elements'      => 500,
		'max_fields'        => 100,
		'max_value_chars'   => 2000,
		'max_nodes'         => 50000,
		'max_edit_distance' => 2500,
	];

	/** Settings tried, in order, for an element's label. */
	private const LABEL_KEYS = [ 'title', 'text', 'heading', 'button_text', 'label', 'editor', 'content', '_title' ];

	private const MAX_LABEL_CHARS = 80;

	private const MAX_DEPTH = 100;

	/**
	 * @param array<int, mixed>|string $old     Tree before, or its stored meta value.
	 * @param array<int, mixed>|string $new     Tree after, or its stored meta value.
	 * @param array<string, int>       $options See the class description.
	 * @return array<string, mixed>
	 */
	public static function diff( array|string $old, array|string $new, array $options = [] ): array {
		$opt = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$value       = isset( $options[ $key ] ) && is_int( $options[ $key ] ) ? $options[ $key ] : $default;
			$opt[ $key ] = max( 1, min( $value, self::CEILING[ $key ] ) );
		}

		$old_tree = ElementTreeDiff::tree_from_meta( $old );
		$new_tree = ElementTreeDiff::tree_from_meta( $new );

		$result = [
			'kind'      => 'elementor',
			'status'    => 'ok',
			'reason'    => '',
			'message'   => '',
			'summary'   => [ 'added' => 0, 'removed' => 0, 'moved' => 0, 'changed' => 0 ],
			'old_nodes' => self::count( $old_tree, 0 ),
			'new_nodes' => self::count( $new_tree, 0 ),
			'truncated' => false,
			'cut'       => [ 'elements' => 0 ],
			'masked'    => 0,
			'elements'  => [],
		];

		if ( $result['old_nodes'] > $opt['max_nodes'] || $result['new_nodes'] > $opt['max_nodes'] ) {
			$result['status']    = 'too_large';
			$result['reason']    = 'nodes';
			$result['message']   = 'Too large to show: more than ' . $opt['max_nodes'] . ' elements.';
			$result['truncated'] = true;
			return $result;
		}

		$was = self::index( $old_tree );
		$now = self::index( $new_tree );

		$order_moves = self::order_moves( $was, $now, $opt['max_edit_distance'] );

		$entries = [];
		foreach ( $now['order'] as $id ) {
			$after = $now['by_id'][ $id ];
			if ( ! isset( $was['by_id'][ $id ] ) ) {
				$entry = [ 'op' => 'added', 'id' => $id, 'type' => $after['type'], 'parent' => $after['parent'], 'index' => $after['index'] ];
				$entry = self::with_label( $entry, $after['settings'], $opt );
				$entries[] = $entry;
				continue;
			}

			$before  = $was['by_id'][ $id ];
			$content = self::content( $before, $after, $opt );
			$moved   = $before['parent'] !== $after['parent'] || isset( $order_moves[ $id ] );

			if ( ! $moved && null === $content ) {
				continue;
			}

			if ( $moved ) {
				$entry = [
					'op'          => 'moved',
					'id'          => $id,
					'type'        => $after['type'],
					'reason'      => $before['parent'] !== $after['parent'] ? 'parent' : 'order',
					'from_parent' => $before['parent'],
					'to_parent'   => $after['parent'],
					'from_index'  => $before['index'],
					'to_index'    => $after['index'],
				];
			} else {
				$entry = [ 'op' => 'changed', 'id' => $id, 'type' => $after['type'] ];
			}
			$entry = self::with_label( $entry, $after['settings'], $opt );
			if ( null !== $content ) {
				$entry['settings']   = $content['settings'];
				$entry['other']      = $content['other'];
				$entry['fields_cut'] = $content['cut'];
				$entry['changed']    = true;
				$entry['masked']     = $content['masked'];
			}
			$entries[] = $entry;
		}
		foreach ( $was['order'] as $id ) {
			if ( isset( $now['by_id'][ $id ] ) ) {
				continue;
			}
			$before    = $was['by_id'][ $id ];
			$entry     = [ 'op' => 'removed', 'id' => $id, 'type' => $before['type'], 'parent' => $before['parent'], 'index' => $before['index'] ];
			$entries[] = self::with_label( $entry, $before['settings'], $opt );
		}

		if ( [] === $entries ) {
			$result['status'] = 'identical';
			return $result;
		}

		foreach ( $entries as $entry ) {
			++$result['summary'][ $entry['op'] ];
			if ( 'moved' === $entry['op'] && ! empty( $entry['changed'] ) ) {
				++$result['summary']['changed'];
			}
		}

		$shown     = array_slice( $entries, 0, $opt['max_elements'] );
		$cut       = count( $entries ) - count( $shown );
		$masked    = 0;
		$truncated = $cut > 0;
		foreach ( $shown as &$entry ) {
			$masked   += $entry['masked'] ?? 0;
			$truncated = $truncated || ( $entry['fields_cut'] ?? 0 ) > 0;
			unset( $entry['changed'], $entry['masked'] );
		}
		unset( $entry );

		$result['elements']  = $shown;
		$result['cut']       = [ 'elements' => $cut ];
		$result['masked']    = $masked;
		$result['truncated'] = $truncated;

		return $result;
	}

	/**
	 * @param array<int|string, mixed> $nodes
	 */
	private static function count( array $nodes, int $depth ): int {
		$total = 0;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			++$total;
			if ( $depth < self::MAX_DEPTH && isset( $node['elements'] ) && is_array( $node['elements'] ) ) {
				$total += self::count( $node['elements'], $depth + 1 );
			}
		}
		return $total;
	}

	/**
	 * @param array<int|string, mixed> $tree
	 * @return array{by_id: array<string, array<string, mixed>>, order: list<string>}
	 */
	private static function index( array $tree ): array {
		$index = [ 'by_id' => [], 'order' => [] ];
		self::walk( $tree, null, 0, $index );
		return $index;
	}

	/**
	 * @param array<int|string, mixed>                                              $nodes
	 * @param array{by_id: array<string, array<string, mixed>>, order: list<string>} $index
	 */
	private static function walk( array $nodes, ?string $parent, int $depth, array &$index ): void {
		$position = 0;
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$id       = isset( $node['id'] ) && is_scalar( $node['id'] ) ? (string) $node['id'] : '';
			$children = isset( $node['elements'] ) && is_array( $node['elements'] ) ? $node['elements'] : [];

			if ( '' !== $id ) {
				$own = $node;
				unset( $own['id'], $own['elements'] );
				$settings = isset( $own['settings'] ) ? self::plain( $own['settings'] ) : [];
				unset( $own['settings'] );

				if ( ! isset( $index['by_id'][ $id ] ) ) {
					$index['order'][] = $id;
				}
				$type                = isset( $node['widgetType'] ) && is_string( $node['widgetType'] ) && '' !== $node['widgetType']
					? $node['widgetType']
					: ( isset( $node['elType'] ) && is_string( $node['elType'] ) ? $node['elType'] : '' );
				$index['by_id'][ $id ] = [
					'type'     => DiffMask::key( $type ),
					'parent'   => $parent,
					'index'    => $position,
					'settings' => is_array( $settings ) ? $settings : [],
					'other'    => $own,
				];
			}
			++$position;
			if ( $depth < self::MAX_DEPTH ) {
				self::walk( $children, '' !== $id ? $id : $parent, $depth + 1, $index );
			}
		}
	}

	private static function plain( mixed $value ): mixed {
		return is_object( $value ) ? get_object_vars( $value ) : $value;
	}

	/**
	 * Elements that stayed under the same parent but left the longest run of
	 * siblings that kept their order.
	 *
	 * @param array{by_id: array<string, array<string, mixed>>, order: list<string>} $was
	 * @param array{by_id: array<string, array<string, mixed>>, order: list<string>} $now
	 * @return array<string, true>
	 */
	private static function order_moves( array $was, array $now, int $max_d ): array {
		$stay = static fn( string $id ): bool => isset( $was['by_id'][ $id ], $now['by_id'][ $id ] )
			&& $was['by_id'][ $id ]['parent'] === $now['by_id'][ $id ]['parent'];

		$before = [];
		foreach ( $was['order'] as $id ) {
			if ( $stay( $id ) ) {
				$before[ 'p:' . (string) $was['by_id'][ $id ]['parent'] ][] = $id;
			}
		}
		$after = [];
		foreach ( $now['order'] as $id ) {
			if ( $stay( $id ) ) {
				$after[ 'p:' . (string) $now['by_id'][ $id ]['parent'] ][] = $id;
			}
		}

		$moved = [];
		foreach ( $after as $group => $ids ) {
			$pairs   = Myers::matches( $before[ $group ] ?? [], $ids, $max_d ) ?? [];
			$matched = [];
			foreach ( $pairs as [ , $j ] ) {
				$matched[ $j ] = true;
			}
			foreach ( $ids as $j => $id ) {
				if ( ! isset( $matched[ $j ] ) ) {
					$moved[ $id ] = true;
				}
			}
		}
		return $moved;
	}

	/**
	 * Setting and other-key changes of one element, or null when its own content is the same.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @param array<string, int>   $opt
	 * @return array{settings: list<array<string, mixed>>, other: list<array<string, mixed>>, cut: int, masked: int}|null
	 */
	private static function content( array $before, array $after, array $opt ): ?array {
		if ( $before['settings'] === $after['settings'] && $before['other'] === $after['other'] ) {
			return null;
		}
		$field_options = [ 'max_fields' => $opt['max_fields'], 'max_value_chars' => $opt['max_value_chars'] ];
		$settings      = FieldDiff::diff( $before['settings'], $after['settings'], $field_options );
		$other         = FieldDiff::diff( $before['other'], $after['other'], $field_options );
		if ( 'identical' === $settings['status'] && 'identical' === $other['status'] ) {
			return null;
		}
		return [
			'settings' => $settings['fields'],
			'other'    => $other['fields'],
			'cut'      => $settings['cut']['fields'] + $other['cut']['fields'],
			'masked'   => $settings['masked'] + $other['masked'],
		];
	}

	/**
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $settings
	 * @param array<string, int>   $opt
	 * @return array<string, mixed>
	 */
	private static function with_label( array $entry, array $settings, array $opt ): array {
		foreach ( self::LABEL_KEYS as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			[ $text, $hidden ] = DiffMask::value( $settings[ $key ], $key, $opt['max_value_chars'] );
			if ( $hidden ) {
				continue;
			}
			$text = trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $text ) ) );
			if ( '' === $text || DiffMask::sensitive( $text ) ) {
				continue;
			}
			$entry['label'] = DiffMask::clip( $text, self::MAX_LABEL_CHARS );
			break;
		}
		return $entry;
	}
}
