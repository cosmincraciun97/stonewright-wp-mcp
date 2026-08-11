<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

use Stonewright\WpMcp\Elementor\Renderer\Responsive;
use Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases;

/**
 * Canonical Elementor container layout normalization.
 *
 * Semantic intents accepted by DesignSpec / direct tree mutations:
 * - `row` and legacy `horizontal` → flex + flex_direction row
 * - `stack` and legacy `vertical` → flex + flex_direction column
 * - `grid` → container_type grid
 * - `flex` alone keeps/sets flex without inventing a direction override
 *
 * Breakpoint overrides are applied independently via Responsive keys
 * (`flex_direction_tablet`, `flex_direction_mobile`, …). Unrelated
 * breakpoints are never rewritten.
 */
final class LayoutNormalizer {

	private const ROW_ALIASES = [ 'row', 'horizontal' ];

	private const COLUMN_ALIASES = [ 'stack', 'vertical', 'column' ];

	/**
	 * Normalize inbound container settings (mutation path).
	 *
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function normalize_settings( array $settings ): array {
		$layout    = array_key_exists( 'layout', $settings ) ? $settings['layout'] : null;
		$direction = array_key_exists( 'direction', $settings ) ? $settings['direction'] : null;

		unset( $settings['layout'], $settings['direction'] );

		$settings = SettingsKeyAliases::normalize( $settings )['settings'];

		// Responsive layout maps: layout: { desktop: row, tablet: stack }.
		if ( is_array( $layout ) && self::is_viewport_map( $layout ) ) {
			$resolved = self::resolve_layout_map( $layout );
			if ( null !== $resolved['container_type'] && ! isset( $settings['container_type'] ) ) {
				$settings['container_type'] = $resolved['container_type'];
			}
			if ( null !== $resolved['flex_direction'] ) {
				$settings = Responsive::apply( $settings, 'flex_direction', $resolved['flex_direction'] );
			}
			$layout = null;
		}

		// Scalar layout intent.
		if ( is_string( $layout ) && '' !== $layout ) {
			$intent = self::resolve_layout_intent( $layout );
			if ( null !== $intent['container_type'] && ! isset( $settings['container_type'] ) ) {
				$settings['container_type'] = $intent['container_type'];
			}
			if ( null !== $intent['flex_direction'] && ! isset( $settings['flex_direction'] ) ) {
				// Only set default direction when no responsive direction follows.
				if ( ! is_array( $direction ) || ! self::is_viewport_map( $direction ) ) {
					$settings['flex_direction'] = $intent['flex_direction'];
				}
			}
		}

		// Responsive direction maps: direction: { desktop: row, mobile: column }.
		if ( is_array( $direction ) && self::is_viewport_map( $direction ) ) {
			$normalized = [];
			foreach ( $direction as $bp => $value ) {
				$resolved = self::resolve_direction( (string) $value );
				if ( null !== $resolved ) {
					$normalized[ $bp ] = $resolved;
				}
			}
			if ( [] !== $normalized ) {
				$settings = Responsive::apply( $settings, 'flex_direction', $normalized );
			}
			if ( ! isset( $settings['container_type'] ) ) {
				$settings['container_type'] = 'flex';
			}
			$direction = null;
		}

		if ( is_string( $direction ) && '' !== $direction ) {
			$resolved = self::resolve_direction( $direction );
			if ( null !== $resolved && ! isset( $settings['flex_direction'] ) ) {
				$settings['flex_direction'] = $resolved;
			}
			if ( ! isset( $settings['container_type'] ) ) {
				$settings['container_type'] = 'flex';
			}
		}

		if ( ! isset( $settings['container_type'] ) || '' === (string) $settings['container_type'] ) {
			$settings['container_type'] = 'flex';
		}

		if ( 'grid' !== $settings['container_type'] && ! isset( $settings['flex_direction'] ) ) {
			// Preserve tablet/mobile-only direction overrides without inventing desktop.
			$has_responsive = isset( $settings['flex_direction_tablet'] ) || isset( $settings['flex_direction_mobile'] );
			if ( ! $has_responsive ) {
				$settings['flex_direction'] = 'column';
			}
		}

		return $settings;
	}

	/**
	 * Resolve a DesignSpec layout + direction pair for renderers.
	 *
	 * @param mixed $layout
	 * @param mixed $direction
	 * @return array{container_type: string, flex_direction: string|array<string, string>|null}
	 */
	public static function for_spec( mixed $layout = null, mixed $direction = null ): array {
		$container_type = 'flex';
		$flex_direction = null;

		if ( is_array( $layout ) && self::is_viewport_map( $layout ) ) {
			$resolved = self::resolve_layout_map( $layout );
			$container_type = $resolved['container_type'] ?? 'flex';
			$flex_direction = $resolved['flex_direction'];
		} elseif ( is_string( $layout ) && '' !== $layout ) {
			$intent         = self::resolve_layout_intent( $layout );
			$container_type = $intent['container_type'] ?? 'flex';
			$flex_direction = $intent['flex_direction'];
		}

		if ( is_array( $direction ) && self::is_viewport_map( $direction ) ) {
			$normalized = [];
			foreach ( $direction as $bp => $value ) {
				$resolved = self::resolve_direction( (string) $value );
				if ( null !== $resolved ) {
					$normalized[ $bp ] = $resolved;
				}
			}
			if ( [] !== $normalized ) {
				$flex_direction = $normalized;
			}
			$container_type = 'grid' === $container_type ? 'grid' : 'flex';
		} elseif ( is_string( $direction ) && '' !== $direction ) {
			$resolved = self::resolve_direction( $direction );
			if ( null !== $resolved ) {
				$flex_direction = $resolved;
			}
			$container_type = 'grid' === $container_type ? 'grid' : 'flex';
		}

		if ( 'grid' !== $container_type && null === $flex_direction ) {
			$flex_direction = 'column';
		}

		return [
			'container_type'  => $container_type,
			'flex_direction'  => $flex_direction,
		];
	}

