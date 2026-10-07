<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\RescueRuntime;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Tests\Unit\RescueRuntime\Support\MuRuntime;

/**
 * Rescue keys: issue, single use, expiry, the user they are bound to, what is stored and what
 * a redemption sends back.
 *
 * @coversNothing The MU-plugin lives outside includes/.
 */
final class RescueKeyLifecycleTest extends TestCase {

	protected function setUp(): void {
		MuRuntime::begin();
		MuRuntime::admin( 7 );
		MuRuntime::boot();
	}

	protected function tearDown(): void {
		MuRuntime::end();
	}

	/** @return array{key:string,expires_at:int} */
	private function issue( int $user_id = 7 ): array {
		$issued = \Stonewright_Rescue::issue_key( $user_id );
		self::assertIsArray( $issued );
		return $issued;
	}

	/** Sends a key to wp-login.php; returns whether the runtime ended the request. */
	private function redeem( string $key, string $script = 'wp-login.php', string $method = 'GET' ): bool {
		MuRuntime::request( $script, [ 'stonewright_rescue' => $key, 'redirect_to' => 'https://example.test/wp-admin/admin.php?page=stonewright-rescue' ], [], $method );
		return MuRuntime::start();
	}

	private static function key_option( string $key ): string {
		return 'stonewright_rescue_key_' . explode( '.', $key )[0];
	}

	public function test_an_administrator_gets_a_key_made_of_an_id_and_a_secret(): void {
		$issued = $this->issue();

		self::assertMatchesRegularExpression( '/^[a-f0-9]{16}\.[a-f0-9]{32}$/', $issued['key'] );
		self::assertEqualsWithDelta( time() + 900, $issued['expires_at'], 2 );
	}

	public function test_only_the_hash_of_the_secret_is_stored_with_the_user_and_the_expiry(): void {
		$issued = $this->issue();
		[ $id, $secret ] = explode( '.', $issued['key'] );

		$stored = json_decode( (string) MuRuntime::option( 'stonewright_rescue_key_' . $id ), true );
		self::assertSame( hash( 'sha256', $secret ), $stored['h'] );
		self::assertSame( 7, $stored['u'] );
		self::assertSame( 900, $stored['e'] - $stored['c'] );
		self::assertSame( [ 'h', 'u', 'c', 'e' ], array_keys( $stored ) );
		self::assertStringNotContainsString( $secret, json_encode( $GLOBALS['stonewright_test_options'] ) ?: '', 'the secret is stored nowhere' );
	}

	/** @return array<string, array{0: int}> */
	public static function users_without_a_key(): array {
		return [
			'a subscriber'      => [ 8 ],
			'no such user'      => [ 9 ],
			'user id zero'      => [ 0 ],
			'a negative number' => [ -3 ],
		];
	}

	/** @dataProvider users_without_a_key */
	public function test_only_an_administrator_gets_a_key( int $user_id ): void {
		MuRuntime::subscriber( 8 );
		$GLOBALS['stonewright_test_missing_user_ids'] = [ 9 ];
		MuRuntime::admin( 9 );

		self::assertNull( \Stonewright_Rescue::issue_key( $user_id ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
	}

	public function test_a_key_can_be_redeemed_once(): void {
		$issued = $this->issue();

		self::assertTrue( $this->redeem( $issued['key'] ), 'the first request is sent on to the login page' );
		self::assertSame( 1, MuRuntime::$capture->exits );
		self::assertCount( 1, MuRuntime::$capture->cookies );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ), 'the record is gone' );

		MuRuntime::$capture->cookies = [];
		MuRuntime::$capture->headers = [];
		self::assertFalse( $this->redeem( $issued['key'] ), 'the second request is not redirected' );
		self::assertSame( [], MuRuntime::$capture->cookies, 'and gets no session' );
		self::assertTrue( MuRuntime::has_filter( 'login_message' ), 'the login page tells the person the link is not valid' );
		self::assertStringContainsString( 'not valid', (string) MuRuntime::filter( 'login_message', '' ) );
	}

