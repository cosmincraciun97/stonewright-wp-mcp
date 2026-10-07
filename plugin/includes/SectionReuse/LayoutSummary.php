<?php
/**
 * Layout-only summary and signature of one section.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

use Stonewright\WpMcp\Elementor\Provider\ProviderRouter;

/**
 * Reads a section's structure from its builder's own format: node types, nesting, column and grid counts, and
 * repeated children. Text, media and style values are ignored; the only values that count are the settings
 * that define layout (direction, wrap, grid size, content width, column size, block layout and alignment).
 * The summary is deterministic: the same structure always gives the same signature, whatever the ids,
 * wording or colors.
 */
final class LayoutSummary {

	/** Most node types listed in a summary. */
	private const MAX_TYPES = 12;

	/** V4 style props that define layout. */
	private const V4_LAYOUT_PROPS = [ 'display', 'flex-direction', 'flex-wrap', 'grid-template-columns', 'grid-template-rows', 'grid-auto-flow', 'position' ];

	/** V3 container settings that define layout. */
	private const V3_CONTAINER_KEYS = [ 'container_type', 'flex_direction', 'flex_direction_tablet', 'flex_direction_mobile', 'flex_wrap', 'content_width', 'layout' ];

	/**
	 * @param string                                    $builder One of the Builder constants.
	 * @param array<string, mixed>                      $node    An Elementor element or a parsed block.
	 * @param array{max_elements?:int,max_depth?:int}|null $limits  Caps; the Elementor route limits by default.
	 * @return array{columns:int,depth:int,elements:int,types:array<string,int>,repeated:array{type:string,count:int,parent:string}|null,signature:string,capped:bool}
	 */
	public static function of( string $builder, array $node, ?array $limits = null ): array {
		$limits = array_merge( ProviderRouter::element_limits(), $limits ?? [] );
		$state  = [
			'builder'  => $builder,
			'max'      => max( 1, (int) $limits['max_elements'] ),
			'max_depth' => max( 1, (int) $limits['max_depth'] ),
			'count'    => 0,
			'depth'    => 0,
			'columns'  => 0,
			'types'    => [],
			'repeated' => null,
			'capped'   => false,
		];
		$shape  = self::walk( $node, 1, $state );

		$types = $state['types'];
		uksort(
			$types,
			static fn( string $left, string $right ): int => [ $types[ $right ], $left ] <=> [ $types[ $left ], $right ]
		);

		return [
			'columns'   => $state['columns'],
			'depth'     => $state['depth'],
			'elements'  => $state['count'],
			'types'     => array_slice( $types, 0, self::MAX_TYPES, true ),
			'repeated'  => null === $state['repeated'] ? null : [ 'type' => $state['repeated']['type'], 'count' => $state['repeated']['count'], 'parent' => $state['repeated']['parent'] ],
			'signature' => substr( hash( 'sha256', (string) wp_json_encode( $shape ) ), 0, 16 ),
			'capped'    => $state['capped'],
		];
	}

	/**
	 * The node's token and layout attributes, and its children's shapes.
	 *
	 * @param array<string, mixed> $node
	 * @param array<string, mixed> $state
	 * @return array<int, mixed>|null Null when the cap stopped the walk before this node.
	 */
	private static function walk( array $node, int $depth, array &$state ): ?array {
		if ( $state['count'] >= $state['max'] ) {
			$state['capped'] = true;
			return null;
		}
		++$state['count'];
		$state['depth'] = max( $state['depth'], $depth );
		$token          = self::token( $state['builder'], $node );
		$state['types'][ $token ] = ( $state['types'][ $token ] ?? 0 ) + 1;

		$children = [];
		$list     = self::children( $state['builder'], $node );
		if ( [] !== $list && $depth >= $state['max_depth'] ) {
			$state['capped'] = true;
			$list            = [];
		}
		foreach ( $list as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			$shape = self::walk( $child, $depth + 1, $state );
			if ( null !== $shape ) {
				$children[] = [ 'node' => $child, 'shape' => $shape ];
			}
		}

		$attrs = self::layout_attributes( $state['builder'], $node );
		$state['columns'] = max( $state['columns'], self::column_count( $state['builder'], $node, $attrs, $children ) );
		self::note_repetition( $token, $children, $state );

		return [ $token, $attrs, array_column( $children, 'shape' ) ];
	}