	/**
	 * Apply a possibly-responsive flex_direction value onto settings.
	 *
	 * @param array<string, mixed> $settings
	 * @param mixed                $direction
	 * @return array<string, mixed>
	 */
	public static function apply_direction( array $settings, mixed $direction ): array {
		if ( null === $direction ) {
			return $settings;
		}
		if ( is_array( $direction ) && self::is_viewport_map( $direction ) ) {
			$normalized = [];
			foreach ( $direction as $bp => $value ) {
				$resolved = self::resolve_direction( is_string( $value ) ? $value : (string) $value );
				if ( null !== $resolved ) {
					$normalized[ $bp ] = $resolved;
				}
			}
			return [] === $normalized ? $settings : Responsive::apply( $settings, 'flex_direction', $normalized );
		}

		$resolved = self::resolve_direction( is_string( $direction ) ? $direction : (string) $direction );
		if ( null === $resolved ) {
			return $settings;
		}

		return Responsive::apply( $settings, 'flex_direction', $resolved );
	}

	/**
	 * Lightweight parent/child layout validation before mutation.
	 *
	 * @param array<string, mixed> $parent_settings
	 * @param array<string, mixed> $child_settings
	 * @return list<array<string, mixed>>
	 */
	public static function validate_nested( array $parent_settings, array $child_settings ): array {
		$diagnostics = [];
		$parent_dir  = (string) ( $parent_settings['flex_direction'] ?? 'column' );
		$child_type  = (string) ( $child_settings['container_type'] ?? 'flex' );

		// Nested grid inside a non-grid parent is valid; only flag absurd combos.
		if ( 'row' === $parent_dir ) {
			$width = $child_settings['width'] ?? null;
			if ( is_array( $width ) && '%' === ( $width['unit'] ?? null ) && is_numeric( $width['size'] ?? null ) ) {
				$size = (float) $width['size'];
				if ( $size > 100.0 ) {
					$diagnostics[] = [
						'code'    => 'layout_child_width_overflow',
						'message' => 'Child width exceeds 100% inside a row parent.',
						'size'    => $size,
					];
				}
			}
		}

		if ( 'grid' === $child_type && 'grid' === ( $parent_settings['container_type'] ?? '' ) ) {
			// Allowed but worth noting for agents that confuse nested grids.
			$diagnostics[] = [
				'code'    => 'layout_nested_grid',
				'message' => 'Nested grid containers are valid; confirm columns/rows on both levels.',
			];
		}

		return $diagnostics;
	}

	/**
	 * @return array{container_type:?string,flex_direction:?string}
	 */
	public static function resolve_layout_intent( string $layout ): array {
		$layout = strtolower( trim( $layout ) );

		if ( 'grid' === $layout ) {
			return [ 'container_type' => 'grid', 'flex_direction' => null ];
		}

		if ( in_array( $layout, self::ROW_ALIASES, true ) ) {
			return [ 'container_type' => 'flex', 'flex_direction' => 'row' ];
		}

		if ( in_array( $layout, self::COLUMN_ALIASES, true ) ) {
			return [ 'container_type' => 'flex', 'flex_direction' => 'column' ];
		}

		if ( 'flex' === $layout ) {
			return [ 'container_type' => 'flex', 'flex_direction' => null ];
		}

		// Unknown scalar — treat as flex column without inventing aliases.
		return [ 'container_type' => 'flex', 'flex_direction' => null ];
	}

	public static function resolve_direction( string $direction ): ?string {
		$direction = strtolower( trim( $direction ) );
		if ( in_array( $direction, self::ROW_ALIASES, true ) || 'row' === $direction ) {
			return 'row';
		}
		if ( in_array( $direction, self::COLUMN_ALIASES, true ) || 'column' === $direction ) {
			return 'column';
		}
		if ( in_array( $direction, [ 'row-reverse', 'column-reverse' ], true ) ) {
			return $direction;
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $map
	 * @return array{container_type:?string,flex_direction:array<string,string>|null}
	 */
	private static function resolve_layout_map( array $map ): array {
		$container_type = null;
		$directions     = [];

		foreach ( $map as $bp => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$intent = self::resolve_layout_intent( $value );
			if ( null !== $intent['container_type'] ) {
				// Desktop (or first) wins for container_type; Elementor has no per-bp container_type.
				if ( null === $container_type || 'desktop' === $bp ) {
					$container_type = $intent['container_type'];
				}
			}
			if ( null !== $intent['flex_direction'] ) {
				$directions[ $bp ] = $intent['flex_direction'];
			}
		}

		return [
			'container_type' => $container_type,
			'flex_direction' => [] === $directions ? null : $directions,
		];
	}

	/**
	 * @param array<mixed> $value
	 */
	private static function is_viewport_map( array $value ): bool {
		foreach ( array_keys( $value ) as $key ) {
			if ( is_string( $key ) && in_array( $key, [ 'desktop', 'tablet', 'mobile' ], true ) ) {
				return true;
			}
		}

		return false;
	}
}
