<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use Stonewright\WpMcp\Security\LeashGuard;
use Stonewright\WpMcp\Tests\Unit\Security\Adapters\OtherFamilyLedgerTestCase;

/**
 * A loosening that the option backstop keeps out leaves an event in the change ledger, with the names of the
 * modes and abilities and nothing else.
 *
 * @covers \Stonewright\WpMcp\Security\LeashGuard
 */
final class LeashGuardAuditTest extends OtherFamilyLedgerTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['stonewright_test_filters'] = [];
		add_filter( 'pre_update_option_stonewright_mode', [ LeashGuard::class, 'guard_mode_write' ], 10, 2 );
		add_filter( 'pre_update_option_stonewright_disabled_abilities', [ LeashGuard::class, 'guard_disabled_abilities_write' ], 10, 2 );
		$GLOBALS['stonewright_test_serving_rest'] = true;
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['stonewright_test_serving_rest'], $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'] );
		$GLOBALS['stonewright_test_filters'] = [];
		parent::tearDown();
	}

	public function test_a_refused_mode_change_is_recorded_with_the_two_modes_only(): void {
		update_option( 'stonewright_mode', 'production-safe' );

		update_option( 'stonewright_mode', 'development' );

		self::assertSame( 'production-safe', get_option( 'stonewright_mode' ) );
		$row = $this->row_of( 'other' );
		self::assertSame( [ 'admin_setting', 'stonewright_mode' ], [ $row['resource_type'], $row['resource_id'] ] );
		self::assertStringStartsWith( 'Refused:', $row['summary'] );
		self::assertStringContainsString( 'production-safe to development', $row['summary'] );
	}

	public function test_a_refused_ability_switch_on_is_recorded_with_the_names(): void {
		update_option( 'stonewright_disabled_abilities', [ 'stonewright/a', 'stonewright/b' ] );

		update_option( 'stonewright_disabled_abilities', [ 'stonewright/b' ] );

		self::assertSame( [ 'stonewright/a', 'stonewright/b' ], get_option( 'stonewright_disabled_abilities' ) );
		$row = $this->row_of( 'other' );
		self::assertSame( 'stonewright_disabled_abilities', $row['resource_id'] );
		self::assertStringStartsWith( 'Refused:', $row['summary'] );
		self::assertStringContainsString( 'stonewright/a', $row['summary'] );
	}

	public function test_nothing_is_recorded_when_nothing_is_refused(): void {
		update_option( 'stonewright_mode', 'development' );
		update_option( 'stonewright_mode', 'staging' );
		update_option( 'stonewright_disabled_abilities', [ 'stonewright/a' ] );

		self::assertSame( [], $this->ledger_rows() );
	}
}
