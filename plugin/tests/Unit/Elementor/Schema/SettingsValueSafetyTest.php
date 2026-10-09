<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;

/**
 * Colour, typography, unit and shadow values reach generated CSS, so the
 * validator accepts only real values for those controls.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 */
final class SettingsValueSafetyTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = \Elementor\Plugin::$instance;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
		\Elementor\Plugin::$instance = (object) [
			'widgets_manager' => new class() {
				public function get_widget_types( ?string $name = null ): array|object|null {
					$widgets = [ 'safety-widget' => new SafetyWidgetForTest() ];
					return null === $name ? $widgets : ( $widgets[ $name ] ?? null );
				}
			},
		];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/** @return array<string, array{0:mixed}> */
	public static function invalid_colors(): array {
		return [
			'css breakout'        => [ 'red;}body{display:none}' ],
			'not a colour'        => [ 'not-a-color' ],
			'javascript scheme'   => [ 'javascript:alert(1)' ],
			'url function'        => [ 'url(//example.com/x.png)' ],
			'expression'          => [ 'expression(alert(1))' ],
			'style close'         => [ '</style><script>alert(1)</script>' ],
			'comment opener'      => [ 'red/*' ],
			'short hex'           => [ '#12' ],
			'five digit hex'      => [ '#12345' ],
			'non hex digits'      => [ '#ggg' ],
			'hex with suffix'     => [ '#fff;' ],
			'rgb missing args'    => [ 'rgb(1,2)' ],
			'rgb text arg'        => [ 'rgb(1,2,red)' ],
			'rgba trailing rule'  => [ 'rgba(0,0,0,0.5);}a{}' ],
			'hsl url arg'         => [ 'hsl(url(x),1%,1%)' ],
			'nested function'     => [ 'rgb(calc(1),2,3)' ],
			'gradient'            => [ 'linear-gradient(red,blue)' ],
			'integer'             => [ 123 ],
			'boolean'             => [ true ],
			'array'               => [ [ 'color' => 'red' ] ],
			'newline smuggle'     => [ "red\n;}body{display:none}" ],
		];
	}

	/** @return array<string, array{0:mixed}> */
	public static function valid_colors(): array {
		return [
			'empty clears'        => [ '' ],
			'null'                => [ null ],
			'hex3'                => [ '#fff' ],
			'hex4'                => [ '#fff8' ],
			'hex6'                => [ '#AaBbCc' ],
			'hex8'                => [ '#aabbccdd' ],
			'rgb'                 => [ 'rgb(255, 0, 0)' ],
			'rgba'                => [ 'rgba(0,0,0,0.5)' ],
			'rgba leading dot'    => [ 'rgba(0,0,0,.5)' ],
			'rgb percent'         => [ 'rgb(100%, 0%, 0%)' ],
			'rgb space syntax'    => [ 'rgb(0 0 0 / 50%)' ],
			'hsl'                 => [ 'hsl(120, 100%, 50%)' ],
			'hsla'                => [ 'hsla(120deg 100% 50% / .5)' ],
			'transparent'         => [ 'transparent' ],
			'named'               => [ 'red' ],
			'named mixed case'    => [ 'RebeccaPurple' ],
			'current colour'      => [ 'currentColor' ],
			'global css variable' => [ 'var(--e-global-color-primary)' ],
		];
	}

	/**
	 * @dataProvider invalid_colors
	 */
	public function test_colour_control_refuses_values_that_are_not_colours( mixed $value ): void {
		$result = SettingsValidator::validate( 'safety-widget', [ 'title_color' => $value ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'settings.title_color', $result->get_error_data()['violations'][0]['path'] );
		self::assertStringContainsString( 'settings.title_color', $result->get_error_message() );
	}

	/**
	 * @dataProvider valid_colors
	 */
	public function test_colour_control_accepts_real_colour_forms( mixed $value ): void {
		$result = SettingsValidator::validate( 'safety-widget', [ 'title_color' => $value ], false );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( $value, $result['settings']['title_color'] );
	}

	public function test_responsive_colour_key_is_checked_like_its_base_control(): void {
		$result = SettingsValidator::validate( 'safety-widget', [ 'title_color_mobile' => 'red;}body{display:none}' ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'settings.title_color_mobile', $result->get_error_data()['violations'][0]['path'] );
	}

	public function test_repeater_colour_field_is_checked(): void {
		$bad = SettingsValidator::validate( 'safety-widget', [ 'items' => [ [ '_id' => 'a', 'item_color' => 'red;}x{' ] ] ], false );
		$ok  = SettingsValidator::validate( 'safety-widget', [ 'items' => [ [ '_id' => 'a', 'item_color' => '#00ff00' ] ] ], false );

		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'settings.items.0.item_color', $bad->get_error_data()['violations'][0]['path'] );
		self::assertIsArray( $ok );
	}

	public function test_global_references_are_accepted_only_in_the_stored_form(): void {
		$ok = SettingsValidator::validate(
			'safety-widget',
			[ '__globals__' => [ 'title_color' => 'globals/colors?id=primary', 'typography_typography' => 'globals/typography?id=accent' ] ],
			false
		);
		self::assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_message() : '' );

		foreach ( [ 'red;}body{display:none}', 'globals/colors?id=a;}b{', 'url(//example.com/x.png)', 'globals/colors?id=' ] as $bad ) {
			$result = SettingsValidator::validate( 'safety-widget', [ '__globals__' => [ 'title_color' => $bad ] ], false );
			self::assertInstanceOf( \WP_Error::class, $result, $bad );
			self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
			self::assertSame( 'settings.__globals__.title_color', $result->get_error_data()['violations'][0]['path'] );
		}
	}

	public function test_empty_global_reference_clears_the_binding(): void {
		$result = SettingsValidator::validate( 'safety-widget', [ '__globals__' => [ 'title_color' => '' ] ], false );

		self::assertIsArray( $result );
	}

	/** @return array<string, array{0:string,1:mixed}> */
	public static function invalid_typography(): array {
		return [
			'family breakout'      => [ 'typography_font_family', 'Arial;}body{display:none}' ],
			'family url'           => [ 'typography_font_family', 'url(//example.com/f.woff)' ],
			'family braces'        => [ 'typography_font_family', 'Arial{x}' ],
			'family angle'         => [ 'typography_font_family', '<script>' ],
			'family quote'         => [ 'typography_font_family', 'Arial"' ],
			'family array'         => [ 'typography_font_family', [ 'Arial' ] ],
			'size unit breakout'   => [ 'typography_font_size', [ 'size' => 16, 'unit' => 'px;}body{display:none}' ] ],
			'size unit unknown'    => [ 'typography_font_size', [ 'size' => 16, 'unit' => 'bogus' ] ],
			'size unit not text'   => [ 'typography_font_size', [ 'size' => 16, 'unit' => [ 'px' ] ] ],
			'size value breakout'  => [ 'typography_font_size', [ 'size' => '16px;}body{', 'unit' => 'px' ] ],
			'line height unit'     => [ 'typography_line_height', [ 'size' => 1.5, 'unit' => 'em}' ] ],
			'letter spacing size'  => [ 'typography_letter_spacing', [ 'size' => 'url(x)', 'unit' => 'px' ] ],
			'word spacing unit'    => [ 'typography_word_spacing', [ 'size' => 1, 'unit' => 'x;' ] ],
			'weight breakout'      => [ 'typography_font_weight', '700;}body{display:none}' ],
			'weight text'          => [ 'typography_font_weight', 'heavy' ],
			'transform breakout'   => [ 'typography_text_transform', 'uppercase;}x{' ],
			'transform unknown'    => [ 'typography_text_transform', 'shout' ],
			'style breakout'       => [ 'typography_font_style', 'italic;}x{' ],
			'decoration breakout'  => [ 'typography_text_decoration', 'underline;}x{' ],
			'decoration unknown'   => [ 'typography_text_decoration', 'blink-forever' ],
		];
	}

	/**
	 * @dataProvider invalid_typography
	 */
	public function test_typography_controls_refuse_unsafe_values( string $key, mixed $value ): void {
		$result = SettingsValidator::validate( 'safety-widget', [ $key => $value ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_elementor_settings_invalid', $result->get_error_code() );
		self::assertSame( 'settings.' . $key, $result->get_error_data()['violations'][0]['path'] );
	}

	/** @return array<string, array{0:string,1:mixed}> */
	public static function valid_typography(): array {
		return [
			'family'             => [ 'typography_font_family', 'Roboto' ],
			'family with space'  => [ 'typography_font_family', 'Open Sans' ],
			'family with hyphen' => [ 'typography_font_family', 'Source Sans-3' ],
			'family stack'       => [ 'typography_font_family', 'Inter, sans-serif' ],
			'family empty'       => [ 'typography_font_family', '' ],
			'size px'            => [ 'typography_font_size', [ 'size' => 16, 'unit' => 'px' ] ],
			'size string em'     => [ 'typography_font_size', [ 'size' => '1.5', 'unit' => 'em' ] ],
			'size rem'           => [ 'typography_font_size', [ 'unit' => 'rem', 'size' => 2, 'sizes' => [] ] ],
			'size vw'            => [ 'typography_font_size', [ 'size' => 4, 'unit' => 'vw' ] ],
			'size cleared'       => [ 'typography_font_size', [ 'size' => '', 'unit' => 'px' ] ],
			'size mobile'        => [ 'typography_font_size_mobile', [ 'size' => 14, 'unit' => 'px' ] ],
			'line height unitless' => [ 'typography_line_height', [ 'size' => 1.4, 'unit' => 'em' ] ],
			'letter spacing'     => [ 'typography_letter_spacing', [ 'size' => -0.5, 'unit' => 'px' ] ],
			'weight number'      => [ 'typography_font_weight', '700' ],
			'weight keyword'     => [ 'typography_font_weight', 'bold' ],
			'weight empty'       => [ 'typography_font_weight', '' ],
			'transform'          => [ 'typography_text_transform', 'uppercase' ],
			'style'              => [ 'typography_font_style', 'italic' ],
			'decoration'         => [ 'typography_text_decoration', 'line-through' ],
		];
	}

	/**
	 * @dataProvider valid_typography
	 */
	public function test_typography_controls_accept_real_values( string $key, mixed $value ): void {
		$result = SettingsValidator::validate( 'safety-widget', [ 'typography_typography' => 'custom', $key => $value ], false );

		self::assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : '' );
		self::assertSame( $value, $result['settings'][ $key ] );
	}

	public function test_dimensions_refuse_unsafe_sides_and_units(): void {
		foreach ( [
			[ 'top' => '1px;}body{display:none}', 'right' => '0', 'bottom' => '0', 'left' => '0', 'unit' => 'px' ],
			[ 'top' => '1', 'right' => '0', 'bottom' => '0', 'left' => '0', 'unit' => 'px;}x{' ],
			[ 'top' => '1', 'right' => '0', 'bottom' => '0', 'left' => '0', 'unit' => 'bogus' ],
		] as $bad ) {
			$result = SettingsValidator::validate( 'safety-widget', [ 'spacing' => $bad ], false );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'settings.spacing', $result->get_error_data()['violations'][0]['path'] );
		}

		$ok = SettingsValidator::validate(
			'safety-widget',
			[ 'spacing' => [ 'top' => '-8', 'right' => '1.5', 'bottom' => '', 'left' => 4, 'unit' => 'em', 'isLinked' => false ] ],
			false
		);
		self::assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_message() : '' );
	}

	public function test_shadow_controls_check_geometry_and_colour(): void {
		$bad = SettingsValidator::validate(
			'safety-widget',
			[ 'box_shadow_box_shadow' => [ 'horizontal' => 0, 'vertical' => 0, 'blur' => 10, 'spread' => 0, 'color' => 'red;}body{display:none}' ] ],
			false
		);
		self::assertInstanceOf( \WP_Error::class, $bad );
		self::assertSame( 'settings.box_shadow_box_shadow', $bad->get_error_data()['violations'][0]['path'] );

		$bad_blur = SettingsValidator::validate( 'safety-widget', [ 'text_shadow_text_shadow' => [ 'horizontal' => 0, 'vertical' => 0, 'blur' => '1px;}', 'color' => '#000' ] ], false );
		self::assertInstanceOf( \WP_Error::class, $bad_blur );

		$ok = SettingsValidator::validate(
			'safety-widget',
			[
				'box_shadow_box_shadow'   => [ 'horizontal' => 0, 'vertical' => 4, 'blur' => 10, 'spread' => 0, 'color' => 'rgba(0,0,0,0.5)' ],
				'text_shadow_text_shadow' => [ 'horizontal' => 1, 'vertical' => 1, 'blur' => 2, 'color' => '#000000' ],
			],
			false
		);
		self::assertIsArray( $ok, $ok instanceof \WP_Error ? $ok->get_error_message() : '' );
	}

	public function test_a_refused_value_never_reaches_the_normalized_settings(): void {
		$result = SettingsValidator::validate( 'safety-widget', [ 'title' => 'Kept', 'title_color' => 'red;}body{display:none}' ], false );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertStringNotContainsString( 'body{display:none}', (string) wp_json_encode( $result->get_error_data()['schema_request'] ?? [] ) );
	}

	public function test_tree_validation_with_preserve_unknown_still_refuses_unsafe_colour(): void {
		$tree = [
			[
				'id'         => 'abc12345',
				'elType'     => 'widget',
				'widgetType' => 'safety-widget',
				'settings'   => [ 'title_color' => 'red;}body{display:none}' ],
				'elements'   => [],
			],
		];

		self::assertFalse( SettingsValidator::validate_tree( $tree ) );
		self::assertSame( 'stonewright_elementor_settings_invalid', SettingsValidator::last_error()?->get_error_code() );
	}
}

