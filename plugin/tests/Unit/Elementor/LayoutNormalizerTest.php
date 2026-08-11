<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\ContainerSettings;
use Stonewright\WpMcp\Elementor\LayoutNormalizer;

/**
 * @covers \Stonewright\WpMcp\Elementor\LayoutNormalizer
 * @covers \Stonewright\WpMcp\Elementor\ContainerSettings
 */
final class LayoutNormalizerTest extends TestCase {

	public function test_row_and_horizontal_become_flex_row(): void {
		foreach ( [ 'row', 'horizontal' ] as $layout ) {
			$settings = LayoutNormalizer::normalize_settings( [ 'layout' => $layout ] );
			self::assertSame( 'flex', $settings['container_type'], $layout );
			self::assertSame( 'row', $settings['flex_direction'], $layout );
			self::assertArrayNotHasKey( 'layout', $settings );
		}
	}

	public function test_stack_and_vertical_become_flex_column(): void {
		foreach ( [ 'stack', 'vertical', 'column' ] as $layout ) {
			$settings = LayoutNormalizer::normalize_settings( [ 'layout' => $layout ] );
			self::assertSame( 'flex', $settings['container_type'], $layout );
			self::assertSame( 'column', $settings['flex_direction'], $layout );
		}
	}

	public function test_grid_layout(): void {
		$settings = LayoutNormalizer::normalize_settings( [ 'layout' => 'grid' ] );
		self::assertSame( 'grid', $settings['container_type'] );
		self::assertArrayNotHasKey( 'flex_direction', $settings );
	}

	public function test_breakpoint_direction_overrides_are_independent(): void {
		$settings = LayoutNormalizer::normalize_settings(
			[
				'layout'    => 'row',
				'direction' => [
					'desktop' => 'row',
					'tablet'  => 'column',
					'mobile'  => 'column',
				],
			]
		);

		self::assertSame( 'flex', $settings['container_type'] );
		self::assertSame( 'row', $settings['flex_direction'] );
		self::assertSame( 'column', $settings['flex_direction_tablet'] );
		self::assertSame( 'column', $settings['flex_direction_mobile'] );
	}

	public function test_responsive_layout_map_preserves_unrelated_breakpoints(): void {
		// Only tablet override provided — desktop/mobile stay unset unless mapped.
		$settings = LayoutNormalizer::normalize_settings(
			[
				'layout' => [
					'desktop' => 'row',
					'tablet'  => 'stack',
				],
			]
		);

		self::assertSame( 'flex', $settings['container_type'] );
		self::assertSame( 'row', $settings['flex_direction'] );
		self::assertSame( 'column', $settings['flex_direction_tablet'] );
		self::assertArrayNotHasKey( 'flex_direction_mobile', $settings );
	}

	public function test_for_spec_two_column_hero_intent(): void {
		$intent = LayoutNormalizer::for_spec( 'row', null );
		self::assertSame( 'flex', $intent['container_type'] );
		self::assertSame( 'row', $intent['flex_direction'] );

		$responsive = LayoutNormalizer::for_spec(
			[ 'desktop' => 'row', 'tablet' => 'stack', 'mobile' => 'stack' ],
			null
		);
		self::assertSame( 'flex', $responsive['container_type'] );
		self::assertSame(
			[ 'desktop' => 'row', 'tablet' => 'column', 'mobile' => 'column' ],
			$responsive['flex_direction']
		);
	}

	public function test_container_settings_delegates_and_validates_nested(): void {
		$parent = ContainerSettings::normalize( [ 'layout' => 'row' ] );
		$child  = ContainerSettings::normalize( [ 'layout' => 'stack', 'width' => [ 'unit' => '%', 'size' => 150 ] ] );
		$diag   = ContainerSettings::validate_nested( $parent, $child );

		self::assertSame( 'row', $parent['flex_direction'] );
		self::assertNotEmpty( $diag );
		self::assertSame( 'layout_child_width_overflow', $diag[0]['code'] );
	}

	public function test_unknown_settings_aliases_still_normalized(): void {
		$settings = ContainerSettings::normalize(
			[
				'layout'          => 'horizontal',
				'justify_content' => 'center',
				'gap'             => 24,
			]
		);
		self::assertSame( 'row', $settings['flex_direction'] );
		self::assertSame( 'center', $settings['flex_justify_content'] );
		self::assertSame( 24, $settings['flex_gap'] );
	}

	public function test_unknown_breakpoint_key_is_not_expanded_into_elementor_settings(): void {
		$settings = LayoutNormalizer::normalize_settings(
			[
				'layout' => [
					'desktop' => 'row',
					'watch'   => 'row',
				],
			]
		);

		self::assertSame( 'column', $settings['flex_direction'] );
		self::assertArrayNotHasKey( 'flex_direction_watch', $settings );
	}
}
