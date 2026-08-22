<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Gutenberg;

/**
 * Maps a color value to a theme.json palette slug when the active theme
 * declares a matching preset. Hex is kept when no preset matches.
 */
final class ColorPresetLookup {

	public static function slug_for_hex( string $hex ): ?string {
		$want = self::normalize_hex( $hex );
		if ( null === $want ) {
			return null;
		}
		foreach ( self::palette() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$color = self::normalize_hex( (string) ( $entry['color'] ?? '' ) );
			$slug  = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
			if ( null !== $color && $color === $want && '' !== $slug ) {
				return $slug;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $attrs
	 * @return array<string, mixed>
	 */
	public static function apply_color( array $attrs, string $value, string $context = 'text' ): array {
		$value = trim( $value );
		if ( '' === $value ) {
			return $attrs;
		}

		$slug = null;
		if ( str_starts_with( $value, '#' ) ) {
			if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
				return $attrs;
			}
			$slug = self::slug_for_hex( $value );
		} else {
			$slug = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );
			if ( ! is_string( $slug ) || '' === $slug ) {
				return $attrs;
			}
		}

		if ( is_string( $slug ) && '' !== $slug ) {
			if ( 'background' === $context ) {
				$attrs['backgroundColor']              = $slug;
				$attrs['style']['color']['background'] = 'var:preset|color|' . $slug;
			} else {
				$attrs['textColor']              = $slug;
				$attrs['style']['color']['text'] = 'var:preset|color|' . $slug;
			}
			return $attrs;
		}

		if ( 'background' === $context ) {
			$attrs['style']['color']['background'] = $value;
		} else {
			$attrs['style']['color']['text'] = $value;
		}
		return $attrs;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private static function palette(): array {
		$settings = [];
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$loaded = wp_get_global_settings();
			if ( is_array( $loaded ) ) {
				$settings = $loaded;
			}
		}
		if ( isset( $GLOBALS['stonewright_test_global_settings'] ) && is_array( $GLOBALS['stonewright_test_global_settings'] ) ) {
			$settings = $GLOBALS['stonewright_test_global_settings'];
		}
		$palette = $settings['color']['palette'] ?? [];
		if ( ! is_array( $palette ) ) {
			return [];
		}
		if ( isset( $palette[0] ) && is_array( $palette[0] ) ) {
			return array_values( $palette );
		}
		$flat = [];
		foreach ( $palette as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $entry ) {
				if ( is_array( $entry ) ) {
					$flat[] = $entry;
				}
			}
		}
		return $flat;
	}

	private static function normalize_hex( string $hex ): ?string {
		$hex = strtolower( ltrim( trim( $hex ), '#' ) );
		if ( 3 === strlen( $hex ) && ctype_xdigit( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 8 === strlen( $hex ) && ctype_xdigit( $hex ) ) {
			$hex = substr( $hex, 0, 6 );
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return null;
		}
		return '#' . $hex;
	}
}
