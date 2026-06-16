<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use Elementor\Plugin;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\ElementorV3\Status as V3Status;
use Stonewright\WpMcp\Abilities\ElementorV4\Status as V4Status;

/**
 * @covers \Stonewright\WpMcp\Abilities\ElementorV3\Status
 * @covers \Stonewright\WpMcp\Abilities\ElementorV4\Status
 */
final class ElementorStatusDiscoveryTest extends TestCase {

	private object $original_instance;

	protected function setUp(): void {
		$this->original_instance = Plugin::$instance;
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_elementor_v4_atomic' => true,
		];
	}

	protected function tearDown(): void {
		Plugin::$instance = $this->original_instance;
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_v3_status_reports_widget_inventory_and_v4_flag(): void {
		Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/**
				 * @return array<string, object>|object|null
				 */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [
						'heading' => new class() {
							public function get_title(): string {
								return 'Heading';
							}
						},
						'image'   => new class() {
							public function get_title(): string {
								return 'Image';
							}
						},
					];

					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];

		$result = ( new V3Status() )->execute( [] );

		self::assertIsArray( $result );
		self::assertSame( [ 'heading', 'image' ], $result['active_widget_types'] );
		self::assertSame( [ 'button', 'container', 'icon', 'icon-box', 'icon-list', 'text-editor' ], $result['unsupported_widgets'] );
		self::assertTrue( $result['v4_atomic_enabled'] );
		self::assertFalse( $result['v4_write_ready'] );
		self::assertSame( 'elementor-v3-native', $result['recommended_renderer'] );
		self::assertSame( 'Use Elementor V3 native-widget tools until V4 atomic support is both available and enabled.', $result['agent_action'] );
		self::assertArrayHasKey( 'pro_elements_active', $result );
	}

	public function test_v4_status_reports_elementor_and_widget_discovery_context(): void {
		Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				/**
				 * @return array<string, object>|object|null
				 */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [
						'heading' => new class() {},
					];

					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];

		$result = ( new V4Status() )->execute( [] );

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'elementor_version', $result );
		self::assertArrayHasKey( 'pro_elements_active', $result );
		self::assertSame( [ 'heading' ], $result['active_widget_types'] );
		self::assertContains( 'text-editor', $result['unsupported_widgets'] );
		self::assertSame( 'enabled-but-unavailable', $result['v4_atomic_support_status'] );
		self::assertFalse( $result['v4_write_ready'] );
		self::assertSame( 'elementor-v3-native', $result['recommended_renderer'] );
		self::assertSame( 'Use Elementor V3 native-widget tools until V4 atomic support is both available and enabled.', $result['agent_action'] );
	}
}
