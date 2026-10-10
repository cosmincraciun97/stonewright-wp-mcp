<?php
/**
 * The containers nested inside an Elementor section that can be copied on their own.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * A top-level element is always a section. An element nested inside one is a section only when it is a valid copy
 * root:
 *
 * - Elementor V3: a `container`, or a legacy `section` (an inner section). A `column` and a widget are not.
 * - Elementor V4: an Atomic layout element, `e-div-block` or `e-flexbox`. An Atomic widget and the parts of a
 *   composite element such as tabs are not.
 *
 * The root and everything under it must belong to one builder family: a subtree that mixes V3 and V4 nodes is not
 * a copy root. An extract takes such a container at any depth by its element id. A search lists a bounded number of
 * them per section, the shallower ones first, so an agent can see what it may ask for.
 */
final class NestedContainers {

	/** Most nested containers listed for one section. */
	public const MAX_LISTED = 6;

	/** Deepest level listed; the section itself is level 1. A container deeper than this is still extracted by its id. */
	public const MAX_DEPTH = 4;

	/** Most elements one listing walks. */
	private const MAX_WALK = 2000;

	/** Atomic elements that hold other elements and stand on their own. */
	private const V4_ROOTS = [ 'e-div-block', 'e-flexbox' ];

	/**
	 * Why an element cannot be the root of a copy, or null when it can. Nothing here looks at the children.
	 *
	 * @param array<string, mixed> $element
	 * @return 'widget'|'column'|'unsupported'|null
	 */
	public static function root_problem( array $element, string $builder ): ?string {
		$el_type = (string) ( $element['elType'] ?? '' );
		if ( 'widget' === $el_type ) {
			return 'widget';
		}
		if ( Builder::ELEMENTOR_V4 === $builder ) {
			return in_array( $el_type, self::V4_ROOTS, true ) ? null : 'unsupported';
		}
		if ( 'column' === $el_type ) {
			return 'column';
		}

		return in_array( $el_type, [ 'container', 'section' ], true ) ? null : 'unsupported';
	}

	/**
	 * The nested containers of one section, shallower first and in document order within a level.
	 *
	 * @param array<string, mixed> $section The section element, from {@see SectionSource::sections()}.
	 * @return array{items:list<array<string,mixed>>,truncated:bool} `truncated` says more were found than listed.
	 */
	public static function of( string $builder, array $section ): array {
		if ( ! Builder::is_elementor( $builder ) ) {
			return [ 'items' => [], 'truncated' => false ];
		}
		$found   = [];
		$level   = [ $section ];
		$walked  = 0;
		$deeper  = false;
		for ( $depth = 2; $depth <= self::MAX_DEPTH + 1 && [] !== $level; $depth++ ) {
			$next = [];
			foreach ( $level as $parent ) {
				foreach ( is_array( $parent['elements'] ?? null ) ? $parent['elements'] : [] as $child ) {
					if ( ! is_array( $child ) || '' === (string) ( $child['id'] ?? '' ) || ++$walked > self::MAX_WALK ) {
						continue;
					}
					$next[] = $child;
					if ( null !== self::root_problem( $child, $builder ) || [] === self::children( $child ) ) {
						continue;
					}
					if ( $depth > self::MAX_DEPTH ) {
						$deeper = true;
						continue;
					}
					$found[] = self::entry( $builder, $child, $depth );
				}
			}
			$level = $next;
		}

		return [ 'items' => array_slice( $found, 0, self::MAX_LISTED ), 'truncated' => $deeper || count( $found ) > self::MAX_LISTED ];
	}

	/**
	 * @param array<string, mixed> $element
	 * @return array<string, mixed>
	 */
	private static function entry( string $builder, array $element, int $depth ): array {
		$children = self::children( $element );
		$types    = [];
		foreach ( $children as $child ) {
			$type           = Builder::element_type( $child );
			$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
		}
		arsort( $types );
		$entry = [
			'locator'  => [ 'kind' => 'element', 'id' => (string) $element['id'] ],
			'depth'    => $depth,
			'type'     => Builder::element_type( $element ),
			'children' => count( $children ),
			'elements' => self::count( $element ),
		];
		$top = (int) reset( $types );
		if ( $top >= 2 ) {
			$entry['repeated'] = [ 'type' => (string) key( $types ), 'count' => $top ];
		}
		$heading = SectionInspector::inspect( $builder, $element )['outline']['heading'];
		if ( '' !== $heading ) {
			$entry['heading'] = $heading;
		}

		return $entry;
	}

	/**
	 * @param array<string, mixed> $element
	 * @return list<array<string, mixed>>
	 */
	private static function children( array $element ): array {
		return array_values( array_filter( is_array( $element['elements'] ?? null ) ? $element['elements'] : [], 'is_array' ) );
	}

	/** @param array<string, mixed> $element */
	private static function count( array $element ): int {
		$count = 1;
		foreach ( self::children( $element ) as $child ) {
			$count += self::count( $child );
		}

		return $count;
	}
}
