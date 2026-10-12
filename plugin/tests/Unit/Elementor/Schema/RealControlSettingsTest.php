<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Schema;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Schema\PatchValidator;
use Stonewright\WpMcp\Elementor\Schema\SettingsValidator;
use Stonewright\WpMcp\Elementor\Schema\WidgetSchemaRepository;
use Stonewright\WpMcp\Tests\Unit\Elementor\Schema\Fixtures\ElementorControlFixtures;

/**
 * Valid Elementor settings are accepted against control definitions shaped like the ones Elementor 4.3 registers, and
 * a refused setting names its control, what was received, what the control accepts and a corrected example.
 *
 * @covers \Stonewright\WpMcp\Elementor\Schema\SettingsValidator
 * @covers \Stonewright\WpMcp\Elementor\Schema\PatchValidator
 */
final class RealControlSettingsTest extends TestCase {

	private object $original_elementor;

	protected function setUp(): void {
		$this->original_elementor = ElementorControlFixtures::install();
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	protected function tearDown(): void {
		\Elementor\Plugin::$instance = $this->original_elementor;
		WidgetSchemaRepository::reset_request_cache();
		$GLOBALS['stonewright_test_transients'] = [];
	}

	/** @param array<string, mixed> $settings */
	private function widget( string $type, array $settings ): array|\WP_Error {
		return SettingsValidator::validate( $type, $settings, false );
	}

	/** @param array<string, mixed> $settings */
	private function container( array $settings ): array|\WP_Error {
		return SettingsValidator::validate_container( $settings );
	}

	private static function why( array|\WP_Error $result ): string {
		return $result instanceof \WP_Error ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data()['violations'] ?? [] ) : '';
	}

	/** @return array<string, mixed> */
	private static function first_violation( array|\WP_Error $result ): array {
		self::assertInstanceOf( \WP_Error::class, $result );
		return $result->get_error_data()['violations'][0];
	}

	// ---- valid settings that were refused -------------------------------------------------------------------

	public function test_a_control_that_hides_itself_when_it_has_its_default_still_accepts_that_default(): void {
		$result = $this->widget( 'heading', [ 'size' => 'default' ] );
		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( 'default', $result['settings']['size'] );

		$button = $this->widget( 'button', [ 'size' => 'sm' ] );
		self::assertIsArray( $button, self::why( $button ) );

		$large = $this->widget( 'heading', [ 'size' => 'large' ] );
		self::assertIsArray( $large, self::why( $large ) );
	}

	/** @return array<string, array{0:mixed,1:string}> */
	public static function font_weights(): array {
		return [
			'integer 600'     => [ 600, '600' ],
			'integer 400'     => [ 400, '400' ],
			'string 700'      => [ '700', '700' ],
			'keyword bold'    => [ 'bold', 'bold' ],
			'default (empty)' => [ '', '' ],
		];
	}

	/** @dataProvider font_weights */
	public function test_a_font_weight_is_accepted_as_the_number_the_editor_stores_or_a_keyword( mixed $given, string $stored ): void {
		$result = $this->widget( 'heading', [ 'typography_typography' => 'custom', 'typography_font_weight' => $given ] );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( $stored, $result['settings']['typography_font_weight'] );
	}

	public function test_a_font_weight_outside_the_range_is_still_refused_with_a_usable_message(): void {
		$result = $this->widget( 'heading', [ 'typography_typography' => 'custom', 'typography_font_weight' => 'heavy' ] );

		$violation = self::first_violation( $result );
		self::assertSame( 'settings.typography_font_weight', $violation['path'] );
		self::assertSame( 'string', $violation['received_value']['type'] );
		self::assertSame( 'heavy', $violation['received_value']['preview'] );
		self::assertContains( '600', $violation['allowed_values'] );
	}

	public function test_an_empty_value_clears_a_select_whose_options_do_not_list_the_empty_string(): void {
		$result = $this->widget( 'button', [ 'button_box_shadow_type' => 'yes', 'button_box_shadow_box_shadow_position' => '' ] );
		self::assertIsArray( $result, self::why( $result ) );

		$choose = $this->container( [ 'flex_direction' => '', 'flex_wrap' => null ] );
		self::assertIsArray( $choose, self::why( $choose ) );
	}

