<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\RescueSettings;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The option that lets an authenticated agent reach the Stonewright MCP route in safe boot while
 * a rescue incident is open: off by default, a plain on/off value, audited when it changes.
 *
 * @covers \Stonewright\WpMcp\Admin\RescueSettings
 */
final class RescueSettingsTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin( false );
		$GLOBALS['stonewright_test_wpdb_inserts']       = [];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$GLOBALS['stonewright_test_settings_sections']  = [];
		$GLOBALS['stonewright_test_settings_fields']    = [];
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	public function test_the_option_name_is_the_one_the_helper_reads(): void {
		self::assertSame( \Stonewright_Rescue::OPTION_MCP, RescueSettings::OPTION );
	}

	public function test_it_is_off_by_default(): void {
		self::assertFalse( RescueSettings::mcp_safe_boot_enabled() );

		foreach ( [ '', '0', 0, false, 'no', [ '1' ], null ] as $value ) {
			MuRuntime::set_option( RescueSettings::OPTION, $value );
			self::assertFalse( RescueSettings::mcp_safe_boot_enabled(), var_export( $value, true ) );
		}
	}

	public function test_only_an_explicit_on_value_turns_it_on(): void {
		foreach ( [ '1', 1, true, 'on', 'yes' ] as $value ) {
			MuRuntime::set_option( RescueSettings::OPTION, $value );
			self::assertTrue( RescueSettings::mcp_safe_boot_enabled(), var_export( $value, true ) );
		}
	}

	public function test_what_is_saved_is_one_or_an_empty_string(): void {
		foreach ( [ '1', 1, true, 'on', 'yes', 'ON' ] as $value ) {
			self::assertSame( '1', RescueSettings::sanitize( $value ), var_export( $value, true ) );
		}
		foreach ( [ '', '0', 0, false, null, 'no', 'maybe', [ '1' ], 2 ] as $value ) {
			self::assertSame( '', RescueSettings::sanitize( $value ), var_export( $value, true ) );
		}
	}

	public function test_registering_adds_a_section_and_a_field_to_the_general_settings(): void {
		RescueSettings::register();
		MuRuntime::fire( 'admin_init' );

		$registered = $GLOBALS['stonewright_test_registered_settings'];
		self::assertCount( 1, $registered );
		self::assertSame( 'general', $registered[0]['group'] );
		self::assertSame( RescueSettings::OPTION, $registered[0]['option'] );
		self::assertSame( [ RescueSettings::class, 'sanitize' ], $registered[0]['args']['sanitize_callback'] );
		self::assertSame( '', $registered[0]['args']['default'] );
		self::assertSame( 'general', $GLOBALS['stonewright_test_settings_sections'][0]['page'] );
		self::assertSame( 'general', $GLOBALS['stonewright_test_settings_fields'][0]['page'] );
	}

	public function test_the_field_is_an_unchecked_checkbox_that_explains_what_it_does(): void {
		ob_start();
		RescueSettings::render_field();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'type="checkbox"', $html );
		self::assertStringContainsString( 'name="' . RescueSettings::OPTION . '"', $html );
		self::assertStringNotContainsString( 'checked', $html );
		self::assertStringContainsString( 'rescue incident is open', $html );
		self::assertStringContainsString( 'authentication and permission checks', $html );
		self::assertStringContainsString( 'Other plugins, including security plugins, do not run on those requests', $html );
	}

	public function test_the_field_is_checked_when_the_setting_is_on(): void {
		MuRuntime::set_option( RescueSettings::OPTION, '1' );

		ob_start();
		RescueSettings::render_field();

		self::assertStringContainsString( 'checked', (string) ob_get_clean() );
	}

	public function test_a_change_is_audited_with_the_old_and_the_new_value(): void {
		MuRuntime::admin( 7 );
		$GLOBALS['stonewright_test_current_user_id'] = 7;

		RescueSettings::audit_change( '', '1' );

		$rows = array_values( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $row ): bool => 'stonewright/rescue-setting' === ( $row['data']['ability_name'] ?? '' ) ) );
		self::assertCount( 1, $rows );
		self::assertStringContainsString( 'mcp_safe_boot', json_encode( $rows ) ?: '' );
	}

	public function test_an_unchanged_value_is_not_audited(): void {
		RescueSettings::audit_change( '1', '1' );
		RescueSettings::audit_change( '', '' );

		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'] );
	}
}
