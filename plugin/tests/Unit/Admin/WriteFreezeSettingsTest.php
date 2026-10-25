<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\Setup\SettingsForm;
use Stonewright\WpMcp\Admin\WriteFreezeSettings;
use Stonewright\WpMcp\Security\WriteFreeze;

/**
 * @covers \Stonewright\WpMcp\Admin\WriteFreezeSettings
 */
final class WriteFreezeSettingsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_settings_errors'] = [];
		$GLOBALS['stonewright_test_filters']         = [];
		unset( $GLOBALS['stonewright_test_timezone'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_settings_errors'] = [];
		unset( $GLOBALS['stonewright_test_timezone'] );
	}

	/** The control's own format for a moment $seconds from now, in UTC. */
	private static function field( int $seconds ): string {
		return gmdate( 'Y-m-d\TH:i', time() + $seconds );
	}

	public function test_settings_sanitize_stores_the_time_and_reason(): void {
		$clean = WriteFreezeSettings::sanitize_freeze( [ 'until' => self::field( 7200 ), 'reason' => '  Launch <b>day</b>  ' ] );

		self::assertSame( 'Launch day', $clean['reason'] );
		self::assertSame( strtotime( self::field( 7200 ) . ':00 UTC' ), $clean['until'] );
		self::assertSame( [], $GLOBALS['stonewright_test_settings_errors'] );
	}

	public function test_settings_sanitize_rejects_a_past_time_and_caps_the_reason_at_191_chars(): void {
		$old = [ 'until' => time() + 3600, 'reason' => 'Existing' ];
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $old;

		$clean = WriteFreezeSettings::sanitize_freeze( [ 'until' => self::field( -3600 ), 'reason' => 'x' ] );

		self::assertSame( $old, $clean, 'The stored freeze stays.' );
		self::assertCount( 1, get_settings_errors( WriteFreezeSettings::ERRORS ) );
		self::assertStringContainsString( 'in the future', get_settings_errors( WriteFreezeSettings::ERRORS )[0]['message'] );

		$GLOBALS['stonewright_test_options'] = [];
		$clean = WriteFreezeSettings::sanitize_freeze( [ 'until' => self::field( 600 ), 'reason' => str_repeat( 'é', 400 ) ] );
		self::assertSame( WriteFreeze::MAX_REASON, mb_strlen( $clean['reason'] ) );
	}

	public function test_an_empty_time_lifts_the_freeze(): void {
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = [ 'until' => time() + 3600, 'reason' => 'Existing' ];

		self::assertSame( [], WriteFreezeSettings::sanitize_freeze( [ 'until' => '', 'reason' => 'ignored' ] ) );
	}

	public function test_text_that_is_not_a_date_is_refused_and_keeps_the_stored_freeze(): void {
		$old = [ 'until' => time() + 3600, 'reason' => 'Existing' ];
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $old;

		self::assertSame( $old, WriteFreezeSettings::sanitize_freeze( [ 'until' => 'tomorrow', 'reason' => '' ] ) );
		self::assertSame( $old, WriteFreezeSettings::sanitize_freeze( [ 'until' => '2026-13-45T25:61', 'reason' => '' ] ) );
		self::assertSame( $old, WriteFreezeSettings::sanitize_freeze( 'not an array' ) );
		self::assertCount( 2, get_settings_errors( WriteFreezeSettings::ERRORS ) );
	}

	public function test_the_time_is_read_in_the_site_timezone(): void {
		$GLOBALS['stonewright_test_timezone'] = 'America/New_York';
		$local                                = ( new \DateTimeImmutable( '+2 hours', new \DateTimeZone( 'America/New_York' ) ) )->format( 'Y-m-d\TH:i' );

		$clean = WriteFreezeSettings::sanitize_freeze( [ 'until' => $local, 'reason' => '' ] );

		self::assertSame( ( new \DateTimeImmutable( $local, new \DateTimeZone( 'America/New_York' ) ) )->getTimestamp(), $clean['until'] );
	}

	public function test_a_form_posted_back_as_shown_does_not_change_the_freeze(): void {
		$stored = [ 'until' => time() + 3600 + 37, 'reason' => 'Existing' ];
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $stored;

		$clean = WriteFreezeSettings::sanitize_freeze( [ 'until' => gmdate( 'Y-m-d\TH:i', $stored['until'] ), 'reason' => 'Existing' ] );

		self::assertSame( $stored, $clean, 'The seconds of a freeze set by code survive a save of the form.' );
	}

	public function test_the_card_shows_the_active_freeze_and_empty_fields_otherwise(): void {
		$empty = WriteFreezeSettings::settings_html();
		self::assertStringContainsString( 'name="stonewright_write_freeze[until]"', $empty );
		self::assertStringContainsString( 'type="datetime-local"', $empty );
		self::assertStringContainsString( 'name="stonewright_write_freeze[reason]"', $empty );
		self::assertStringContainsString( 'Rollbacks keep working', $empty );
		self::assertStringNotContainsString( 'Writes are frozen until', $empty );

		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = [ 'until' => time() + 3600, 'reason' => 'Launch day' ];
		$active = WriteFreezeSettings::settings_html();
		self::assertStringContainsString( 'Writes are frozen until', $active );
		self::assertStringContainsString( 'value="Launch day"', $active );

		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = [ 'until' => time() - 3600, 'reason' => 'Done' ];
		self::assertStringNotContainsString( 'value="Done"', WriteFreezeSettings::settings_html(), 'An expired freeze is not offered again.' );
	}

	public function test_the_card_uses_the_shared_form_components_and_no_page_sizing(): void {
		$html = WriteFreezeSettings::settings_html();
		self::assertStringContainsString( 'sw-ui-field', $html );
		self::assertStringContainsString( 'sw-ui-input', $html );
		self::assertStringNotContainsString( 'style=', $html );
	}

	public function test_the_settings_form_includes_the_card_and_the_refusal(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/Setup/SettingsForm.php' );
		self::assertStringContainsString( 'WriteFreezeSettings::settings_html()', $source );
		self::assertStringContainsString( 'WriteFreezeSettings::refusal_html()', $source );
		self::assertTrue( class_exists( SettingsForm::class ) );
	}

	public function test_a_change_is_audited_and_a_save_that_changes_nothing_is_not(): void {
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		WriteFreezeSettings::audit_change( [ 'until' => 5, 'reason' => '' ], [ 'until' => 5, 'reason' => 'other reason' ] );
		self::assertSame( [], $GLOBALS['stonewright_test_wpdb_inserts'], 'The same end time is not a change.' );

		WriteFreezeSettings::audit_change( [], [ 'until' => time() + 3600, 'reason' => 'x' ] );
		self::assertCount( 1, $GLOBALS['stonewright_test_wpdb_inserts'] );
		self::assertStringContainsString( 'write-freeze-setting', (string) json_encode( $GLOBALS['stonewright_test_wpdb_inserts'][0]['data'] ) );
	}
}
