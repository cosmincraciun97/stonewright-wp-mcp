<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * The markers an Elementor element leaves in its rendered HTML, by element kind.
 *
 * A V3 element and a V4 container print `data-id` and the `elementor-element-<id>` class. V4 tabs, background video and
 * form elements print `data-id` only. A V4 leaf widget (heading, paragraph, button, image, svg, divider) prints
 * `data-interaction-id` only, so a check that looks for the V3 class never finds it. The id is matched as a whole
 * token: `abc` is not found in `abcd`.
 */
final class RenderMarkers {

	private const DATA_ID          = 'data-id';
	private const INTERACTION_ID   = 'data-interaction-id';
	private const CLASS_TOKEN      = 'class';

	/** V4 elements that print their id in `data-id` and in the `elementor-element-<id>` class. */
	private const V4_CONTAINERS = [ 'e-div-block', 'e-flexbox', 'e-grid' ];

	/** Prefixes of V4 element families that print `data-id` and the class like a container. */
	private const V4_CONTAINER_PREFIXES = [ 'e-accordion', 'e-list' ];

	/** Prefixes of V4 element families that print `data-id` and no `elementor-element-<id>` class. */
	private const V4_DATA_ID_PREFIXES = [ 'e-tab', 'e-background-video', 'e-form' ];

	/** V4 widgets that print `data-interaction-id` and no `data-id`. */
	private const V4_LEAVES = [ 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider' ];

	/** V4 widgets that print `data-id` and `data-interaction-id`. */
	private const V4_MEDIA = [ 'e-youtube', 'e-self-hosted-video' ];

	/**
	 * The marker kinds that prove an element of this type was rendered, most specific first.
	 *
	 * @param string $type Widget type of a widget, element type otherwise; empty when the element is unknown.
	 * @return list<string> Any of data-id, data-interaction-id, class.
	 */
	public static function kinds_for( string $type ): array {
		if ( '' === $type || ! str_starts_with( $type, 'e-' ) ) {
			return [ self::DATA_ID, self::CLASS_TOKEN ];
		}
		if ( in_array( $type, self::V4_CONTAINERS, true ) || self::has_prefix( $type, self::V4_CONTAINER_PREFIXES ) ) {
			return [ self::DATA_ID, self::CLASS_TOKEN ];
		}
		if ( in_array( $type, self::V4_LEAVES, true ) ) {
			return [ self::INTERACTION_ID, self::DATA_ID ];
		}
		if ( in_array( $type, self::V4_MEDIA, true ) ) {
			return [ self::DATA_ID, self::INTERACTION_ID ];
		}
		if ( self::has_prefix( $type, self::V4_DATA_ID_PREFIXES ) ) {
			return [ self::DATA_ID ];
		}
		return [ self::DATA_ID, self::INTERACTION_ID, self::CLASS_TOKEN ];
	}

	/**
	 * Human-readable marker strings for the kinds of a type, as they appear in HTML.
	 *
	 * @return list<string>
	 */
	public static function labels( string $id, string $type ): array {
		$labels = [];
		foreach ( self::kinds_for( $type ) as $kind ) {
			$labels[] = self::label( $kind, $id );
		}
		return $labels;
	}

	/** The first marker of the type found in the HTML, or null when none is. */
	public static function find( string $html, string $id, string $type ): ?string {
		foreach ( self::kinds_for( $type ) as $kind ) {
			if ( self::matches( $html, $kind, $id ) ) {
				return self::label( $kind, $id );
			}
		}
		return null;
	}

	private static function label( string $kind, string $id ): string {
		return self::CLASS_TOKEN === $kind ? 'class~="elementor-element-' . $id . '"' : $kind . '="' . $id . '"';
	}

	private static function matches( string $html, string $kind, string $id ): bool {
		$quoted = preg_quote( $id, '/' );
		if ( self::CLASS_TOKEN === $kind ) {
			return 1 === preg_match( '/\bclass=(["\'])(?:(?!\1).)*?(?<=[\s"\'])elementor-element-' . $quoted . '(?=[\s"\'])(?:(?!\1).)*\1/s', $html );
		}
		return 1 === preg_match( '/(?<![\w-])' . preg_quote( $kind, '/' ) . '=(["\'])' . $quoted . '\1/', $html );
	}

	/** @param list<string> $prefixes */
	private static function has_prefix( string $type, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $type, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
