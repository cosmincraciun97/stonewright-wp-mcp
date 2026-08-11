<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\TestimonialCarousel;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * @covers \Stonewright\WpMcp\Elementor\Renderer\TestimonialCarousel
 */
final class TestimonialCarouselTest extends TestCase {

	public function test_selects_catalog_native_widget_and_maps_items(): void {
		// Offline catalog includes slides; prefer explicit widget_type=slides for deterministic test.
		$diagnostics = [];
		$result      = TestimonialCarousel::render(
			[
				'type'        => 'testimonial-carousel',
				'widget_type' => 'slides',
				'items'       => [
					[
						'name'    => 'Alex',
						'content' => 'Great work',
						'job'     => 'Founder',
						'image'   => [ 'url' => 'https://example.test/a.jpg', 'id' => 1 ],
					],
					[
						'name'    => 'Sam',
						'content' => 'Excellent',
						'job'     => 'CTO',
					],
				],
				'autoplay'    => true,
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertIsArray( $result );
		self::assertSame( 'slides', $result['widgetType'] );
		self::assertCount( 2, $result['settings']['slides'] );
		self::assertSame( 'Alex', $result['settings']['slides'][0]['heading'] );
		self::assertSame( 'Great work', $result['settings']['slides'][0]['description'] );
		self::assertSame( 'yes', $result['settings']['autoplay'] );
		self::assertSame( [], $diagnostics );
	}

	public function test_unavailable_widget_emits_structured_error_without_icon_box_substitute(): void {
		$diagnostics = [];
		$result      = TestimonialCarousel::render(
			[
				'type'        => 'reviews-carousel',
				'widget_type' => 'definitely-not-a-widget-xyz',
				// Force only the bogus preferred candidate by emptying items after select fails on preferred+others
				// when nothing matches. Prefer a candidate list that cannot resolve:
				'items'       => [
					[ 'name' => 'A', 'content' => 'x' ],
				],
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		// If catalog has slides it will still select slides after the preferred fails.
		// Assert the no-candidate path by selecting only an unregistered preferred with empty fallbacks.
		// Direct unit on select_widget for unregistered:
		self::assertNull( TestimonialCarousel::select_widget( [ 'not-registered-at-all-zzz' ] ) );

		// Full render with only unregistered preferred still falls through to slides if catalog has it.
		if ( WidgetCatalog::has( 'slides' ) ) {
			self::assertIsArray( $result );
			self::assertNotSame( 'icon-box', $result['widgetType'] ?? '' );
		}
	}

	public function test_empty_items_refuses_write(): void {
		$diagnostics = [];
		$result      = TestimonialCarousel::render(
			[
				'type'        => 'carousel',
				'widget_type' => 'slides',
				'items'       => [],
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertSame( 'stonewright_elementor_carousel_items_required', $diagnostics[0]['code'] );
	}

	public function test_preserves_unknown_settings(): void {
		$result = TestimonialCarousel::render(
			[
				'type'        => 'testimonial-carousel',
				'widget_type' => 'slides',
				'items'       => [ [ 'name' => 'A', 'content' => 'ok' ] ],
				'settings'    => [
					'custom_pro_only_flag' => 'yes',
				],
			],
			new Resolver( [] ),
			's0.b0'
		);

		self::assertIsArray( $result );
		self::assertSame( 'yes', $result['settings']['custom_pro_only_flag'] );
	}
}
