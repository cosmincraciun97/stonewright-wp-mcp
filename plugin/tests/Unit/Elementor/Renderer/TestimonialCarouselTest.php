<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\TestimonialCarousel;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * @covers \Stonewright\WpMcp\Elementor\Renderer\TestimonialCarousel
 */
final class TestimonialCarouselTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	public function test_selects_live_native_widget_and_maps_items(): void {
		// Default stub mirrors catalog as live — slides is available.
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

	public function test_catalog_only_pro_widgets_are_rejected(): void {
		// Free site: catalog still lists Pro carousel widgets, live registry does not.
		self::assertTrue( WidgetCatalog::has( 'slides' ) );
		$this->install_free_only_manager();

		$diagnostics = [];
		$result      = TestimonialCarousel::render(
			[
				'type'        => 'testimonial-carousel',
				'widget_type' => 'slides',
				'items'       => [
					[ 'name' => 'A', 'content' => 'x' ],
				],
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertSame( TestimonialCarousel::ERROR_CODE, $diagnostics[0]['code'] );
		self::assertTrue( $diagnostics[0]['live_required'] );
		self::assertContains( 'slides', $diagnostics[0]['candidates'] );
		self::assertContains( 'slides', $diagnostics[0]['catalog_known'] );
		self::assertNull( TestimonialCarousel::select_widget( [ 'slides', 'testimonial-carousel', 'reviews' ] ) );
		self::assertNull( TestimonialCarousel::select_widget( [ 'not-registered-at-all-zzz' ] ) );
	}

	public function test_live_but_incompatible_carousel_is_rejected(): void {
		$this->install_incompatible_slides_manager();
		self::assertNull( TestimonialCarousel::select_widget( [ 'slides' ] ) );

		$diagnostics = [];
		$result      = TestimonialCarousel::render(
			[
				'type'        => 'testimonial-carousel',
				'widget_type' => 'slides',
				'items'       => [ [ 'name' => 'A', 'content' => 'x' ] ],
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertSame( TestimonialCarousel::ERROR_CODE, $diagnostics[0]['code'] );
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

	/**
	 * Free-site harness: live manager has core widgets only — no catalog fallback.
	 */
	private function install_free_only_manager(): void {
		$base = $this->original_elementor;
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $base,
			[
				'widgets_manager' => new class() {
					public function get_widget_types( ?string $name = null ): array|object|null {
						$widgets = [
							'heading' => new class() {
								public function get_title(): string {
									return 'Heading';
								}

								/** @return list<string> */
								public function get_categories(): array {
									return [ 'basic' ];
								}

								/** @return list<string> */
								public function get_keywords(): array {
									return [ 'heading' ];
								}

								/** @return array<string, array<string, mixed>> */
								public function get_controls(): array {
									return [
										'title' => [
											'type'    => 'text',
											'tab'     => 'content',
											'section' => 'content',
										],
									];
								}
							},
						];
						if ( null === $name ) {
							return $widgets;
						}
						return $widgets[ $name ] ?? null;
					}
				},
			]
		);
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	private function install_incompatible_slides_manager(): void {
		$base = $this->original_elementor;
		\Elementor\Plugin::$instance = (object) array_merge(
			(array) $base,
			[
				'widgets_manager' => new class() {
					public function get_widget_types( ?string $name = null ): array|object|null {
						$widget = new class() {
							/** @return array<string, array<string, mixed>> */
							public function get_controls(): array {
								return [ 'slides' => [ 'type' => 'text', 'section' => 'content' ] ];
							}
						};
						return null === $name ? [ 'slides' => $widget ] : ( 'slides' === $name ? $widget : null );
					}
				},
			]
		);
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}
}
