<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Core\RescueInstaller;
use Stonewright\WpMcp\Security\RescueKeys;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * The plugin side of the rescue keys: the one link the Rescue page and the recovery email
 * hand out, and what it leaves behind.
 *
 * @covers \Stonewright\WpMcp\Security\RescueKeys
 */
final class RescueKeysTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin();
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		MuRuntime::admin( 7 );
		MuRuntime::boot();
		RescueInstaller::install();
	}

	protected function tearDown(): void {
		RescueInstaller::remove();
		MuRuntime::end();
	}

	/** @return array<string, string> */
	private static function query_of( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		return array_map( 'strval', $query );
	}

	/** @return list<array<string, mixed>> */
	private function audit_rows( string $ability ): array {
		return array_values( array_filter( $GLOBALS['stonewright_test_wpdb_inserts'], static fn ( array $row ): bool => $ability === ( $row['data']['ability_name'] ?? '' ) ) );
	}

	public function test_the_link_is_the_login_url_with_a_one_time_key_and_the_rescue_page_as_the_target(): void {
		$url = RescueKeys::safe_boot_url( 7 );

		self::assertNotNull( $url );
		self::assertStringStartsWith( 'https://example.test/wp-login.php?', $url );
		$query = self::query_of( $url );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{16}\.[a-f0-9]{32}$/D', $query['stonewright_rescue'] );
		self::assertSame( 'https://example.test/wp-admin/admin.php?page=stonewright-rescue', $query['redirect_to'] );
	}

	public function test_the_link_opens_safe_mode_once(): void {
		$url   = (string) RescueKeys::safe_boot_url( 7 );
		$query = self::query_of( $url );

		MuRuntime::request( 'wp-login.php', $query );
		self::assertTrue( MuRuntime::start(), 'the first request is redirected with a session' );
		self::assertCount( 1, MuRuntime::$capture->cookies );

		MuRuntime::$capture->cookies = [];
		MuRuntime::request( 'wp-login.php', $query );
		self::assertFalse( MuRuntime::start() );
		self::assertSame( [], MuRuntime::$capture->cookies, 'the second request gets nothing' );
	}

	public function test_the_key_is_bound_to_the_user_it_was_issued_for_and_lives_fifteen_minutes(): void {
		$query = self::query_of( (string) RescueKeys::safe_boot_url( 7 ) );
		$id    = explode( '.', $query['stonewright_rescue'] )[0];

		$stored = json_decode( (string) MuRuntime::option( 'stonewright_rescue_key_' . $id ), true );
		self::assertSame( 7, $stored['u'] );
		self::assertSame( 900, $stored['e'] - $stored['c'] );
	}

	/** @return array<string, array{0: int}> */
	public static function users_without_a_link(): array {
		return [
			'a subscriber'  => [ 8 ],
			'user id zero'  => [ 0 ],
			'a negative id' => [ -1 ],
			'an unknown id' => [ 99 ],
		];
	}

	/** @dataProvider users_without_a_link */
	public function test_only_an_administrator_gets_a_link( int $user_id ): void {
		MuRuntime::subscriber( 8 );
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 99 ];

		self::assertNull( RescueKeys::safe_boot_url( $user_id ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
		self::assertSame( [], $this->audit_rows( 'stonewright/rescue-safe-boot-link' ) );
	}

	public function test_there_is_no_link_when_the_helper_is_not_installed_or_was_changed(): void {
		unlink( RescueInstaller::target_path() );
		self::assertNull( RescueKeys::safe_boot_url( 7 ), 'not installed' );

		RescueInstaller::install();
		file_put_contents( RescueInstaller::target_path(), "<?php\n// changed\n" );
		self::assertNull( RescueKeys::safe_boot_url( 7 ), 'modified' );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ), 'no key was stored for a link that could not work' );
	}

	public function test_issuing_a_link_is_audited_and_the_audit_row_holds_no_key(): void {
		$url    = (string) RescueKeys::safe_boot_url( 7 );
		$secret = explode( '.', self::query_of( $url )['stonewright_rescue'] )[1];

		$rows = $this->audit_rows( 'stonewright/rescue-safe-boot-link' );
		self::assertCount( 1, $rows );
		$encoded = json_encode( $rows ) ?: '';
		self::assertStringContainsString( '"7"', $encoded, 'the administrator it was issued for' );
		self::assertStringNotContainsString( $secret, $encoded );
		self::assertStringNotContainsString( 'stonewright_rescue=', $encoded );
	}

	public function test_revoke_all_removes_waiting_keys_and_open_sessions(): void {
		RescueKeys::safe_boot_url( 7 );
		RescueKeys::safe_boot_url( 7 );
		MuRuntime::open_session( 7 );

		self::assertGreaterThanOrEqual( 3, RescueKeys::revoke_all() );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
	}

	public function test_deactivation_revokes_keys_and_sessions_but_keeps_the_helper_file(): void {
		RescueKeys::safe_boot_url( 7 );
		MuRuntime::open_session( 7 );

		RescueInstaller::on_deactivate();

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
		self::assertFileExists( RescueInstaller::target_path() );
	}

	public function test_the_rescue_page_slug_matches_the_page(): void {
		self::assertSame( 'stonewright-rescue', RescueKeys::PAGE );
	}
}