	/**
	 * @param array<string, mixed> $node
	 * @return list<mixed>
	 */
	private static function children( string $builder, array $node ): array {
		$key  = Builder::GUTENBERG === $builder ? 'innerBlocks' : 'elements';
		$list = $node[ $key ] ?? [];

		return is_array( $list ) ? array_values( $list ) : [];
	}

	/** @param array<string, mixed> $node */
	public static function token( string $builder, array $node ): string {
		if ( Builder::GUTENBERG === $builder ) {
			$name = $node['blockName'] ?? null;

			return is_string( $name ) && '' !== $name ? $name : 'core/freeform';
		}
		$type = Builder::element_type( $node );

		return '' !== $type ? $type : 'unknown';
	}

	/**
	 * The settings of a node that define layout, as scalars under stable keys.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, scalar>
	 */
	private static function layout_attributes( string $builder, array $node ): array {
		$attrs = match ( $builder ) {
			Builder::GUTENBERG    => self::block_layout( $node ),
			Builder::ELEMENTOR_V4 => self::v4_layout( $node ),
			default               => self::v3_layout( $node ),
		};
		ksort( $attrs );

		return $attrs;
	}

	/**
	 * @param array<string, mixed> $node
	 * @return array<string, scalar>
	 */
	private static function v3_layout( array $node ): array {
		$settings = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		$el_type  = (string) ( $node['elType'] ?? '' );
		$out      = [];
		if ( 'container' === $el_type ) {
			foreach ( self::V3_CONTAINER_KEYS as $key ) {
				if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
					$out[ $key ] = $settings[ $key ];
				}
			}
			foreach ( [ 'grid_columns_grid', 'grid_rows_grid' ] as $key ) {
				$size = is_array( $settings[ $key ] ?? null ) ? ( $settings[ $key ]['size'] ?? null ) : null;
				if ( is_scalar( $size ) ) {
					$out[ $key ] = $size;
				}
			}
		} elseif ( 'section' === $el_type && isset( $settings['structure'] ) && is_scalar( $settings['structure'] ) ) {
			$out['structure'] = $settings['structure'];
		} elseif ( 'column' === $el_type && isset( $settings['_column_size'] ) && is_scalar( $settings['_column_size'] ) ) {
			$out['_column_size'] = $settings['_column_size'];
		}

