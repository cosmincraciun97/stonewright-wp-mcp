<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * An Elementor that loads its controls for a front-end request keeps the style controls apart from the others:
 * `get_controls()` then lists only the content controls and the style controls sit in the stack. The schema
 * holds both, so a standard style setting is never refused as unknown.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\LiveControls
 * @covers \Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository
 * @covers \Stonewright\WpMcp\Elementor\Schema\ContainerSchemaRepository
 */
final class LiveStyleControlsTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$GLOBALS['stonewright_test_options']    = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients'] = [];
		WidgetSchemaRepository::reset_request_cache();
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager'  => new class() {
				/** @return array<string, object>|object|null */
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'title-card' => new StyleSplitElement( [ 'title' => [ 'type' => 'text', 'tab' => 'content' ] ], [ 'title_color' => [ 'type' => 'color', 'tab' => 'style' ], 'align' => [ 'type' => 'choose', 'tab' => 'style', 'options' => [ 'left' => [], 'center' => [] ] ] ] ) ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
			'elements_manager' => new class() {
				public function get_element_types( ?string $name = null ): array|object {
					$element = new StyleSplitElement( [ 'css_classes' => [ 'type' => 'text', 'tab' => 'advanced' ] ], [ 'background_color' => [ 'type' => 'color', 'tab' => 'style' ], 'border_radius' => [ 'type' => 'dimensions', 'tab' => 'style' ] ] );
					return null === $name ? [ 'container' => $element ] : $element;
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
		WidgetSchemaRepository::reset_request_cache();
	}

	public function test_the_widget_schema_holds_the_style_controls_the_stack_keeps_apart(): void {
		$schema = WidgetSchemaRepository::get( 'title-card' );

		self::assertIsArray( $schema );
		self::assertSame( [ 'align', 'title', 'title_color' ], array_keys( $schema['controls'] ) );
		self::assertSame( 'color', $schema['controls']['title_color']['type'] );
	}

	public function test_a_standard_style_setting_of_a_widget_is_not_refused_as_unknown(): void {
		$result = PatchValidator::widget( 'title-card', [], [ 'title' => 'Hello', 'title_color' => '#112233', 'align' => 'center' ], 'merge' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( '#112233', $result['settings']['title_color'] );
	}

	public function test_the_container_schema_holds_the_style_controls_the_stack_keeps_apart(): void {
		$schema = ContainerSchemaRepository::get( 'container' );

		self::assertIsArray( $schema );
		self::assertArrayHasKey( 'background_color', $schema['controls'] );
		self::assertArrayHasKey( 'border_radius', $schema['controls'] );
		self::assertArrayHasKey( 'css_classes', $schema['controls'] );
		$result = SettingsValidator::validate_container( [ 'background_color' => '#ffffff' ], 'container' );
		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
	}

	public function test_an_upgrade_drops_the_schemas_captured_before_it(): void {
		self::assertIsArray( WidgetSchemaRepository::get( 'title-card' ) );
		self::assertNotSame( [], $GLOBALS['stonewright_test_transients'], 'The schema was kept for later requests.' );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-upgrade';

		PluginRegistration::maybe_upgrade();

		self::assertSame( [], $GLOBALS['stonewright_test_transients'] );
	}
	public function test_a_key_neither_list_defines_is_still_unknown(): void {
		$result = PatchValidator::widget( 'title-card', [], [ 'title_colour' => '#112233' ], 'merge' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'unknown_setting_preserved', $result->get_error_data()['violations'][0]['code'] );
	}
}

/** An element whose stack splits its style controls from the rest. */
final class StyleSplitElement {

	/**
	 * @param array<string, array<string, mixed>> $content
	 * @param array<string, array<string, mixed>> $style
	 */
	public function __construct( private array $content, private array $style ) {}

	public function get_title(): string {
		return 'Title card';
	}

	/** @return list<string> */
	public function get_categories(): array {
		return [ 'basic' ];
	}

	/** @return array<string, array<string, mixed>> Only the content controls, as Elementor does for a front-end request. */
	public function get_controls(): array {
		return $this->content;
	}

	/** @return array{controls:array<string, array<string, mixed>>, style_controls:array<string, array<string, mixed>>, tabs:array<string, mixed>} */
	public function get_stack(): array {
		return [ 'controls' => $this->content, 'style_controls' => $this->style, 'tabs' => [] ];
	}
}
