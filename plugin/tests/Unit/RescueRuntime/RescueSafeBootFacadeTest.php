<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\RescueSafeBoot;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * What the rest of the plugin sees of safe boot: whether the request is in it, who it is for,
 * the way out, the wrapper a rollback uses to change the plugin and theme selection, and the
 * audit row of entering it.
 *
 * @covers \Stonewright\WpMcp\Security\RescueSafeBoot
 */
final class RescueSafeBootFacadeTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin();
		$GLOBALS['stonewright_test_wpdb_inserts']  = [];
		$GLOBALS['stonewright_test_transients']    = [];
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/** @return list<array<string, mixed>> */
	private function audit_rows(): array {
		return array_values( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $row ): bool => 'stonewright/rescue-safe-boot' === ( $row['data']['ability_name'] ?? '' ) ) );
	}

	private function enter_safe_boot( int $user_id = 7 ): void {
		$token = MuRuntime::open_session( $user_id );
		MuRuntime::request( 'wp-admin/index.php', [], [ 'stonewright_rescue_session' => $token ] );
		MuRuntime::start();
	}

	public function test_a_normal_request_is_not_in_safe_boot(): void {
		MuRuntime::boot();

		self::assertFalse( RescueSafeBoot::active() );
		self::assertSame( 0, RescueSafeBoot::user_id() );
		self::assertSame( '', RescueSafeBoot::exit_url() );
	}

	public function test_a_safe_boot_request_knows_its_administrator_and_the_way_out(): void {
		$this->enter_safe_boot( 7 );

		self::assertTrue( RescueSafeBoot::active() );
		self::assertSame( 7, RescueSafeBoot::user_id() );
		self::assertStringContainsString( 'stonewright_rescue_exit=1', RescueSafeBoot::exit_url() );
	}

	public function test_the_wrapper_runs_the_callback_with_the_stored_selection_and_returns_its_result(): void {
		$this->enter_safe_boot( 7 );
		$active = [ 'akismet/akismet.php', MuRuntime::PLUGIN ];

		$result = RescueSafeBoot::with_stored_selection(
			static fn (): array => MuRuntime::filter( 'option_active_plugins', $active )
		);

		self::assertSame( $active, $result );
		self::assertSame( [ MuRuntime::PLUGIN ], MuRuntime::filter( 'option_active_plugins', $active ) );
	}

	public function test_the_wrapper_just_runs_the_callback_outside_safe_boot(): void {
		MuRuntime::boot();

		self::assertSame( 'done', RescueSafeBoot::with_stored_selection( static fn (): string => 'done' ) );
	}

	public function test_entering_safe_boot_is_audited_once_per_session_without_the_session(): void {
		$token = MuRuntime::open_session( 7 );
		MuRuntime::request( 'wp-admin/index.php', [], [ 'stonewright_rescue_session' => $token ] );
		MuRuntime::start();

		RescueSafeBoot::audit_entry();
		RescueSafeBoot::audit_entry();

		$rows = $this->audit_rows();
		self::assertCount( 1, $rows );
		$encoded = json_encode( $rows ) ?: '';
		self::assertStringContainsString( '"7"', $encoded );
		self::assertStringNotContainsString( $token, $encoded );
		self::assertStringNotContainsString( hash( 'sha256', $token ), $encoded );
	}

	public function test_a_new_session_is_audited_again(): void {
		$this->enter_safe_boot( 7 );
		RescueSafeBoot::audit_entry();
		$this->enter_safe_boot( 7 );
		RescueSafeBoot::audit_entry();

		self::assertCount( 2, $this->audit_rows() );
	}

	public function test_nothing_is_audited_outside_safe_boot(): void {
		MuRuntime::boot();

		RescueSafeBoot::audit_entry();

		self::assertSame( [], $this->audit_rows() );
	}

	public function test_nothing_is_audited_for_the_mcp_route_mode_that_has_no_session(): void {
		MuRuntime::write_journal( [ MuRuntime::entry( 'cs-1', [ 'state' => 'incident' ] ) ] );
		MuRuntime::set_option( 'stonewright_rescue_mcp_safe_boot', '1' );
		MuRuntime::request( 'index.php' );
		$_SERVER['REQUEST_URI']        = '/wp-json/mcp/stonewright';
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic dGVzdDp0ZXN0';
		MuRuntime::start();

		RescueSafeBoot::audit_entry();

		self::assertTrue( RescueSafeBoot::active() );
		self::assertSame( [], $this->audit_rows() );
	}

	public function test_registering_hooks_the_audit_to_init(): void {
		RescueSafeBoot::register();

		self::assertTrue( MuRuntime::has_action( 'init' ) );
	}
}