	public function test_redemption_sends_a_session_cookie_that_is_http_only_secure_and_same_site_lax(): void {
		$issued = $this->issue();
		$this->redeem( $issued['key'] );

		$cookie = MuRuntime::$capture->cookies[0];
		self::assertSame( 'stonewright_rescue_session', $cookie['name'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $cookie['value'] );
		self::assertNotSame( explode( '.', $issued['key'] )[1], $cookie['value'], 'the cookie is not the key' );
		self::assertTrue( $cookie['options']['httponly'] );
		self::assertTrue( $cookie['options']['secure'], 'the site uses HTTPS' );
		self::assertSame( 'Lax', $cookie['options']['samesite'] );
		self::assertSame( '/', $cookie['options']['path'] );
		self::assertSame( '', $cookie['options']['domain'] );
		self::assertEqualsWithDelta( time() + 1800, $cookie['options']['expires'], 3 );
	}

	/** @return array<string, array{0: bool, 1: string, 2: bool}> Whether the request is HTTPS, the home URL, whether the cookie is Secure. */
	public static function transports(): array {
		return [
			'an HTTPS request'                                 => [ true, 'https://example.test', true ],
			'a plain request to a site whose URL is HTTPS'      => [ false, 'https://example.test', true ],
			'a plain request to a site whose URL is plain HTTP' => [ false, 'http://example.test', false ],
		];
	}

	/** @dataProvider transports */
	public function test_the_cookie_is_secure_when_the_site_uses_https( bool $request_is_ssl, string $home, bool $secure ): void {
		MuRuntime::$capture->ssl = $request_is_ssl;
		MuRuntime::set_option( 'home', $home );
		$issued = $this->issue();
		$this->redeem( $issued['key'] );

		self::assertSame( $secure, MuRuntime::$capture->cookies[0]['options']['secure'] );
	}

	public function test_the_cookie_is_scoped_to_the_path_of_the_site(): void {
		MuRuntime::set_option( 'home', 'https://example.test/blog' );
		$issued = $this->issue();
		$this->redeem( $issued['key'] );

		self::assertSame( '/blog/', MuRuntime::$capture->cookies[0]['options']['path'] );
	}

	public function test_the_session_is_stored_as_a_hash_with_the_user(): void {
		$issued = $this->issue();
		$this->redeem( $issued['key'] );
		$token = MuRuntime::$capture->cookies[0]['value'];

		$names = MuRuntime::option_names( 'stonewright_rescue_sess_' );
		self::assertCount( 1, $names );
		self::assertSame( 'stonewright_rescue_sess_' . substr( hash( 'sha256', $token ), 0, 40 ), $names[0] );
		$record = json_decode( (string) MuRuntime::option( $names[0] ), true );
		self::assertSame( hash( 'sha256', $token ), $record['h'] );
		self::assertSame( 7, $record['u'] );
		self::assertSame( 1800, $record['e'] - $record['c'] );
		self::assertStringNotContainsString( $token, json_encode( $GLOBALS['stonewright_test_options'] ) ?: '' );
	}

	public function test_the_redirect_goes_to_the_login_url_without_the_key(): void {
		$issued = $this->issue();
		$secret = explode( '.', $issued['key'] )[1];
		$this->redeem( $issued['key'] );

		$location = '';
		foreach ( MuRuntime::$capture->headers as [ $line, $status ] ) {
			if ( str_starts_with( $line, 'Location: ' ) ) {
				$location = substr( $line, 10 );
				self::assertSame( 302, $status );
			}
		}
		self::assertSame( 'https://example.test/wp-login.php?redirect_to=https%3A%2F%2Fexample.test%2Fwp-admin%2Fadmin.php%3Fpage%3Dstonewright-rescue', $location );
		self::assertStringNotContainsString( $secret, $location );
		self::assertStringNotContainsString( 'stonewright_rescue=', $location );
		$lines = array_column( MuRuntime::$capture->headers, 0 );
		self::assertContains( 'Cache-Control: no-store, max-age=0', $lines );
		self::assertContains( 'Referrer-Policy: no-referrer', $lines );
	}

	public function test_an_expired_key_is_refused_and_removed(): void {
		$issued = $this->issue();
		$name   = self::key_option( $issued['key'] );
		$record = json_decode( (string) MuRuntime::option( $name ), true );
		$record['e'] = time() - 1;
		MuRuntime::set_option( $name, json_encode( $record ) );

		self::assertFalse( $this->redeem( $issued['key'] ) );
		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertNull( MuRuntime::option( $name ) );
	}

	public function test_a_wrong_secret_is_refused_and_burns_the_key(): void {
		$issued = $this->issue();
		$wrong  = explode( '.', $issued['key'] )[0] . '.' . str_repeat( '0', 32 );

		self::assertFalse( $this->redeem( $wrong ) );
		self::assertNull( MuRuntime::option( self::key_option( $issued['key'] ) ), 'one wrong try uses the key up' );
		self::assertFalse( $this->redeem( $issued['key'] ), 'so the right key no longer works either' );
		self::assertSame( [], MuRuntime::$capture->cookies );
	}

	public function test_a_key_of_a_user_who_is_no_longer_an_administrator_is_refused(): void {
		$issued = $this->issue();
		MuRuntime::subscriber( 7 );

		self::assertFalse( $this->redeem( $issued['key'] ) );
		self::assertSame( [], MuRuntime::$capture->cookies );
	}

	/** @return array<string, array{0: mixed}> */
	public static function malformed_keys(): array {
		return [
			'upper case'         => [ strtoupper( str_repeat( 'a', 16 ) ) . '.' . str_repeat( 'B', 32 ) ],
			'short secret'       => [ str_repeat( 'a', 16 ) . '.' . str_repeat( 'b', 31 ) ],
			'long id'            => [ str_repeat( 'a', 17 ) . '.' . str_repeat( 'b', 32 ) ],
			'no dot'             => [ str_repeat( 'a', 48 ) ],
			'trailing space'     => [ str_repeat( 'a', 16 ) . '.' . str_repeat( 'b', 32 ) . ' ' ],
			'trailing newline'   => [ str_repeat( 'a', 16 ) . '.' . str_repeat( 'b', 32 ) . "\n" ],
			'sql'                => [ "x' OR '1'='1" ],
			'an array'           => [ [ str_repeat( 'a', 16 ) . '.' . str_repeat( 'b', 32 ) ] ],
			'empty'              => [ '' ],
		];
	}

	/** @dataProvider malformed_keys */
	public function test_a_malformed_key_is_ignored_without_touching_the_database( mixed $key ): void {
		MuRuntime::request( 'wp-login.php', [ 'stonewright_rescue' => $key ] );

		self::assertFalse( MuRuntime::start() );
		self::assertSame( [], $GLOBALS['wpdb']->statements );
		self::assertSame( [], MuRuntime::$capture->cookies );
	}

	public function test_an_unknown_key_looks_up_one_row_and_changes_nothing(): void {
		$issued = $this->issue();
		$before = $GLOBALS['stonewright_test_options'];
		$GLOBALS['wpdb']->statements = [];
		MuRuntime::request( 'wp-login.php', [ 'stonewright_rescue' => str_repeat( 'a', 16 ) . '.' . str_repeat( 'b', 32 ) ] );

		self::assertFalse( MuRuntime::start() );
		self::assertCount( 1, $GLOBALS['wpdb']->statements );
		self::assertSame( $before, $GLOBALS['stonewright_test_options'] );
		self::assertNotNull( MuRuntime::option( self::key_option( $issued['key'] ) ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function requests_that_do_not_redeem(): array {
		return [
			'a POST to wp-login.php'      => [ 'wp-login.php', 'POST' ],
			'a GET to the front end'      => [ 'index.php', 'GET' ],
			'a GET to the dashboard'      => [ 'wp-admin/index.php', 'GET' ],
			'a GET to admin-ajax'         => [ 'wp-admin/admin-ajax.php', 'GET' ],
			'a GET to xmlrpc'             => [ 'xmlrpc.php', 'GET' ],
		];
	}

	/** @dataProvider requests_that_do_not_redeem */
	public function test_a_key_is_redeemed_only_by_a_get_request_to_the_login_page( string $script, string $method ): void {
		$issued = $this->issue();

		self::assertFalse( $this->redeem( $issued['key'], $script, $method ) );
		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertNotNull( MuRuntime::option( self::key_option( $issued['key'] ) ), 'the key is still waiting' );
	}

	public function test_a_request_that_loses_the_race_for_a_key_does_not_get_a_session(): void {
		$issued = $this->issue();
		$name   = self::key_option( $issued['key'] );
		// Another request deletes the record between the lookup and the delete.
		$GLOBALS['wpdb']->after_select = static function ( string $looked_up ) use ( $name ): void {
			if ( $looked_up === $name ) {
				unset( $GLOBALS['stonewright_test_options'][ $name ] );
			}
		};

		self::assertFalse( $this->redeem( $issued['key'] ) );
		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
	}

	public function test_at_most_five_keys_wait_at_once_and_the_oldest_go_first(): void {
		$keys = [];
		for ( $i = 0; $i < 7; $i++ ) {
			$keys[] = $this->issue()['key'];
			// Stagger the expiry so the order is known.
			$name        = self::key_option( $keys[ $i ] );
			$record      = json_decode( (string) MuRuntime::option( $name ), true );
			$record['e'] = time() + 100 + $i;
			MuRuntime::set_option( $name, json_encode( $record ) );
		}

		self::assertCount( 5, MuRuntime::option_names( 'stonewright_rescue_key_' ) );
		self::assertNull( MuRuntime::option( self::key_option( $keys[0] ) ) );
		self::assertNull( MuRuntime::option( self::key_option( $keys[1] ) ) );
		self::assertNotNull( MuRuntime::option( self::key_option( $keys[6] ) ) );
	}

	public function test_expired_keys_and_sessions_are_purged_when_a_key_is_issued(): void {
		MuRuntime::set_option( 'stonewright_rescue_key_' . str_repeat( 'a', 16 ), json_encode( [ 'h' => 'x', 'u' => 7, 'c' => 1, 'e' => time() - 5 ] ) );
		MuRuntime::set_option( 'stonewright_rescue_sess_' . str_repeat( 'b', 40 ), json_encode( [ 'h' => 'x', 'u' => 7, 'c' => 1, 'e' => time() - 5 ] ) );
		MuRuntime::set_option( 'stonewright_rescue_sess_' . str_repeat( 'c', 40 ), json_encode( [ 'h' => 'x', 'u' => 7, 'c' => 1, 'e' => time() + 500 ] ) );

		$this->issue();

		self::assertNull( MuRuntime::option( 'stonewright_rescue_key_' . str_repeat( 'a', 16 ) ) );
		self::assertNull( MuRuntime::option( 'stonewright_rescue_sess_' . str_repeat( 'b', 40 ) ) );
		self::assertNotNull( MuRuntime::option( 'stonewright_rescue_sess_' . str_repeat( 'c', 40 ) ), 'a live session stays' );
	}

	public function test_purge_all_removes_every_key_and_session(): void {
		$this->issue();
		$this->issue();
		MuRuntime::open_session( 7 );
		MuRuntime::set_option( 'stonewright_other_option', 'kept' );

		self::assertGreaterThanOrEqual( 3, \Stonewright_Rescue::purge_all() );

		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_key_' ) );
		self::assertSame( [], MuRuntime::option_names( 'stonewright_rescue_sess_' ) );
		self::assertSame( 'kept', MuRuntime::option( 'stonewright_other_option' ) );
	}

	public function test_no_key_or_session_token_is_logged_or_stored_in_the_clear(): void {
		$log = tempnam( sys_get_temp_dir(), 'sw-log' );
		self::assertIsString( $log );
		$previous = ini_set( 'error_log', $log );
		try {
			$issued = $this->issue();
			$secret = explode( '.', $issued['key'] )[1];
			$this->redeem( $issued['key'] );
			$token = MuRuntime::$capture->cookies[0]['value'];
			$this->redeem( $issued['key'] );
		} finally {
			ini_set( 'error_log', (string) $previous );
		}

		$contents = (string) file_get_contents( $log );
		unlink( $log );
		self::assertSame( '', $contents, 'the runtime writes nothing to the PHP log' );
		$stored = json_encode( $GLOBALS['stonewright_test_options'] ) ?: '';
		self::assertStringNotContainsString( $secret, $stored );
		self::assertStringNotContainsString( $token, $stored );
	}

	public function test_a_new_session_replaces_the_one_the_browser_already_had_with_a_single_cookie(): void {
		$first = MuRuntime::open_session( 7 );
		$issued = $this->issue();
		MuRuntime::request( 'wp-login.php', [ 'stonewright_rescue' => $issued['key'] ], [ 'stonewright_rescue_session' => $first ] );
		MuRuntime::start();

		self::assertCount( 1, MuRuntime::option_names( 'stonewright_rescue_sess_' ), 'the old session is revoked' );
		self::assertNull( MuRuntime::option( 'stonewright_rescue_sess_' . substr( hash( 'sha256', $first ), 0, 40 ) ) );
		self::assertCount( 1, MuRuntime::$capture->cookies, 'one Set-Cookie header, the new session' );
		self::assertNotSame( '', MuRuntime::$capture->cookies[0]['value'] );
	}

	public function test_a_key_is_not_used_up_when_the_session_cookie_cannot_be_sent(): void {
		$issued = $this->issue();
		MuRuntime::$capture->headers_sent = true;

		self::assertFalse( $this->redeem( $issued['key'] ) );

		self::assertSame( [], MuRuntime::$capture->cookies );
		self::assertNotNull( MuRuntime::option( self::key_option( $issued['key'] ) ), 'the key still works for a request that can send the cookie' );
	}

	public function test_the_runtime_does_not_redeem_while_the_stonewright_plugin_file_is_missing(): void {
		$issued = $this->issue();
		unlink( MuRuntime::plugin_file() );

		self::assertFalse( $this->redeem( $issued['key'] ) );
		self::assertNotNull( MuRuntime::option( self::key_option( $issued['key'] ) ), 'the key is not used up' );
	}
}
