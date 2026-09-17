<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Loop;

/**
 * Synthetic Elementor Pro 4.2-style Loop Grid / Loop Carousel widgets.
 *
 * Loaded from tests/fixtures/elementor/*.json — no client templates.
 */
final class SyntheticLoopWidgets {

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function grid_controls(): array {
		return self::load( 'loop-grid-controls.json' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function carousel_controls(): array {
		return self::load( 'loop-carousel-controls.json' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function load( string $filename ): array {
		$path = dirname( __DIR__, 3 ) . '/fixtures/elementor/' . $filename;
		$raw  = file_get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		$controls = is_array( $decoded['controls'] ?? null ) ? $decoded['controls'] : [];
		$overrides = is_array( $GLOBALS['stonewright_test_loop_control_overrides'] ?? null )
			? $GLOBALS['stonewright_test_loop_control_overrides']
			: [];
		$widget = str_starts_with( $filename, 'loop-carousel' ) ? 'loop-carousel' : 'loop-grid';
		if ( isset( $overrides[ $widget ] ) && is_array( $overrides[ $widget ] ) ) {
			$controls = $overrides[ $widget ];
		} elseif ( isset( $overrides['*'] ) && is_callable( $overrides['*'] ) ) {
			$controls = $overrides['*']( $controls, $widget );
		}

		return $controls;
	}
}

final class SyntheticLoopWidgetManager {
	public function get_widget_types( ?string $name = null ): array|object|null {
		$widgets = [
			'loop-carousel' => new SyntheticLoopCarouselWidget(),
			'loop-grid'     => new SyntheticLoopGridWidget(),
		];

		return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
	}
}

class SyntheticLoopCarouselWidget {
	public function get_title(): string {
		return 'Loop Carousel';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return SyntheticLoopWidgets::carousel_controls();
	}
}

class SyntheticLoopGridWidget {
	public function get_title(): string {
		return 'Loop Grid';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'pro-elements' ];
	}

	/** @return array<string, array<string, mixed>> */
	public function get_controls(): array {
		return SyntheticLoopWidgets::grid_controls();
	}
}
