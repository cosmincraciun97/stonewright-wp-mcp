<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Normalizes Elementor container settings accepted by direct tree mutations.
 */
final class ContainerSettings {

	/**
	 * Settings that commonly come from agent guesses and create broken flex output.
	 *
	 * @var array<int, string>
	 */
	private const BLOCKED_FLEX_KEYS = [
		'flex_wrap',
		'_flex_size',
		'_flex_grow',
		'_flex_shrink',
	];

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function normalize( array $settings ): array {
		$layout    = isset( $settings['layout'] ) ? (string) $settings['layout'] : '';
		$direction = isset( $settings['direction'] ) ? (string) $settings['direction'] : '';

		unset( $settings['layout'], $settings['direction'] );

		foreach ( self::BLOCKED_FLEX_KEYS as $key ) {
			unset( $settings[ $key ] );
		}

		if ( ! isset( $settings['container_type'] ) ) {
			$settings['container_type'] = 'grid' === $layout ? 'grid' : 'flex';
		}

		if ( 'grid' !== $settings['container_type'] && ! isset( $settings['flex_direction'] ) ) {
			$settings['flex_direction'] = self::is_row_direction( $direction, $layout ) ? 'row' : 'column';
		}

		return $settings;
	}

	private static function is_row_direction( string $direction, string $layout ): bool {
		return in_array( $direction, [ 'row', 'horizontal' ], true ) || 'horizontal' === $layout;
	}
}