		return $out;
	}

	/**
	 * Layout props of the node's local styles, by breakpoint. A style that applies in a state (hover, focus) is not layout.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, scalar>
	 */
	private static function v4_layout( array $node ): array {
		$out    = [];
		$styles = is_array( $node['styles'] ?? null ) ? $node['styles'] : [];
		foreach ( $styles as $style ) {
			$variants = is_array( $style ) && is_array( $style['variants'] ?? null ) ? $style['variants'] : [];
			foreach ( $variants as $variant ) {
				$meta = is_array( $variant ) && is_array( $variant['meta'] ?? null ) ? $variant['meta'] : [];
				if ( ! is_array( $variant ) || ( isset( $meta['state'] ) && null !== $meta['state'] && '' !== $meta['state'] ) ) {
					continue;
				}
				$breakpoint = isset( $meta['breakpoint'] ) && is_string( $meta['breakpoint'] ) && '' !== $meta['breakpoint'] ? $meta['breakpoint'] : 'desktop';
				$props      = is_array( $variant['props'] ?? null ) ? $variant['props'] : [];
				foreach ( self::V4_LAYOUT_PROPS as $prop ) {
					if ( array_key_exists( $prop, $props ) ) {
						$out[ $breakpoint . ':' . $prop ] = self::plain( $props[ $prop ] );
					}
				}
			}
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $block
	 * @return array<string, scalar>
	 */
	private static function block_layout( array $block ): array {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$out   = [];
		$layout = is_array( $attrs['layout'] ?? null ) ? $attrs['layout'] : [];
		foreach ( [ 'type', 'orientation', 'columnCount', 'minimumColumnWidth', 'flexWrap' ] as $key ) {
			if ( isset( $layout[ $key ] ) && is_scalar( $layout[ $key ] ) ) {
				$out[ 'layout.' . $key ] = $layout[ $key ];
			}
		}
		foreach ( [ 'align', 'width', 'isStackedOnMobile', 'columns' ] as $key ) {
			if ( isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ) {
				$out[ $key ] = $attrs[ $key ];
			}
		}

		return $out;
	}

	/** A style prop's value without its type envelope, as a scalar. */
	private static function plain( mixed $value ): string|int|float|bool {
		if ( is_array( $value ) && array_key_exists( 'value', $value ) ) {
			$value = $value['value'];
		}
		if ( is_scalar( $value ) ) {
			return $value;
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * How many columns the node lays its children out in; zero when it is not a row or a grid.
	 *
	 * @param array<string, mixed>            $node
	 * @param array<string, scalar>           $attrs
	 * @param list<array{node:mixed,shape:mixed}> $children
	 */
	private static function column_count( string $builder, array $node, array $attrs, array $children ): int {
		$count = count( $children );
		if ( Builder::GUTENBERG === $builder ) {
			$name = (string) ( $node['blockName'] ?? '' );
			if ( 'core/columns' === $name ) {
				return $count;
			}
			if ( 'core/group' === $name || 'core/row' === $name || 'core/stack' === $name ) {
				$type = (string) ( $attrs['layout.type'] ?? '' );
				if ( 'grid' === $type ) {
					return (int) ( $attrs['layout.columnCount'] ?? $count );
				}
				return 'flex' === $type && 'vertical' !== (string) ( $attrs['layout.orientation'] ?? '' ) && 'core/stack' !== $name ? $count : 0;
			}
			return 0;
		}
		if ( Builder::ELEMENTOR_V4 === $builder ) {
			if ( 'grid' === (string) ( $attrs['desktop:display'] ?? '' ) ) {
				return $count;
			}
			return 'flex' === (string) ( $attrs['desktop:display'] ?? '' ) && in_array( (string) ( $attrs['desktop:flex-direction'] ?? '' ), [ 'row', 'row-reverse' ], true ) ? $count : 0;
		}
		$el_type = (string) ( $node['elType'] ?? '' );
		if ( 'section' === $el_type ) {
			return $count;
		}
		if ( 'container' !== $el_type ) {
			return 0;
		}
		if ( 'grid' === (string) ( $attrs['container_type'] ?? '' ) ) {
			return isset( $attrs['grid_columns_grid'] ) ? (int) $attrs['grid_columns_grid'] : $count;
		}

		return in_array( (string) ( $attrs['flex_direction'] ?? '' ), [ 'row', 'row-reverse' ], true ) ? $count : 0;
	}

	/**
	 * Notes the largest group of identical siblings under one parent; the first one found wins a tie.
	 *
	 * @param list<array{node:mixed,shape:mixed}> $children
	 * @param array<string, mixed>                 $state
	 */
	private static function note_repetition( string $parent_token, array $children, array &$state ): void {
		if ( count( $children ) < 2 ) {
			return;
		}
		$groups = [];
		foreach ( $children as $child ) {
			$key = hash( 'sha256', (string) wp_json_encode( $child['shape'] ) );
			$groups[ $key ]['count'] = ( $groups[ $key ]['count'] ?? 0 ) + 1;
			$groups[ $key ]['type']  = (string) ( is_array( $child['shape'] ) ? $child['shape'][0] : 'unknown' );
		}
		foreach ( $groups as $group ) {
			if ( $group['count'] >= 2 && ( null === $state['repeated'] || $group['count'] > $state['repeated']['count'] ) ) {
				$state['repeated'] = [ 'type' => $group['type'], 'count' => $group['count'], 'parent' => $parent_token ];
			}
		}
	}
}
