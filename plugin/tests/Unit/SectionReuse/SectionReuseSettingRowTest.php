<?php
/**
 * The "Reuse saved sections" row of the Setup screen.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\SectionReuse;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Setup\SectionReuseRow;
use Stonewright\WpMcp\SectionReuse\SectionReuseSetting;

/**
 * @covers \Stonewright\WpMcp\Admin\Setup\SectionReuseRow
 * @covers \Stonewright\WpMcp\SectionReuse\SectionReuseSetting
 */
final class SectionReuseSettingRowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']             = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$GLOBALS['stonewright_test_user_caps']           = [ 'manage_options' => true ];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']             = [];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$GLOBALS['stonewright_test_user_caps']           = [];
	}

	private static function row(): string {
		return SectionReuseRow::html();
	}

	public function test_the_row_is_one_form_table_row_with_a_labelled_switch_and_one_sentence_of_help(): void {
		$html = self::row();

		self::assertStringStartsWith( '<tr class="stonewright-section-reuse-row">', $html, 'One row of the settings table.' );
		self::assertSame( 1, substr_count( $html, '<tr' ) );
		self::assertStringContainsString( '<th scope="row"><label for="stonewright_section_reuse">Reuse saved sections</label></th>', $html );
		self::assertStringContainsString( 'id="stonewright_section_reuse"', $html );
		self::assertStringContainsString( 'role="switch"', $html );
		self::assertStringContainsString( 'aria-describedby="stonewright_section_reuse_help"', $html );
		self::assertStringContainsString( 'id="stonewright_section_reuse_help"', $html );
		self::assertStringContainsString( '<span class="sw-ui-switch"><input type="checkbox" role="switch"', $html, 'The control is the layer\'s switch.' );
		self::assertStringNotContainsString( 'style=', $html, 'No inline styles.' );
		self::assertStringNotContainsString( 'class="notice', $html, 'No core notice inside the form.' );
	}

	public function test_it_posts_ask_when_the_switch_is_on_and_off_when_it_is_not(): void {
		$on = self::row();
		self::assertStringContainsString( 'name="stonewright_section_reuse" value="off"', $on, 'The hidden field is what an unchecked switch posts.' );
		self::assertStringContainsString( 'value="ask" checked aria-describedby', $on );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		$off = self::row();
		self::assertStringContainsString( 'value="ask" aria-describedby', $off );
		self::assertStringNotContainsString( ' checked', $off );
	}

	public function test_the_info_callout_shows_only_while_the_setting_is_on_and_is_announced_politely(): void {
		$on = self::row();
		self::assertStringContainsString( 'sw-ui-callout--info', $on );
		self::assertStringContainsString( '<div aria-live="polite"><div class="sw-ui-callout', $on );
		self::assertStringContainsString( 'never changes the page it copies from', $on );

		$GLOBALS['stonewright_test_options'][ SectionReuseSetting::OPTION ] = 'off';
		self::assertStringNotContainsString( 'sw-ui-callout', self::row() );
	}

	public function test_production_safe_mode_says_what_changes(): void {
		self::assertStringNotContainsString( 'production-safe', self::row() );

		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		self::assertStringContainsString( 'In production-safe mode a copy needs no confirmation token', self::row() );
	}

	public function test_the_setting_goes_through_the_settings_api_with_a_sanitize_callback_in_the_stonewright_group(): void {
		SectionReuseSetting::register_setting();

		$registered = $GLOBALS['stonewright_test_registered_settings'];
		self::assertCount( 1, $registered );
		self::assertSame( 'stonewright_settings', $registered[0]['group'], 'The group of the Setup form.' );
		self::assertStringContainsString( 'settings_fields( ConfigurationPage::OPTION_GROUP )', (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/Setup/SettingsForm.php' ) );
		self::assertSame( 'stonewright_section_reuse', $registered[0]['option'] );
		self::assertSame( [ SectionReuseSetting::class, 'sanitize' ], $registered[0]['args']['sanitize_callback'] );
		self::assertSame( 'ask', $registered[0]['args']['default'] );
		self::assertFalse( $registered[0]['args']['show_in_rest'], 'The setting is not exposed over REST.' );
	}

	public function test_the_row_renders_inside_the_setup_form_after_the_elementor_v4_field(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/Setup/SettingsForm.php' );
		$form   = strpos( $source, "'stonewright-settings-form sw-ui-stack'" );
		$v4     = strpos( $source, "'stonewright_elementor_v4_atomic'" );
		$call   = strpos( $source, 'SectionReuseRow::html()' );
		$next   = strpos( $source, "'Stock images'" );

		self::assertNotFalse( $form );
		self::assertNotFalse( $v4 );
		self::assertNotFalse( $call );
		self::assertGreaterThan( $v4, $call, 'The row follows the Elementor V4 row.' );
		self::assertLessThan( $next, $call, 'The row is in the settings table, before the next card.' );
		self::assertSame( 1, substr_count( $source, '<form' ) + substr_count( $source, "'form'" ), 'There is one form, so the row saves with the nonce of the Settings API form.' );
	}
}
