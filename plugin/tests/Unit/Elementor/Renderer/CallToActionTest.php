<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Renderer;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\DesignTokens\Resolver;
use Stonewright\WpMcp\Elementor\Renderer\CallToAction;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Elementor\WidgetRegistry\WidgetCatalog;

/**
 * @covers \Stonewright\WpMcp\Elementor\Renderer\CallToAction
 */
final class CallToActionTest extends TestCase {

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

	public function test_cover_skin_defaults_min_height_radius_alignment(): void {
		// Default Elementor stub mirrors catalog widgets as live (Pro-present harness).
		$diagnostics = [];
		$result      = CallToAction::render(
			[
				'type'        => 'call-to-action',
				'skin'        => 'cover',
				'title'       => 'Book a consult',
				'description' => 'Native cover CTA',
				'button'      => 'Get started',
				'url'         => 'https://example.test/book',
				'image'       => [ 'url' => 'https://example.test/cover.jpg', 'id' => 7 ],
				'overlay'     => '#00000080',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertIsArray( $result );
		self::assertSame( 'call-to-action', $result['widgetType'] );
		self::assertSame( 'cover', $result['settings']['skin'] );
		self::assertSame( 320, $result['settings']['min-height']['size'] );
		self::assertSame( '20', $result['settings']['border_radius']['top'] );
		self::assertSame( 'center', $result['settings']['alignment'] );
		self::assertSame( 'middle', $result['settings']['vertical_position'] );
		self::assertSame( 'https://example.test/cover.jpg', $result['settings']['bg_image']['url'] );
		self::assertSame( '#00000080', $result['settings']['overlay_color'] );
		self::assertSame( [], $diagnostics );
	}

	public function test_catalog_alone_refuses_write_without_live_registration(): void {
		// Simulate Elementor Free: catalog still lists Pro CTA, live registry does not.
		self::assertTrue( WidgetCatalog::has( 'call-to-action' ) );
		$this->install_free_only_manager();

		$diagnostics = [];
		$result      = CallToAction::render(
			[
				'type'  => 'call-to-action',
				'skin'  => 'cover',
				'title' => 'Should not write',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertNotEmpty( $diagnostics );
		self::assertSame( CallToAction::ERROR_CODE, $diagnostics[0]['code'] );
		self::assertSame( 'widget_unavailable', $diagnostics[0]['reason'] );
		self::assertTrue( $diagnostics[0]['live_required'] );
		self::assertTrue( $diagnostics[0]['catalog_known'] );
	}

	public function test_never_substitutes_image_box_when_skin_invalid(): void {
		$diagnostics = [];
		$result      = CallToAction::render(
			[
				'type'  => 'call-to-action',
				'skin'  => 'hologram',
				'title' => 'Nope',
			],
			new Resolver( [] ),
			's0.b0',
			$diagnostics
		);

		self::assertNull( $result );
		self::assertNotEmpty( $diagnostics );
		self::assertSame( CallToAction::ERROR_CODE, $diagnostics[0]['code'] );
		self::assertSame( 'unsupported_skin', $diagnostics[0]['reason'] );
	}

	public function test_custom_min_height_and_radius(): void {
		$result = CallToAction::render(
			[
				'type'          => 'cta',
				'skin'          => 'cover',
				'title'         => 'CTA',
				'min_height'    => 400,
				'border_radius' => 24,
				'alignment'     => 'start',
			],
			new Resolver( [] ),
			's1.b0'
		);

		self::assertIsArray( $result );
		self::assertSame( 400, $result['settings']['min-height']['size'] );
		self::assertSame( '24', $result['settings']['border_radius']['left'] );
		self::assertSame( 'start', $result['settings']['alignment'] );
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
}