	public function test_a_value_that_is_not_an_option_is_still_refused_and_the_options_are_listed(): void {
		$violation = self::first_violation( $this->container( [ 'flex_direction' => 'diagonal' ] ) );

		self::assertSame( 'invalid_option', $violation['code'] );
		self::assertSame( 'choose', $violation['control']['type'] );
		self::assertSame( [ 'row', 'column', 'row-reverse', 'column-reverse' ], $violation['allowed_values'] );
		self::assertSame( [ 'flex_direction' => 'row' ], $violation['example'] );
		self::assertSame( 'diagonal', $violation['received_value']['preview'] );
	}

	/** @return array<string, array{0:string,1:string,2:mixed}> */
	public static function cleared_values(): array {
		return [
			'number, empty string'      => [ 'heading', '_z_index', '' ],
			'number, null'              => [ 'heading', '_z_index', null ],
			'slider, empty string'      => [ 'spacer', 'space', '' ],
			'dimensions, empty string'  => [ 'heading', '_margin', '' ],
			'dimensions, null'          => [ 'heading', '_padding_mobile', null ],
			'url, empty string'         => [ 'heading', 'link', '' ],
			'color, empty string'       => [ 'heading', 'title_color', '' ],
			'icons, empty string'       => [ 'button', 'selected_icon', '' ],
			'font size, empty string'   => [ 'heading', 'typography_font_size', '' ],
		];
	}

	/** @dataProvider cleared_values */
	public function test_an_unset_control_is_accepted_as_the_empty_string_or_null( string $widget, string $key, mixed $value ): void {
		$settings = [ $key => $value ];
		if ( str_starts_with( $key, 'typography_' ) ) {
			$settings['typography_typography'] = 'custom';
		}
		$result = $this->widget( $widget, $settings );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( $value, $result['settings'][ $key ] );
	}

	public function test_a_media_control_accepts_the_empty_string_and_the_empty_object(): void {
		$controls = [ 'background_image' => [ 'type' => 'media', 'key' => 'background_image' ] ];
		self::assertSame( [], $this->validate_value( $controls['background_image'], '' ) ?? [] );
		self::assertSame( [], $this->validate_value( $controls['background_image'], [ 'id' => '', 'url' => '' ] ) ?? [] );
		self::assertSame( [], $this->validate_value( $controls['background_image'], [ 'id' => 12, 'url' => 'https://example.test/a.jpg', 'size' => '', 'alt' => '' ] ) ?? [] );
	}

	/** @param array<string, mixed> $control @return array<string, mixed>|null */
	private function validate_value( array $control, mixed $value ): ?array {
		$method = new \ReflectionMethod( SettingsValidator::class, 'validate_value' );
		$method->setAccessible( true );
		$result = $method->invoke( null, $value, $control, 'settings.x' );
		return null === $result ? [] : $result;
	}

	/** @return array<string, array{0:array<string,mixed>}> */
	public static function custom_unit_values(): array {
		return [
			'calc'  => [ [ 'unit' => 'custom', 'size' => 'calc(100vh - 80px)', 'sizes' => [] ] ],
			'clamp' => [ [ 'unit' => 'custom', 'size' => 'clamp(18rem, 40vw, 32rem)' ] ],
			'var'   => [ [ 'unit' => 'custom', 'size' => 'var(--hero-min-height)' ] ],
			'min'   => [ [ 'unit' => 'custom', 'size' => 'min(100%, 60ch)' ] ],
		];
	}

	/** @dataProvider custom_unit_values */
	public function test_a_slider_with_the_custom_unit_holds_a_css_length_expression( array $value ): void {
		$result = $this->container( [ 'min_height' => $value, 'min_height_mobile' => $value ] );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( $value, $result['settings']['min_height'] );
	}

	public function test_dimensions_with_the_custom_unit_hold_css_length_expressions(): void {
		$value  = [ 'unit' => 'custom', 'top' => 'clamp(1rem, 4vw, 3rem)', 'right' => '2vw', 'bottom' => 'calc(1rem + 2px)', 'left' => '0', 'isLinked' => false ];
		$result = $this->widget( 'heading', [ '_padding' => $value ] );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( $value, $result['settings']['_padding'] );
	}

