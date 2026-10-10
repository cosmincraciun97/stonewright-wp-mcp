<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Provider;

use Elementor\Plugin;
use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Provider\WordPressNativeRuntime;

/**
 * @covers \Stonewright\WpMcp\Elementor\Provider\WordPressNativeRuntime
 */
final class WordPressNativeRuntimeTest extends TestCase {

	private object $original_instance;

	protected function setUp(): void {
		$this->original_instance = Plugin::$instance;
	}

	protected function tearDown(): void {
		Plugin::$instance = $this->original_instance;
	}

	public function test_atomic_types_are_the_registered_e_prefixed_layouts_and_widgets_only(): void {
		Plugin::$instance = (object) [
			'elements_manager' => new class() {
				/** @return array<string,object> */
				public function get_element_types(): array {
					return [ 'section' => new \stdClass(), 'container' => new \stdClass(), 'e-div-block' => new \stdClass(), 'e-flexbox' => new \stdClass() ];
				}
			},
			'widgets_manager'  => new class() {
				/** @return array<string,object> */
				public function get_widget_types(): array {
					return [ 'heading' => new \stdClass(), 'e-heading' => new \stdClass(), 'e-paragraph' => new \stdClass(), 'e-div-block' => new \stdClass() ];
				}
			},
		];

		self::assertSame( [ 'e-div-block', 'e-flexbox', 'e-heading', 'e-paragraph' ], ( new WordPressNativeRuntime() )->atomic_types() );
	}

	public function test_atomic_types_are_empty_when_elementor_exposes_no_managers(): void {
		Plugin::$instance = (object) [];

		self::assertSame( [], ( new WordPressNativeRuntime() )->atomic_types() );
	}

	public function test_a_missing_abilities_api_or_ability_is_a_structured_error_not_a_fatal(): void {
		$result = ( new WordPressNativeRuntime() )->execute( 'elementor/does-not-exist', [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertContains( $result->get_error_code(), [ 'stonewright_native_abilities_api_missing', 'stonewright_native_ability_missing' ] );
	}
}
