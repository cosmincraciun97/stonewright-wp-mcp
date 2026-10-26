<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Security\LeashGuard;
use Stonewright\WpMcp\Security\WriteFreeze;
use Stonewright\WpMcp\Tests\Unit\Core\Fixtures\RegisteredAbilityRig;

/**
 * @covers \Stonewright\WpMcp\Security\WriteFreeze
 * @covers \Stonewright\WpMcp\Security\LeashGuard::guard_write_freeze_write
 * @covers \Stonewright\WpMcp\Core\AbilityRegistry::authorize_execution
 */
final class WriteFreezeTest extends TestCase {
	use RegisteredAbilityRig;

	private const WRITE = 'stonewright/content-update-page';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']        = [
			'stonewright_enabled'              => true,
			'stonewright_disabled_abilities'   => [],
			'stonewright_mode'                 => 'development',
			'stonewright_essential_tools_mode' => true,
			'stonewright_mcp_surface'          => 'essential',
		];
		$GLOBALS['stonewright_test_filters']        = [];
		$GLOBALS['stonewright_test_transients']     = [];
		$GLOBALS['stonewright_test_user_logged_in'] = true;
		$GLOBALS['stonewright_test_user_caps']      = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_rest_routes']    = [];
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_serving_rest'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'], $GLOBALS['stonewright_test_nonce_invalid'], $GLOBALS['stonewright_test_serving_rest'] );
		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		$GLOBALS['stonewright_test_options']     = [];
		$GLOBALS['stonewright_test_filters']     = [];
		$GLOBALS['stonewright_test_user_caps']   = [];
		$GLOBALS['stonewright_test_transients']  = [];
		$GLOBALS['stonewright_test_rest_routes'] = [];
	}

	private static function freeze( int $seconds_from_now, string $reason = 'Launch day' ): void {
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = [ 'until' => time() + $seconds_from_now, 'reason' => $reason ];
	}

	private function authorize( string $name ): ?\WP_Error {
		$ability = AbilityRegistry::ability_by_name( $name );
		self::assertNotNull( $ability, $name );

		return AbilityRegistry::authorize_execution( $ability );
	}

	public function test_active_freeze_refuses_a_write_ability_with_the_end_time_and_reason(): void {
		self::freeze( 3600, 'Launch day' );

		$refused = $this->authorize( self::WRITE );

		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'stonewright_write_freeze', $refused->get_error_code() );
		$data  = $refused->get_error_data();
		$until = (int) $GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ]['until'];
		self::assertSame( 423, $data['status'] );
		self::assertTrue( $data['agent_must_stop'] );
		self::assertSame( 'blocked', $data['execution_status'] );
		self::assertStringContainsString( 'Launch day', $refused->get_error_message() );
		self::assertStringContainsString( (string) wp_date( 'Y-m-d H:i', $until ), $refused->get_error_message() );
		self::assertSame( gmdate( 'c', $until ), $data['until'] );
		self::assertStringContainsString( 'page=stonewright', $data['settings_url'] );
	}

	public function test_reads_task_start_and_rollbacks_pass_during_a_freeze(): void {
		self::freeze( 3600 );

		foreach ( [ 'stonewright/site-info', 'stonewright/task-start', 'stonewright/rescue-status', 'stonewright/rescue-rollback', 'stonewright/change-rollback', 'stonewright/change-restore', 'stonewright/learning-record', 'stonewright/security-issue-confirmation-token' ] as $name ) {
			$ability = AbilityRegistry::ability_by_name( $name );
			if ( null === $ability ) {
				continue;
			}
			$refused = AbilityRegistry::authorize_execution( $ability );
			self::assertFalse( $refused instanceof \WP_Error && 'stonewright_write_freeze' === $refused->get_error_code(), $name . ' is not frozen.' );
		}
		foreach ( WriteFreeze::EXEMPT as $name ) {
			self::assertNull( WriteFreeze::gate( $name ), $name );
		}
		self::assertNull( WriteFreeze::gate( 'stonewright/site-info' ) );
	}

	public function test_a_restore_that_is_not_tied_to_the_change_history_stays_frozen(): void {
		self::freeze( 3600 );

		// A post revision or a theme backup can put any older content on the site: that is a write.
		foreach ( [ 'stonewright/post-revision-restore', 'stonewright/theme-backup-restore' ] as $name ) {
			$refused = WriteFreeze::gate( $name );
			self::assertInstanceOf( \WP_Error::class, $refused, $name );
			self::assertSame( 'stonewright_write_freeze', $refused->get_error_code() );
		}
	}

	public function test_an_expired_freeze_is_inactive(): void {
		self::freeze( -5 );

		self::assertFalse( WriteFreeze::active() );
		self::assertNull( $this->authorize( self::WRITE ) );
	}

	public function test_no_freeze_means_no_refusal_and_a_malformed_option_is_ignored(): void {
		self::assertFalse( WriteFreeze::active() );
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = 'frozen';
		self::assertFalse( WriteFreeze::active() );
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = [ 'until' => 'soon' ];
		self::assertFalse( WriteFreeze::active() );
		self::assertNull( $this->authorize( self::WRITE ) );
	}

	public function test_every_exempt_ability_is_a_registered_ability(): void {
		foreach ( WriteFreeze::EXEMPT as $name ) {
			self::assertNotNull( AbilityRegistry::ability_by_name( $name ), $name . ' is not a registered ability, so the exemption does nothing.' );
		}
	}

	public function test_the_option_cannot_be_lifted_outside_wp_admin(): void {
		$old = [ 'until' => time() + 3600, 'reason' => 'Launch day' ];
		add_filter( 'pre_update_option_' . WriteFreeze::OPTION, [ LeashGuard::class, 'guard_write_freeze_write' ], 10, 2 );
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $old;
		$GLOBALS['stonewright_test_serving_rest']                   = true;

		// An agent with an Application Password or a bearer: no cookie, no nonce.
		update_option( WriteFreeze::OPTION, [] );
		self::assertSame( $old, get_option( WriteFreeze::OPTION ), 'Lifting it is refused.' );

		update_option( WriteFreeze::OPTION, [ 'until' => $old['until'] - 1800, 'reason' => 'x' ] );
		self::assertSame( $old, get_option( WriteFreeze::OPTION ), 'Ending it sooner is refused.' );

		$longer = [ 'until' => $old['until'] + 3600, 'reason' => 'Extended' ];
		update_option( WriteFreeze::OPTION, $longer );
		self::assertSame( $longer, get_option( WriteFreeze::OPTION ), 'Making it stricter stays open.' );
	}

	public function test_a_person_in_wp_admin_can_lift_it_through_rest(): void {
		$old = [ 'until' => time() + 3600, 'reason' => 'Launch day' ];
		add_filter( 'pre_update_option_' . WriteFreeze::OPTION, [ LeashGuard::class, 'guard_write_freeze_write' ], 10, 2 );
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $old;
		$GLOBALS['stonewright_test_serving_rest']                   = true;
		$GLOBALS['wp_rest_auth_cookie']                             = true;
		$_SERVER['HTTP_X_WP_NONCE']                                 = 'valid-nonce';

		update_option( WriteFreeze::OPTION, [] );

		self::assertSame( [], get_option( WriteFreeze::OPTION ) );
	}

	public function test_wp_cli_and_cron_are_not_held_back_by_the_guard(): void {
		$old = [ 'until' => time() + 3600, 'reason' => 'Launch day' ];
		add_filter( 'pre_update_option_' . WriteFreeze::OPTION, [ LeashGuard::class, 'guard_write_freeze_write' ], 10, 2 );
		$GLOBALS['stonewright_test_options'][ WriteFreeze::OPTION ] = $old;
		$GLOBALS['stonewright_test_serving_rest']                   = false;

		update_option( WriteFreeze::OPTION, [] );

		self::assertSame( [], get_option( WriteFreeze::OPTION ) );
	}

	public function test_the_guard_and_the_audit_are_hooked_at_boot(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Core/PluginRegistration.php' );

		self::assertStringContainsString( "'pre_update_option_stonewright_write_freeze', [ LeashGuard::class, 'guard_write_freeze_write' ]", $source );
		self::assertStringContainsString( 'WriteFreezeSettings::register()', $source );
	}
}
