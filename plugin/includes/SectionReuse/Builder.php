<?php
/**
 * The builder families section reuse works within.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Three builder families. A section is reused inside its own family and is never converted to another.
 */
final class Builder {

	public const GUTENBERG    = 'gutenberg';
	public const ELEMENTOR_V3 = 'elementor-v3';
	public const ELEMENTOR_V4 = 'elementor-v4';

	/** @return list<string> */
	public static function all(): array {
		return [ self::GUTENBERG, self::ELEMENTOR_V3, self::ELEMENTOR_V4 ];
	}

	public static function is_valid( string $builder ): bool {
		return in_array( $builder, self::all(), true );
	}

	public static function is_elementor( string $builder ): bool {
		return self::ELEMENTOR_V3 === $builder || self::ELEMENTOR_V4 === $builder;
	}

	/** The name of the node type of an Elementor element: its widget type for a widget, its element type otherwise. */
	public static function element_type( array $element ): string {
		$el_type = (string) ( $element['elType'] ?? '' );

		return 'widget' === $el_type ? (string) ( $element['widgetType'] ?? '' ) : $el_type;
	}

	/** Whether an Elementor element is an Atomic (V4) node. */
	public static function is_atomic( array $element ): bool {
		return str_starts_with( self::element_type( $element ), 'e-' );
	}
}