	/** @return array<string, array{0:array<string,mixed>}> */
	public static function unsafe_sizes(): array {
		return [
			'custom with a rule break'      => [ [ 'unit' => 'custom', 'size' => '1px;}body{display:none}' ] ],
			'custom with url()'             => [ [ 'unit' => 'custom', 'size' => 'url(//example.test/x.png)' ] ],
			'custom with a comment'         => [ [ 'unit' => 'custom', 'size' => '1px/*' ] ],
			'custom with a long string'     => [ [ 'unit' => 'custom', 'size' => str_repeat( 'a', 300 ) ] ],
			'text size under a real unit'   => [ [ 'unit' => 'px', 'size' => 'calc(1px + 2px)' ] ],
			'text size without a unit'      => [ [ 'size' => 'big' ] ],
		];
	}

	/** @dataProvider unsafe_sizes */
	public function test_a_text_size_is_still_refused_unless_the_unit_is_custom_and_the_text_is_a_plain_expression( array $value ): void {
		$result = $this->container( [ 'min_height' => $value ] );

		self::assertSame( 'settings.min_height', self::first_violation( $result )['path'] );
	}

	public function test_a_repeater_row_keeps_its_global_and_dynamic_bindings(): void {
		$row = [
			'_id'         => 'a1b2c3d',
			'text'        => 'First',
			'icon_color'  => '#112233',
			'__globals__' => [ 'icon_color' => 'globals/colors?id=primary' ],
			'__dynamic__' => [ 'text' => '[elementor-tag id="f00ba12" name="post-title" settings="%7B%7D"]' ],
		];
		$result = $this->widget( 'icon-list', [ 'icon_list' => [ $row ] ] );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( [ $row ], $result['settings']['icon_list'] );
	}

	public function test_a_repeater_row_binding_to_an_unknown_field_is_still_refused_and_named(): void {
		$result = $this->widget( 'icon-list', [ 'icon_list' => [ [ '_id' => 'a1b2c3d', '__globals__' => [ 'icon_colour' => 'globals/colors?id=primary' ] ] ] ] );

		$violation = self::first_violation( $result );
		self::assertSame( 'settings.icon_list.0.__globals__.icon_colour', $violation['path'] );
		self::assertContains( 'icon_color', $violation['suggestions'] );
	}

	/** @return array<string, array{0:mixed}> */
	public static function links(): array {
		return [
			'phone link'            => [ [ 'url' => 'tel:+40123456789', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ] ],
			'mail link'             => [ [ 'url' => 'mailto:team@example.test?subject=Hello', 'is_external' => '', 'nofollow' => '' ] ],
			'relative path'         => [ [ 'url' => 'contact/', 'is_external' => '', 'nofollow' => '' ] ],
			'relative file'         => [ [ 'url' => 'brochure.pdf' ] ],
			'query only'            => [ [ 'url' => '?plan=pro' ] ],
			'anchor'                => [ [ 'url' => '#pricing' ] ],
			'unicode path'          => [ [ 'url' => 'https://example.test/ro/pagină-de-contact', 'is_external' => 'on', 'nofollow' => 'on', 'custom_attributes' => 'data-track|cta' ] ],
			'empty link'            => [ [ 'url' => '', 'is_external' => '', 'nofollow' => '' ] ],
			'dynamic tag url'       => [ [ 'url' => '', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ] ],
			'plain string'          => [ 'https://example.test/' ],
			'toggle only'           => [ [ 'nofollow' => 'on' ] ],
		];
	}

	/** @dataProvider links */
	public function test_a_link_object_with_any_real_target_is_accepted( mixed $link ): void {
		$result = $this->widget( 'button', [ 'link' => $link ] );

		self::assertIsArray( $result, self::why( $result ) );
		self::assertSame( $link, $result['settings']['link'] );
	}

