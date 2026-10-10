<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;

/**
 * The structural elements validate their own controls: a legacy section keeps its native `gap`, and the CSS class
 * control is `css_classes` on a container, section and column (`_css_classes` is the widget control).
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsKeyAliases
 */
final class StructuralControlKeysTest extends TestCase {

	private object $original_elementor;

	/** Serves the controls of a booted Elementor for the three structural elements. */
	private function use_live_structural_controls(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		$base                     = $this->original_elementor;
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager'  => $base->widgets_manager,
			'elements_manager' => new class() {
				public function get_element_types( ?string $name = null ): array|object {
					$common = [
						'_element_id' => [ 'type' => 'text', 'tab' => 'advanced', 'section' => 'advanced', 'responsive' => [] ],
						'css_classes' => [ 'type' => 'text', 'tab' => 'advanced', 'section' => 'advanced', 'responsive' => [] ],
					];
					$by_type = [
						'section'   => $common + [
							'gap'         => [ 'type' => 'select', 'tab' => 'layout', 'section' => 'section_layout', 'responsive' => [], 'options' => [ 'default' => 'Default', 'no' => 'No Gap', 'narrow' => 'Narrow' ] ],
							'content_position' => [ 'type' => 'select', 'tab' => 'layout', 'section' => 'section_layout', 'responsive' => [], 'options' => [ '' => 'Default', 'top' => 'Top', 'middle' => 'Middle' ] ],
						],
						'column'    => $common + [
							'_inline_size' => [ 'type' => 'number', 'tab' => 'layout', 'section' => 'layout', 'responsive' => [] ],
						],
						'container' => $common + [
							'flex_gap'  => [ 'type' => 'gaps', 'tab' => 'layout', 'section' => 'section_layout_container', 'responsive' => [ 'max' => 'desktop' ] ],
						],
					];
					$element = static fn( string $type ): object => new class( $by_type[ $type ] ) {
						/** @param array<string, array<string, mixed>> $controls */
						public function __construct( private array $controls ) {}
						/** @return array<string, array<string, mixed>> */
						public function get_controls(): array {
							return $this->controls;
						}
					};
					if ( null === $name ) {
						return [ 'section' => $element( 'section' ), 'column' => $element( 'column' ), 'container' => $element( 'container' ) ];
					}

					return $element( $name );
				}
			},
		];
	}

	protected function tearDown(): void {
		if ( isset( $this->original_elementor ) ) {
			\Elementor\Plugin::$instance = $this->original_elementor;
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_a_legacy_section_keeps_its_native_gap_control(): void {
		$this->use_live_structural_controls();

		$result = SettingsValidator::validate_container( [ 'gap' => 'no', 'content_position' => 'middle' ], 'section' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( 'no', $result['settings']['gap'] );
		self::assertArrayNotHasKey( 'flex_gap', $result['settings'] );
		self::assertSame( [], array_values( array_filter( $result['warnings'], static fn( array $warning ): bool => 'settings_alias_applied' === $warning['code'] ) ) );
	}

	public function test_a_patch_on_a_legacy_section_keeps_its_native_gap_control(): void {
		$this->use_live_structural_controls();

		$result = PatchValidator::container( [], [ 'gap' => 'narrow' ], 'section', 'merge' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertSame( [ 'gap' => 'narrow' ], $result['settings'] );
	}

	public function test_a_container_still_maps_the_short_gap_name_to_its_flex_control(): void {
		$this->use_live_structural_controls();

		$result = SettingsValidator::validate_container( [ 'gap' => [ 'column' => '8', 'row' => '8', 'unit' => 'px', 'isLinked' => true ] ], 'container' );

		self::assertIsArray( $result, $result instanceof \WP_Error ? wp_json_encode( $result->get_error_data() ) : '' );
		self::assertArrayHasKey( 'flex_gap', $result['settings'] );
		self::assertArrayNotHasKey( 'gap', $result['settings'] );
	}

	public function test_the_css_class_control_is_css_classes_on_every_structural_element(): void {
		$this->use_live_structural_controls();

		foreach ( [ 'container', 'section', 'column' ] as $type ) {
			$known = SettingsValidator::validate_container( [ 'css_classes' => 'band' ], $type );
			self::assertIsArray( $known, $type . ': ' . ( $known instanceof \WP_Error ? wp_json_encode( $known->get_error_data() ) : '' ) );
			self::assertSame( 'band', $known['settings']['css_classes'] );

			$widget_key = SettingsValidator::validate_container( [ '_css_classes' => 'band' ], $type, true, true );
			self::assertIsArray( $widget_key );
			self::assertSame( 'unknown_setting_preserved', $widget_key['warnings'][0]['code'], '`_css_classes` is the widget control, not a container one.' );
		}
	}

	public function test_the_offline_schema_lists_css_classes_for_a_container(): void {
		$result = SettingsValidator::validate_container( [ 'css_classes' => 'band' ], 'container' );

		self::assertIsArray( $result );
		self::assertSame( 'band', $result['settings']['css_classes'] );
	}
}