final class SafetyWidgetForTest {
	public function get_title(): string {
		return 'Safety Widget';
	}

	/** @return array<string,array<string,mixed>> */
	public function get_controls(): array {
		return [
			'title'                      => [ 'type' => 'text', 'label' => 'Title' ],
			'title_color'                => [ 'type' => 'color', 'label' => 'Colour', 'responsive' => true ],
			'typography_typography'      => [ 'type' => 'popover_toggle', 'return_value' => 'custom', 'default' => '' ],
			'typography_font_family'     => [ 'type' => 'font', 'label' => 'Family' ],
			'typography_font_size'       => [ 'type' => 'slider', 'responsive' => true ],
			'typography_line_height'     => [ 'type' => 'slider', 'responsive' => true ],
			'typography_letter_spacing'  => [ 'type' => 'slider', 'responsive' => true ],
			'typography_word_spacing'    => [ 'type' => 'slider', 'responsive' => true ],
			'typography_font_weight'     => [ 'type' => 'select' ],
			'typography_text_transform'  => [ 'type' => 'select' ],
			'typography_font_style'      => [ 'type' => 'select' ],
			'typography_text_decoration' => [ 'type' => 'select' ],
			'items'                      => [
				'type'   => 'repeater',
				'fields' => [ 'item_color' => [ 'type' => 'color', 'label' => 'Item colour' ] ],
			],
			'spacing'                    => [ 'type' => 'dimensions', 'responsive' => [] ],
			'box_shadow_box_shadow'      => [ 'type' => 'box_shadow' ],
			'text_shadow_text_shadow'    => [ 'type' => 'text_shadow' ],
		];
	}
}