	/** @return array<string, array{0:mixed}> */
	public static function unsafe_links(): array {
		return [
			'script scheme'       => [ [ 'url' => 'javascript:alert(1)' ] ],
			'script scheme case'  => [ [ 'url' => 'JavaScript:alert(1)' ] ],
			'data scheme'         => [ [ 'url' => 'data:text/html;base64,PHNjcmlwdD4=' ] ],
			'vbscript scheme'     => [ [ 'url' => 'vbscript:msgbox(1)' ] ],
			'number'              => [ 12 ],
		];
	}

	/** @dataProvider unsafe_links */
	public function test_a_link_with_a_script_scheme_or_no_url_is_still_refused( mixed $link ): void {
		$result = $this->widget( 'button', [ 'link' => $link ] );

		self::assertSame( 'settings.link', self::first_violation( $result )['path'] );
	}

	public function test_icon_values_of_the_font_library_and_of_an_uploaded_svg_are_accepted(): void {
		$font = $this->widget( 'button', [ 'selected_icon' => [ 'value' => 'fas fa-arrow-right', 'library' => 'fa-solid' ], 'icon_align' => 'row-reverse' ] );
		$svg  = $this->widget( 'button', [ 'selected_icon' => [ 'value' => [ 'id' => 412, 'url' => 'https://example.test/icon.svg' ], 'library' => 'svg' ] ] );

		self::assertIsArray( $font, self::why( $font ) );
		self::assertIsArray( $svg, self::why( $svg ) );
	}

	public function test_breakpoint_variants_of_responsive_controls_follow_their_base_control(): void {
		$result = $this->widget(
			'heading',
			[
				'title_color'                   => '#111111',
				'align_tablet'                  => 'center',
				'align_mobile'                  => 'left',
				'typography_typography'         => 'custom',
				'typography_font_size'          => [ 'unit' => 'px', 'size' => 40, 'sizes' => [] ],
				'typography_font_size_tablet'   => [ 'unit' => 'px', 'size' => 32, 'sizes' => [] ],
				'typography_font_size_mobile'   => [ 'unit' => 'rem', 'size' => 1.5, 'sizes' => [] ],
				'typography_line_height_laptop' => [ 'unit' => 'em', 'size' => 1.2, 'sizes' => [] ],
				'typography_letter_spacing_widescreen' => [ 'unit' => 'px', 'size' => 1, 'sizes' => [] ],
				'_margin_mobile'                => [ 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '8', 'left' => '0', 'isLinked' => false ],
				'hide_tablet'                   => 'hidden-tablet',
				'hide_mobile'                   => '',
			]
		);

		self::assertIsArray( $result, self::why( $result ) );
		self::assertCount( 12, $result['settings'] );
	}

	public function test_a_breakpoint_variant_of_a_control_that_is_not_responsive_is_refused_and_says_so(): void {
		$violation = self::first_violation( $this->widget( 'heading', [ 'header_size_mobile' => 'h3' ] ) );

		self::assertSame( 'unknown_setting', $violation['code'] );
		self::assertContains( 'header_size', $violation['suggestions'] );
		self::assertStringContainsString( 'not responsive', $violation['hint'] );
	}

	public function test_a_container_takes_its_real_layout_settings(): void {
		$result = $this->container(
			[
				'content_width'  => 'full',
				'width'          => [ 'unit' => '%', 'size' => 50, 'sizes' => [] ],
				'width_mobile'   => [ 'unit' => '%', 'size' => 100, 'sizes' => [] ],
				'flex_direction' => 'column',
				'flex_gap'       => [ 'column' => '16', 'row' => '16', 'isLinked' => true, 'unit' => 'px' ],
				'flex_gap_mobile' => [ 'column' => '8', 'row' => '8', 'isLinked' => true, 'unit' => 'px' ],
				'padding'        => [ 'unit' => 'px', 'top' => '40', 'right' => '24', 'bottom' => '40', 'left' => '24', 'isLinked' => false ],
				'css_classes'    => 'hero band',
				'_element_id'    => 'hero',
				'z_index'        => 2,
				'background_background' => 'classic',
				'background_color'      => '#0b1b2b',
				'border_radius'  => [ 'unit' => 'px', 'top' => '12', 'right' => '12', 'bottom' => '12', 'left' => '12', 'isLinked' => true ],
				'box_shadow_type' => 'yes',
				'box_shadow_box_shadow' => [ 'horizontal' => 0, 'vertical' => 8, 'blur' => 24, 'spread' => 0, 'color' => 'rgba(0,0,0,0.25)' ],
				'min_height'     => [ 'unit' => 'vh', 'size' => 80, 'sizes' => [] ],
				'container_type' => 'flex',
			]
		);

		self::assertIsArray( $result, self::why( $result ) );
	}

