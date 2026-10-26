<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\EditorSaveGuard;
use Stonewright\WpMcp\Elementor\WritePacing;
use Stonewright\WpMcp\Security\LeashGuard;
use Stonewright\WpMcp\Support\AgentNotices;
use Stonewright\WpMcp\Support\ElementorData;

/**
 * @covers \Stonewright\WpMcp\Elementor\WritePacing
 */
final class WritePacingTest extends TestCase {

	private const POST = 4242;
	private const T0   = 1_800_000_000;

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
		unset( $GLOBALS['stonewright_test_serving_rest'], $GLOBALS['wp_rest_auth_cookie'] );
		WritePacing::reset_for_tests();
	}

	protected function tearDown(): void {
		WritePacing::reset_for_tests();
		AgentNotices::reset_for_tests();
		$GLOBALS['stonewright_test_options']        = [];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_transient_ttls'] = [];
	}

	private static function save( int $times, int $first_at = self::T0, int $every = 30 ): void {
		for ( $i = 0; $i < $times; $i++ ) {
			WritePacing::note_save( self::POST, $first_at + $i * $every );
		}
	}

	public function test_third_save_in_ten_minutes_raises_the_warning(): void {
		self::save( 2 );
		self::assertNull( WritePacing::notice(), 'Two saves are fine.' );

		self::save( 1, self::T0 + 60 );
		$notice = WritePacing::notice();

		self::assertIsArray( $notice );
		self::assertSame( [ [ 'post_id' => self::POST, 'saves' => 3 ] ], $notice['posts'] );
		self::assertSame( 10, $notice['window_minutes'] );
		self::assertStringContainsString( 'elementor-v3-batch-mutate', $notice['hint'] );
	}

	public function test_the_warning_travels_as_a_response_field(): void {
		AgentNotices::register_field( 'write_pacing', [ WritePacing::class, 'notice' ] );
		self::save( 3 );

		$fields = AgentNotices::fields();

		self::assertArrayHasKey( 'write_pacing', $fields );
	}

	public function test_sixth_save_is_refused_only_in_strict_mode(): void {
		self::save( 5 );
		self::assertNull( WritePacing::check( self::POST, self::T0 + 200 ), 'In warn mode nothing is refused, however many saves.' );

		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		$refused = WritePacing::check( self::POST, self::T0 + 200 );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_write_pacing', $refused->get_error_code() );
		$data = $refused->get_error_data();
		self::assertSame( 429, $data['status'] );
		self::assertSame( self::T0 + 600 - ( self::T0 + 200 ), $data['retry_after'] );
		self::assertStringContainsString( 'one elementor-v3-batch-mutate apply', $data['hint'] );
	}

	public function test_strict_mode_lets_the_first_five_saves_through(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		self::save( 4 );

		self::assertNull( WritePacing::check( self::POST, self::T0 + 150 ), 'The fifth save is allowed.' );
		self::save( 1, self::T0 + 150 );
		self::assertInstanceOf( \WP_Error::class, WritePacing::check( self::POST, self::T0 + 160 ) );
	}

	public function test_counter_resets_after_the_window(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		self::save( 5 );
		self::assertInstanceOf( \WP_Error::class, WritePacing::check( self::POST, self::T0 + 599 ) );

		self::assertNull( WritePacing::check( self::POST, self::T0 + 600 ), 'The window has passed.' );
		$after = WritePacing::note_save( self::POST, self::T0 + 700 );
		self::assertSame( 1, $after['count'] );
		self::assertSame( self::T0 + 700, $after['since'] );
	}

	public function test_the_window_is_started_by_the_first_save_and_not_extended(): void {
		WritePacing::note_save( self::POST, self::T0 );
		$second = WritePacing::note_save( self::POST, self::T0 + 500 );

		self::assertSame( self::T0, $second['since'] );
		self::assertSame( 100, $GLOBALS['stonewright_test_transient_ttls'][ WritePacing::TRANSIENT_PREFIX . self::POST ] );
	}

	public function test_each_post_has_its_own_count(): void {
		WritePacing::note_save( 1, self::T0 );
		WritePacing::note_save( 1, self::T0 + 1 );
		WritePacing::note_save( 2, self::T0 + 2 );

		self::assertSame( 2, get_transient( WritePacing::TRANSIENT_PREFIX . '1' )['count'] );
		self::assertSame( 1, get_transient( WritePacing::TRANSIENT_PREFIX . '2' )['count'] );
	}

	public function test_off_counts_nothing_and_says_nothing(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'off';
		self::save( 8 );

		self::assertNull( WritePacing::notice() );
		self::assertNull( WritePacing::check( self::POST, self::T0 + 10 ) );
		self::assertFalse( get_transient( WritePacing::TRANSIENT_PREFIX . self::POST ) );
	}

	public function test_an_unknown_setting_reads_as_the_default_warn(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'banana';
		self::assertSame( 'warn', WritePacing::mode() );
		unset( $GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] );
		self::assertSame( 'warn', WritePacing::mode() );
	}

	public function test_the_limits_can_be_changed_with_a_filter(): void {
		add_filter(
			'stonewright_elementor_write_pacing_limits',
			static fn ( array $limits ): array => array_merge( $limits, [ 'warn_at' => 2, 'window' => 60 ] )
		);
		self::save( 2, self::T0, 10 );

		self::assertNotNull( WritePacing::notice() );
		self::assertSame( 1, WritePacing::note_save( self::POST, self::T0 + 61 )['count'], 'A shorter window ends sooner.' );
	}

	public function test_human_editor_saves_count_too(): void {
		$document = new class() {
			public function get_main_id(): int {
				return WritePacingTest::POST_ID;
			}
		};
		for ( $i = 0; $i < 3; $i++ ) {
			EditorSaveGuard::on_after_save( $document );
		}

		$notice = WritePacing::notice();
		self::assertIsArray( $notice );
		self::assertSame( 3, $notice['posts'][0]['saves'] );
	}

	public function test_a_strict_site_refuses_the_editors_sixth_save_before_it_persists(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		$document = new class() {
			public function get_main_id(): int {
				return WritePacingTest::POST_ID;
			}
		};
		for ( $i = 0; $i < 5; $i++ ) {
			EditorSaveGuard::on_after_save( $document );
		}

		$this->expectException( \RuntimeException::class );
		EditorSaveGuard::on_before_save( $document );
	}

	public function test_a_mcp_writer_is_refused_in_strict_mode_and_counted_when_it_saves(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		for ( $i = 0; $i < 5; $i++ ) {
			WritePacing::note_save( self::POST_ID );
		}

		self::assertFalse( ElementorData::write( self::POST_ID, [] ) );
		$error = ElementorData::write_error_for_ability();
		self::assertSame( 'stonewright_write_pacing', $error->get_error_code() );
	}

	public const POST_ID = self::POST;

	public function test_only_a_person_in_wp_admin_loosens_the_setting(): void {
		add_filter( 'pre_update_option_' . WritePacing::OPTION, [ LeashGuard::class, 'guard_write_pacing_write' ], 10, 2 );
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		$GLOBALS['stonewright_test_serving_rest']                   = true;
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );

		update_option( WritePacing::OPTION, 'off' );
		self::assertSame( 'strict', get_option( WritePacing::OPTION ), 'strict to off is refused.' );
		update_option( WritePacing::OPTION, 'warn' );
		self::assertSame( 'strict', get_option( WritePacing::OPTION ), 'strict to warn is refused.' );

		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'warn';
		update_option( WritePacing::OPTION, 'off' );
		self::assertSame( 'warn', get_option( WritePacing::OPTION ), 'warn to off is refused.' );
		update_option( WritePacing::OPTION, 'strict' );
		self::assertSame( 'strict', get_option( WritePacing::OPTION ), 'Making it stricter stays open.' );

		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'valid-nonce';
		update_option( WritePacing::OPTION, 'off' );
		self::assertSame( 'off', get_option( WritePacing::OPTION ), 'A person in wp-admin may.' );
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['stonewright_test_serving_rest'], $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['stonewright_test_user_logged_in'] = false;
		$GLOBALS['stonewright_test_user_caps']      = [];
	}

	public function test_the_guard_and_the_response_field_are_hooked_at_boot(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString( "'pre_update_option_stonewright_elementor_write_pacing', [ LeashGuard::class, 'guard_write_pacing_write' ]", $source );
		self::assertStringContainsString( "AgentNotices::register_field( 'write_pacing', [ WritePacing::class, 'notice' ] )", $source );
	}

	public function test_editor_autosaves_and_revisions_are_not_counted_or_refused(): void {
		$GLOBALS['stonewright_test_options'][ WritePacing::OPTION ] = 'strict';
		$autosave = new class() {
			public function get_main_id(): int {
				return WritePacingTest::POST_ID;
			}

			public function get_id(): int {
				return WritePacingTest::POST_ID + 1000;
			}
		};
		for ( $i = 0; $i < 8; $i++ ) {
			EditorSaveGuard::on_before_save( $autosave );
			EditorSaveGuard::on_after_save( $autosave );
		}

		self::assertNull( WritePacing::notice() );
		self::assertFalse( get_transient( WritePacing::TRANSIENT_PREFIX . self::POST ), 'Eight autosaves count for nothing.' );
	}
}
