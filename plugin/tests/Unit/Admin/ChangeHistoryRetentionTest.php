<?php
/**
 * What the Changes page and Setup say about how long the change history is kept, and the settings that change it.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ChangeHistoryRetention;
use Stonewright\WpMcp\Admin\ChangesPage;
use Stonewright\WpMcp\Admin\ConfigurationPage;
use Stonewright\WpMcp\Admin\MenuRegistry;
use Stonewright\WpMcp\Security\ChangeLedgerRetention;
use Stonewright\WpMcp\Tests\Unit\Admin\Fixtures\LedgerFixture;

/**
 * @covers \Stonewright\WpMcp\Admin\ChangeHistoryRetention
 */
final class ChangeHistoryRetentionTest extends TestCase {

	use LedgerFixture;

	protected function setUp(): void {
		$this->ledger_up();
		$GLOBALS['stonewright_test_user_caps']          = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']            = [ 'stonewright_mode' => 'development' ];
		$GLOBALS['stonewright_test_settings_errors']    = [];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks']    = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_wp_die_response'] );
		$_GET  = [];
		$_POST = [];
		MenuRegistry::reset_for_tests();
	}

	protected function tearDown(): void {
		$this->ledger_down();
		$GLOBALS['stonewright_test_user_caps']          = [];
		$GLOBALS['stonewright_test_options']            = [];
		$GLOBALS['stonewright_test_settings_errors']    = [];
		$GLOBALS['stonewright_test_registered_settings'] = [];
		$GLOBALS['stonewright_test_scheduled_hooks']    = [];
		unset( $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_wp_die_response'] );
		$_GET  = [];
		$_POST = [];
		MenuRegistry::reset_for_tests();
	}

	/** A post that options.php would hand to the sanitizers: its group, its nonce and the fields. */
	private static function settings_post( array $fields = [] ): void {
		$_POST = array_merge( [ 'option_page' => ConfigurationPage::OPTION_GROUP, '_wpnonce' => 'fixture-nonce' ], $fields );
	}

	private function history_html(): string {
		$_GET = [];
		ob_start();
		try {
			ChangesPage::render();

			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	private static function receipt( int $deleted, string $status = 'completed', string $run_at = '2026-09-10T03:00:00+00:00' ): void {
		update_option(
			ChangeLedgerRetention::RECEIPT_OPTION,
			[
				'status'       => $status,
				'run_at'       => $run_at,
				'deleted_rows' => $deleted,
			]
		);
	}

	/** Ages every change on the clock, so the daily cleanup would have work to do. */
	private function age_the_ledger( int $days ): void {
		$this->now += $days * 86400;
	}

	// ---- the info line ------------------------------------------------------------------------------

	public function test_the_info_line_states_the_limits_the_automatic_removal_and_what_is_kept(): void {
		$html = ChangeHistoryRetention::info_html();

		self::assertStringContainsString( 'sw-changes-retention', $html );
		self::assertStringContainsString( '90 days', $html );
		self::assertStringContainsString( '500 changes', $html );
		self::assertStringContainsString( '100 MB', $html );
		self::assertStringContainsString( 'removed automatically', $html );
		self::assertStringContainsString( 'open incidents', strtolower( $html ) );
		self::assertStringContainsString( 'Undo and Redo', $html );
	}

	public function test_without_a_receipt_the_last_cleanup_is_none_yet(): void {
		$html = ChangeHistoryRetention::info_html();

		self::assertStringContainsString( 'Last cleanup: none yet', $html );
	}

	public function test_with_a_receipt_the_last_cleanup_shows_its_date_and_the_number_removed(): void {
		self::receipt( 12 );

		$html = ChangeHistoryRetention::info_html();

		self::assertStringContainsString( 'Last cleanup:', $html );
		self::assertStringContainsString( '<time datetime="2026-09-10T03:00:00Z"', $html );
		self::assertStringContainsString( 'Sep 10, 2026', $html );
		self::assertStringContainsString( '12 changes removed', $html );
		self::assertStringNotContainsString( 'none yet', $html );

		self::receipt( 1 );
		self::assertStringContainsString( '1 change removed', ChangeHistoryRetention::info_html() );

		self::receipt( 0 );
		self::assertStringContainsString( '0 changes removed', ChangeHistoryRetention::info_html() );
	}

	public function test_a_receipt_that_failed_or_never_reached_the_ledger_is_told_apart(): void {
		self::receipt( 3, 'failed' );
		self::assertStringContainsString( 'stopped by an error', ChangeHistoryRetention::info_html() );
		self::assertStringContainsString( '3 changes removed', ChangeHistoryRetention::info_html() );

		self::receipt( 0, 'unavailable' );
		self::assertStringContainsString( 'Last cleanup: none yet', ChangeHistoryRetention::info_html() );

		update_option( ChangeLedgerRetention::RECEIPT_OPTION, 'garbage' );
		self::assertStringContainsString( 'Last cleanup: none yet', ChangeHistoryRetention::info_html() );
	}

	public function test_the_info_line_shows_the_limits_that_are_in_force_including_a_filter(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 1200 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 250 * 1048576 );
		$html = ChangeHistoryRetention::info_html();
		self::assertStringContainsString( '30 days', $html );
		self::assertStringContainsString( '1,200 changes', $html );
		self::assertStringContainsString( '250 MB', $html );

		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_retention_days'] = static fn (): int => 14;
		self::assertStringContainsString( '14 days', ChangeHistoryRetention::info_html() );
	}

	public function test_the_change_limits_link_goes_to_the_settings_and_only_an_administrator_sees_it(): void {
		$html = ChangeHistoryRetention::info_html();
		self::assertMatchesRegularExpression( '#<a [^>]*href="[^"]*page=stonewright&(amp;)?tab=settings\#stonewright-change-history"[^>]*>[^<]*Change limits#', $html );

		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false ];
		$plain                                 = ChangeHistoryRetention::info_html();
		self::assertStringNotContainsString( 'Change limits', $plain );
		self::assertStringNotContainsString( 'tab=settings', $plain );
		self::assertStringContainsString( '90 days', $plain, 'The line itself stays.' );
	}

	public function test_the_history_tab_always_prints_the_info_line_above_the_filters(): void {
		$html = $this->history_html();

		self::assertSame( 1, substr_count( $html, 'sw-changes-retention' ) );
		self::assertStringContainsString( 'Last cleanup: none yet', $html );
		self::assertLessThan( (int) strpos( $html, 'sw-changes-filters' ), (int) strpos( $html, 'sw-changes-retention' ), 'The line is near the top.' );
		self::assertStringContainsString( 'Change limits', $html );
	}

	public function test_the_rescue_tab_does_not_print_the_history_info_line(): void {
		$_GET = [ 'tab' => 'rescue' ];
		ob_start();
		try {
			ChangesPage::render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
		}

		self::assertStringNotContainsString( 'sw-changes-retention', $html );
	}

	// ---- the advance warning ------------------------------------------------------------------------

	public function test_a_quiet_history_shows_no_warning(): void {
		$this->seed_change();

		self::assertSame( '', ChangeHistoryRetention::warning_html() );
		self::assertStringNotContainsString( 'sw-ui-notice--warn', $this->history_html() );
	}

	public function test_a_history_that_will_lose_changes_shows_a_warning_not_an_error_with_the_count_and_the_date(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed_change( [ 'resource_id' => (string) ( 100 + $i ) ], [ 'n' => $i ], [ 'n' => $i + 10 ] );
		}
		$this->age_the_ledger( 24 );
		$GLOBALS['stonewright_test_scheduled_hooks'][ ChangeLedgerRetention::HOOK ] = $this->now + 3600;

		$html = ChangeHistoryRetention::warning_html();

		self::assertStringContainsString( 'sw-ui-notice--warn', $html );
		self::assertStringNotContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( '3 finished changes will be removed', $html );
		self::assertStringContainsString( 'by <time', $html );
		self::assertStringContainsString( gmdate( 'M j, Y', $this->now + 3600 + 6 * 86400 ), $html );
		self::assertStringContainsString( 'Change limits', $html );
		self::assertStringContainsString( 'sw-ui-notice--warn', $this->history_html(), 'The History tab prints it.' );
	}

	public function test_a_history_near_its_count_limit_warns_before_anything_is_removed(): void {
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 5 );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->seed_change( [ 'resource_id' => (string) ( 200 + $i ) ], [ 'n' => $i ], [ 'n' => $i + 10 ] );
		}

		$html = ChangeHistoryRetention::warning_html();

		self::assertStringContainsString( 'sw-ui-notice--warn', $html );
		self::assertStringContainsString( '80%', $html );
		self::assertStringContainsString( '5 changes', $html );
		self::assertStringNotContainsString( 'will be removed by', $html );
	}

	public function test_a_single_change_is_worded_in_the_singular(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		$this->seed_change();
		$this->age_the_ledger( 40 );

		self::assertStringContainsString( '1 finished change will be removed', ChangeHistoryRetention::warning_html() );
	}

	// ---- registering and validating the settings ----------------------------------------------------

	public function test_the_three_limits_are_registered_with_the_existing_settings_group(): void {
		ConfigurationPage::register_settings();

		$by_option = [];
		foreach ( $GLOBALS['stonewright_test_registered_settings'] as $setting ) {
			$by_option[ $setting['option'] ] = $setting;
		}
		foreach ( [ ChangeLedgerRetention::DAYS_OPTION, ChangeLedgerRetention::MAX_CHANGES_OPTION, ChangeLedgerRetention::MAX_BYTES_OPTION ] as $option ) {
			self::assertArrayHasKey( $option, $by_option, $option );
			self::assertSame( ConfigurationPage::OPTION_GROUP, $by_option[ $option ]['group'] );
			self::assertTrue( is_callable( $by_option[ $option ]['args']['sanitize_callback'] ) );
			self::assertSame( 'integer', $by_option[ $option ]['args']['type'] );
		}
	}

	public function test_a_valid_value_is_saved_as_a_whole_number(): void {
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '250' ] );

		self::assertSame( 45, ChangeHistoryRetention::sanitize_days( '45' ) );
		self::assertSame( 3650, ChangeHistoryRetention::sanitize_days( ' 3650 ' ) );
		self::assertSame( 1, ChangeHistoryRetention::sanitize_days( 1 ) );
		self::assertSame( 1200, ChangeHistoryRetention::sanitize_max_changes( '1200' ) );
		self::assertSame( 100000, ChangeHistoryRetention::sanitize_max_changes( '100000' ) );
		self::assertSame( 250 * 1048576, ChangeHistoryRetention::sanitize_max_bytes( null ) );
		self::assertSame( [], get_settings_errors( ChangeHistoryRetention::ERRORS ) );
	}

	public function test_the_megabytes_of_the_form_become_bytes_and_ten_gigabytes_is_the_ceiling(): void {
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '10240' ] );
		self::assertSame( ChangeLedgerRetention::MAX_MAX_BYTES, ChangeHistoryRetention::sanitize_max_bytes( null ) );

		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '1' ] );
		self::assertSame( 1048576, ChangeHistoryRetention::sanitize_max_bytes( null ) );
		self::assertSame( [], get_settings_errors( ChangeHistoryRetention::ERRORS ) );
	}

	/** @return array<string, array{0: string, 1: mixed}> */
	public static function refusedDays(): array {
		return [
			'zero'      => [ 'days', '0' ],
			'negative'  => [ 'days', '-3' ],
			'text'      => [ 'days', 'forever' ],
			'fraction'  => [ 'days', '1.5' ],
			'empty'     => [ 'days', '' ],
			'too long'  => [ 'days', '3651' ],
			'huge'      => [ 'days', '99999999999999999999' ],
			'array'     => [ 'days', [ '30' ] ],
			'zero count' => [ 'changes', '0' ],
			'too many'  => [ 'changes', '100001' ],
			'count text' => [ 'changes', 'many' ],
			'zero mb'   => [ 'mb', '0' ],
			'too big'   => [ 'mb', '10241' ],
			'mb text'   => [ 'mb', 'a lot' ],
			'mb fraction' => [ 'mb', '2.5' ],
			'mb empty'  => [ 'mb', '' ],
		];
	}

	/** @dataProvider refusedDays */
	public function test_a_value_outside_the_bounds_is_refused_with_a_message_and_the_previous_value_stays( string $field, mixed $value ): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 60 );
		update_option( ChangeLedgerRetention::MAX_CHANGES_OPTION, 300 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 20 * 1048576 );
		self::settings_post( 'mb' === $field ? [ ChangeHistoryRetention::FIELD_MB => $value ] : [] );

		$saved = match ( $field ) {
			'days'    => ChangeHistoryRetention::sanitize_days( $value ),
			'changes' => ChangeHistoryRetention::sanitize_max_changes( $value ),
			default   => ChangeHistoryRetention::sanitize_max_bytes( null ),
		};

		self::assertSame( [ 'days' => 60, 'changes' => 300, 'mb' => 20 * 1048576 ][ $field ], $saved, 'The stored value is returned, so nothing changes.' );
		$errors = get_settings_errors( ChangeHistoryRetention::ERRORS );
		self::assertCount( 1, $errors );
		self::assertSame( 'error', $errors[0]['type'] );
		self::assertStringContainsString( 'whole number from 1 to ' . [ 'days' => '3,650', 'changes' => '100,000', 'mb' => '10,240' ][ $field ], $errors[0]['message'] );
		self::assertStringContainsString( 'previous value was kept', $errors[0]['message'] );
	}

	public function test_a_refused_value_with_nothing_stored_keeps_the_default_without_writing_an_option(): void {
		self::assertFalse( ChangeHistoryRetention::sanitize_days( '0' ), 'An unset option stays unset, so the default applies.' );
		self::assertSame( 90, ChangeLedgerRetention::days() );
	}

	public function test_a_value_equal_to_the_default_does_not_pin_an_option_that_was_never_set(): void {
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '100' ] );

		self::assertFalse( ChangeHistoryRetention::sanitize_days( '90' ) );
		self::assertFalse( ChangeHistoryRetention::sanitize_max_changes( '500' ) );
		self::assertFalse( ChangeHistoryRetention::sanitize_max_bytes( null ) );

		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		self::assertSame( 90, ChangeHistoryRetention::sanitize_days( '90' ), 'A stored value is replaced by the default the person typed.' );
	}

	public function test_a_post_without_the_fields_changes_nothing(): void {
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 20 * 1048576 );
		self::settings_post();

		self::assertSame( 20 * 1048576, ChangeHistoryRetention::sanitize_max_bytes( null ) );
		self::assertSame( [], get_settings_errors( ChangeHistoryRetention::ERRORS ) );
	}

	public function test_a_size_that_is_not_a_whole_number_of_megabytes_is_kept_when_the_form_shows_it_unchanged(): void {
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 150 * 1048576 + 1000 );
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '150' ] );

		self::assertSame( 150 * 1048576 + 1000, ChangeHistoryRetention::sanitize_max_bytes( null ) );
		self::assertSame( [], get_settings_errors( ChangeHistoryRetention::ERRORS ) );
	}

	public function test_code_that_sets_the_option_without_the_form_is_validated_in_bytes_and_not_asked_for_a_nonce(): void {
		unset( $_POST['option_page'], $_POST['_wpnonce'] );
		$GLOBALS['stonewright_test_user_caps'] = [];

		self::assertSame( 5 * 1048576, ChangeHistoryRetention::sanitize_max_bytes( 5 * 1048576 ) );
		self::assertSame( 20, ChangeHistoryRetention::sanitize_days( 20 ) );
		self::assertArrayNotHasKey( 'stonewright_test_last_wp_die_response', $GLOBALS );
	}

	// ---- permission and nonce -----------------------------------------------------------------------

	/** @return array<string, array{0: string}> */
	public static function sanitizers(): array {
		return [ 'days' => [ 'sanitize_days' ], 'changes' => [ 'sanitize_max_changes' ], 'bytes' => [ 'sanitize_max_bytes' ] ];
	}

	/** @dataProvider sanitizers */
	public function test_a_post_from_someone_who_cannot_manage_options_is_answered_with_403_and_changes_nothing( string $method ): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 60 );
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => false, 'edit_posts' => true ];
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '50' ] );

		try {
			ChangeHistoryRetention::$method( 'sanitize_max_bytes' === $method ? null : '10' );
			self::fail( 'The post must be refused.' );
		} catch ( \RuntimeException $refused ) {
			self::assertStringContainsString( 'wp_die', $refused->getMessage() );
		}
		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
		self::assertSame( 60, ChangeLedgerRetention::days() );
	}

	/** @dataProvider sanitizers */
	public function test_a_post_with_a_bad_or_missing_nonce_is_answered_with_403( string $method ): void {
		self::settings_post( [ ChangeHistoryRetention::FIELD_MB => '50' ] );
		$GLOBALS['stonewright_test_nonce_invalid'] = true;
		try {
			ChangeHistoryRetention::$method( 'sanitize_max_bytes' === $method ? null : '10' );
			self::fail( 'A bad nonce must be refused.' );
		} catch ( \RuntimeException $refused ) {
			self::assertStringContainsString( 'wp_die', $refused->getMessage() );
		}
		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );

		unset( $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_last_wp_die_response'], $_POST['_wpnonce'] );
		try {
			ChangeHistoryRetention::$method( 'sanitize_max_bytes' === $method ? null : '10' );
			self::fail( 'A missing nonce must be refused.' );
		} catch ( \RuntimeException $refused ) {
			self::assertStringContainsString( 'wp_die', $refused->getMessage() );
		}
		self::assertSame( 403, $GLOBALS['stonewright_test_last_wp_die_response'] );
	}

	public function test_the_nonce_is_the_one_of_the_settings_group(): void {
		self::settings_post();
		ChangeHistoryRetention::sanitize_days( '10' );

		self::assertSame( ConfigurationPage::OPTION_GROUP . '-options', $GLOBALS['stonewright_test_last_nonce_action'] );
	}

	// ---- the settings section -----------------------------------------------------------------------

	public function test_the_section_has_three_number_fields_with_the_bounds_and_the_values_in_force(): void {
		update_option( ChangeLedgerRetention::DAYS_OPTION, 45 );
		update_option( ChangeLedgerRetention::MAX_BYTES_OPTION, 250 * 1048576 );

		$html = ChangeHistoryRetention::settings_html();

		self::assertStringContainsString( 'id="stonewright-change-history"', $html );
		self::assertStringContainsString( 'Change history', $html );
		self::assertMatchesRegularExpression( '#<input[^>]*type="number"[^>]*name="stonewright_change_ledger_days"[^>]*#', $html );
		self::assertMatchesRegularExpression( '#name="stonewright_change_ledger_days"[^>]*value="45"#', $html );
		self::assertMatchesRegularExpression( '#name="stonewright_change_ledger_max_changes"[^>]*value="500"#', $html );
		self::assertMatchesRegularExpression( '#name="stonewright_change_ledger_max_mb"[^>]*value="250"#', $html );
		self::assertStringContainsString( 'max="3650"', $html );
		self::assertStringContainsString( 'max="100000"', $html );
		self::assertStringContainsString( 'max="10240"', $html );
		self::assertSame( 3, substr_count( $html, 'min="1"' ) );
		self::assertSame( 3, substr_count( $html, 'class="sw-ui-input' ) );
		self::assertSame( 3, substr_count( $html, '<label' ) );
		self::assertStringNotContainsString( 'style=', $html );
	}

	public function test_the_section_says_a_lower_limit_waits_for_the_daily_cleanup(): void {
		$html = ChangeHistoryRetention::settings_html();

		self::assertStringContainsString( 'A lower limit takes effect at the next daily cleanup', $html );
		self::assertStringContainsString( 'There is no unlimited setting', $html );
		self::assertStringContainsString( 'Open incidents and linked Undo and Redo are always kept', $html );
	}

	public function test_the_section_shows_how_many_changes_the_limits_remove_at_the_next_cleanup(): void {
		self::assertStringContainsString( 'would remove nothing', ChangeHistoryRetention::settings_html() );

		update_option( ChangeLedgerRetention::DAYS_OPTION, 30 );
		for ( $i = 0; $i < 3; $i++ ) {
			$this->seed_change( [ 'resource_id' => (string) ( 300 + $i ) ], [ 'n' => $i ], [ 'n' => $i + 10 ] );
		}
		$this->age_the_ledger( 45 );
		$plan = ChangeLedgerRetention::dry_run( $this->now );

		$html = ChangeHistoryRetention::settings_html();

		self::assertSame( 3, $plan['would_remove'] );
		self::assertStringContainsString( 'would remove 3 finished changes', $html, 'The number is the one the pruner plans.' );
		self::assertCount( 3, $this->db->tables[ $this->db->prefix . 'stonewright_changes' ] ?? [], 'Showing it deleted nothing.' );
	}

	public function test_the_section_names_a_filter_that_overrides_a_stored_limit(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_change_ledger_retention_days'] = static fn (): int => 14;

		$html = ChangeHistoryRetention::settings_html();

		self::assertStringContainsString( 'value="90"', $html, 'The field shows what the option holds, so a save does not store the filter value.' );
		self::assertStringContainsString( '14 days', $html );
		self::assertStringContainsString( 'filter', $html );
	}

	public function test_a_refusal_after_a_save_is_shown_as_an_error_notice_with_every_message(): void {
		add_settings_error( ChangeHistoryRetention::ERRORS, 'days', 'Days: enter a whole number from 1 to 3650. The previous value was kept.' );
		add_settings_error( 'other', 'x', 'Not ours.' );

		$html = ChangeHistoryRetention::refusal_html();

		self::assertStringContainsString( 'sw-ui-notice--danger', $html );
		self::assertStringContainsString( 'Days: enter a whole number from 1 to 3650.', $html );
		self::assertStringNotContainsString( 'Not ours.', $html );
		self::assertSame( '', ( static function (): string {
			$GLOBALS['stonewright_test_settings_errors'] = [];

			return ChangeHistoryRetention::refusal_html();
		} )() );
	}
}