	public function test_a_hover_style_and_a_button_background_are_accepted(): void {
		$result = $this->widget(
			'button',
			[
				'text'                          => 'Book now',
				'button_text_color'             => '#ffffff',
				'background_background'         => 'classic',
				'background_color'              => '#0a5cff',
				'hover_color'                   => '#ffffff',
				'button_background_hover_color' => '#0847c4',
				'hover_animation'               => 'grow',
				'border_border'                 => 'solid',
				'border_width'                  => [ 'unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true ],
				'border_color'                  => '#0a5cff',
				'__globals__'                   => [ 'button_text_color' => 'globals/colors?id=accent', 'typography_typography' => 'globals/typography?id=primary' ],
				'_css_classes'                  => 'cta',
				'_element_width'                => 'initial',
				'_element_custom_width'         => [ 'unit' => '%', 'size' => 40, 'sizes' => [] ],
				'_element_custom_width_mobile'  => [ 'unit' => '%', 'size' => 100, 'sizes' => [] ],
			]
		);

		self::assertIsArray( $result, self::why( $result ) );
	}

	// ---- refusals that are correct, and what they say --------------------------------------------------------

	public function test_a_setting_whose_control_is_inactive_says_which_setting_to_send_with_it(): void {
		$result    = $this->widget( 'heading', [ 'typography_typography' => '', 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ] );
		$violation = self::first_violation( $result );

		self::assertSame( 'inactive_condition', $violation['code'] );
		self::assertSame( 'settings.typography_font_size', $violation['path'] );
		self::assertSame( 'slider', $violation['control']['type'] );
		self::assertSame( [ 'typography_typography' => 'custom' ], $violation['requires'] );
		self::assertSame(
			[ 'typography_typography' => 'custom', 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ],
			$violation['example']
		);
		self::assertStringContainsString( 'typography_typography', $result->get_error_message() );
		self::assertStringContainsString( 'custom', $result->get_error_message() );
		self::assertNotContains( 'typography_typography!', $violation['suggestions'] );
	}

	public function test_the_custom_width_control_says_it_needs_the_custom_width_mode(): void {
		$violation = self::first_violation( $this->widget( 'button', [ '_element_custom_width' => [ 'unit' => '%', 'size' => 40 ] ] ) );

		self::assertSame( 'inactive_condition', $violation['code'] );
		self::assertSame( [ '_element_width' => 'initial' ], $violation['requires'] );
		self::assertSame( 'initial', $violation['example']['_element_width'] );
	}

	public function test_the_container_width_control_needs_the_full_content_width(): void {
		$violation = self::first_violation( $this->container( [ 'width' => [ 'unit' => '%', 'size' => 50 ] ] ) );

		self::assertSame( [ 'content_width' => 'full' ], $violation['requires'] );
		self::assertSame( 'full', $violation['example']['content_width'] );
	}

	public function test_a_slider_that_is_a_bare_unit_is_refused_with_the_units_the_control_takes(): void {
		$violation = self::first_violation( $this->container( [ 'min_height' => [ 'unit' => 'pt', 'size' => 'tall' ] ] ) );

		self::assertSame( 'invalid_shape', $violation['code'] );
		self::assertSame( 'slider', $violation['control']['type'] );
		self::assertSame( [ 'px', 'em', 'rem', 'vh', 'custom' ], $violation['control']['units'] );
		self::assertSame( [ 'min_height_tablet', 'min_height_mobile' ], $violation['control']['responsive_keys'] );
		self::assertSame( 'array', $violation['received_value']['type'] );
		self::assertSame( [ 'min_height' => [ 'unit' => 'px', 'size' => 100 ] ], $violation['example'] );
	}

	public function test_a_dimensions_refusal_carries_the_shape_and_an_example(): void {
		$violation = self::first_violation( $this->widget( 'heading', [ '_margin' => '10px 20px' ] ) );

		self::assertSame( 'settings._margin', $violation['path'] );
		self::assertSame( 'dimensions', $violation['control']['type'] );
		self::assertSame( 'string', $violation['received_value']['type'] );
		self::assertSame( '10px 20px', $violation['received_value']['preview'] );
		self::assertSame( [ '_margin' => [ 'unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10', 'isLinked' => true ] ], $violation['example'] );
	}

	public function test_an_unknown_setting_lists_the_closest_real_keys_and_the_message_shows_the_received_value(): void {
		$result    = $this->widget( 'heading', [ 'title_colour' => '#fff' ] );
		$violation = self::first_violation( $result );

		self::assertSame( 'unknown_setting', $violation['code'] );
		self::assertSame( 'title_colour', $violation['setting'] );
		self::assertSame( 'title_color', $violation['suggestions'][0] );
		self::assertSame( [ 'title_color' => '#fff' ], $violation['example'] );
		self::assertSame( '#fff', $violation['received_value']['preview'] );
		self::assertStringContainsString( 'title_color', $result->get_error_message() );
	}

	public function test_a_colour_refusal_names_the_control_and_gives_a_colour_example(): void {
		$violation = self::first_violation( $this->widget( 'heading', [ 'title_color' => 'blue-ish' ] ) );

		self::assertSame( 'color', $violation['control']['type'] );
		self::assertArrayNotHasKey( 'responsive_keys', $violation['control'] );
		self::assertSame( 'string', $violation['received_value']['type'] );
		self::assertSame( [ 'title_color' => '#1a73e8' ], $violation['example'] );
	}

	public function test_every_violation_of_a_call_is_reported_and_the_message_counts_them(): void {
		$result = $this->widget( 'heading', [ 'title_color' => 'blue-ish', 'header_size' => 'h9', '_z_index' => 'high', 'title' => 'ok' ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertCount( 3, $result->get_error_data()['violations'] );
		self::assertStringContainsString( '3 settings', $result->get_error_message() );
		self::assertStringContainsString( 'settings.header_size', $result->get_error_message() );
		self::assertStringContainsString( 'settings._z_index', $result->get_error_message() );
	}

	public function test_a_received_value_is_cut_short_and_never_carries_a_secret(): void {
		$long   = $this->widget( 'heading', [ 'title_color' => str_repeat( 'x', 400 ) ] );
		$secret = $this->widget( 'heading', [ 'title_color' => 'Bearer fixture-token-abcdefghijklmnop' ] );
		$keyed  = $this->widget( 'heading', [ '_margin' => [ 'password' => 'fixture-pass-12345', 'top' => 'x' ] ] );

		self::assertLessThanOrEqual( 80, mb_strlen( self::first_violation( $long )['received_value']['preview'] ) );
		self::assertStringNotContainsString( 'abcdefghijklmnop', (string) wp_json_encode( $secret->get_error_data() ) );
		self::assertStringNotContainsString( 'fixture-pass-12345', (string) wp_json_encode( $keyed->get_error_data() ) );
		self::assertStringNotContainsString( 'abcdefghijklmnop', $secret->get_error_message() );
	}

	public function test_a_patch_refusal_keeps_the_same_detail(): void {
		$result = PatchValidator::widget( 'heading', [ 'typography_typography' => '' ], [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ], 'merge' );

		$violation = self::first_violation( $result );
		self::assertSame( 'inactive_condition', $violation['code'] );
		self::assertSame( [ 'typography_typography' => 'custom' ], $violation['requires'] );
	}

	public function test_a_patch_that_sets_the_gate_on_an_existing_element_is_accepted(): void {
		$result = PatchValidator::widget( 'heading', [ 'typography_typography' => 'custom' ], [ 'typography_font_size' => [ 'unit' => 'px', 'size' => 40 ] ], 'merge' );

		self::assertIsArray( $result, self::why( $result ) );
	}
}
