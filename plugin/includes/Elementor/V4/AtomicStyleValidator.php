<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor\V4;

/** Validates the documented Atomic global-class shape. */
final class AtomicStyleValidator {
	/** @param array<string, mixed> $style */
	public static function validate( array $style ): bool|\WP_Error {
		if ( empty( $style['id'] ) || empty( $style['label'] ) || 'class' !== ( $style['type'] ?? '' ) || ! isset( $style['variants'] ) || ! is_array( $style['variants'] ) ) {
			return new \WP_Error( 'stonewright_v4_invalid_class', 'Class requires id, label, type=class, and variants.' );
		}
		$seen = [];
		foreach ( $style['variants'] as $index => $variant ) {
			if ( ! is_array( $variant ) || ! isset( $variant['meta']['breakpoint'], $variant['props'] ) || ! is_array( $variant['props'] ) ) {
				return new \WP_Error( 'stonewright_v4_invalid_class_variant', 'Each class variant requires meta.breakpoint and props.', [ 'index' => $index ] );
			}
			$key = (string) $variant['meta']['breakpoint'] . ':' . (string) ( $variant['meta']['state'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				return new \WP_Error( 'stonewright_v4_duplicate_class_variant', 'Duplicate breakpoint/state variant.', [ 'index' => $index ] );
			}
			$seen[ $key ] = true;
		}
		return true;
	}
}
