<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Elementor;

/**
 * Normalizes Elementor container settings accepted by direct tree mutations.
 */
final class ContainerSettings {

	/**
	 * @var array<string, string>
	 */
	private const SAFE_ALIASES = [
		'justify_content' => 'flex_justify_content',
		'align_items'     => 'flex_align_items',
		'align_content'   => 'flex_align_content',
	];

	/**
	 * @param array<string, mixed> $settings
	 * @return array<string, mixed>
	 */
	public static function normalize( array $settings ): array {
		$layout    = isset( $settings['layout'] ) ? (string) $settings['layout'] : '';
		$direction = isset( $settings['direction'] ) ? (string) $settings['direction'] : '';

		unset( $settings['layout'], $settings['direction'] );

		foreach ( self::SAFE_ALIASES as $alias => $target ) {
			if ( ! array_key_exists( $alias, $settings ) ) {
				continue;
			}
			if ( ! array_key_exists( $target, $settings ) ) {
				$settings[ $target ] = $settings[ $alias ];
			}
			unset( $settings[ $alias ] );
		}

		if ( ! isset( $settings['container_type'] ) ) {
			$settings['container_type'] = 'grid' === $layout ? 'grid' : 'flex';
		}

		if ( 'grid' !== $settings['container_type'] && ! isset( $settings['flex_direction'] ) ) {
			$settings['flex_direction'] = self::is_row_direction( $direction, $layout ) ? 'row' : 'column';
		}

		return $settings;
	}

	/**
	 * @return array<string, string>
	 */
	public static function safe_aliases(): array {
		return self::SAFE_ALIASES;
	}

	/**
	 * @return list<string>
	 */
	public static function blocked_settings(): array {
		return [];
	}

	private static function is_row_direction( string $direction, string $layout ): bool {
		return in_array( $direction, [ 'row', 'horizontal' ], true ) || 'horizontal' === $layout;
	}
}
