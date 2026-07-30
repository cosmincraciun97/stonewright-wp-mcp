<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\Renderer;

/**
 * Apply a possibly-responsive value to an Elementor V3 settings array.
 * Elementor stores responsive variants under `<key>_tablet` and `<key>_mobile`.
 */
final class Responsive {

    private const ALLOWED_BREAKPOINTS = [ 'desktop', 'tablet', 'mobile' ];

    /**
     * @param array<string, mixed> $settings
     * @param mixed                $value
     * @return array<string, mixed>
     */
    public static function apply( array $settings, string $key, $value ): array {
        if ( ! is_array( $value ) ) {
            $settings[ $key ] = $value;
            return $settings;
        }
        if ( ! self::is_viewport_array( $value ) ) {
            $settings[ $key ] = $value;
            return $settings;
        }
        foreach ( $value as $bp => $bp_value ) {
            if ( ! in_array( $bp, self::ALLOWED_BREAKPOINTS, true ) ) {
                continue;
            }
            $suffix = ( 'desktop' === $bp ) ? '' : '_' . $bp;
            $settings[ $key . $suffix ] = $bp_value;
        }
        return $settings;
    }

    /**
     * @param array<mixed> $value
     */
    private static function is_viewport_array( array $value ): bool {
        foreach ( array_keys( $value ) as $key ) {
            if ( is_string( $key ) && in_array( $key, self::ALLOWED_BREAKPOINTS, true ) ) {
                return true;
            }
        }
        return false;
    }
}
