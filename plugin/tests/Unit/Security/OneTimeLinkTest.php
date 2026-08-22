<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\OneTimeLink;

/**
 * @covers \Stonewright\WpMcp\Security\OneTimeLink
 */
final class OneTimeLinkTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		unset( $GLOBALS['stonewright_test_auth_cookie'] );
		unset( $GLOBALS['stonewright_test_set_current_user'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_wpdb_inserts'] = [];
		unset( $GLOBALS['stonewright_test_auth_cookie'] );
		unset( $GLOBALS['stonewright_test_set_current_user'] );
		unset( $GLOBALS['stonewright_test_filters']['stonewright_otl_now'] );
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_create_returns_url_with_token(): void {
		$url = OneTimeLink::create( 1 );
		$this->assertStringContainsString( 'stonewright_otl=', $url );
		$this->assertStringContainsString( 'wp-admin', $url );
	}

	public function test_consume_returns_user_id_for_valid_token(): void {
		$url   = OneTimeLink::create( 42 );
		// Extract token from URL.
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = $params['stonewright_otl'] ?? '';
		$this->assertNotEmpty( $token );

		$user_id = OneTimeLink::consume( $token );
		$this->assertSame( 42, $user_id );
	}

	public function test_consume_returns_false_after_first_use(): void {
		$url = OneTimeLink::create( 5 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = $params['stonewright_otl'] ?? '';

		// First consume: success.
		$this->assertSame( 5, OneTimeLink::consume( $token ) );

		// Second consume: already deleted — must return false.
		$this->assertFalse( OneTimeLink::consume( $token ) );
	}

	public function test_consume_returns_false_for_unknown_token(): void {
		$this->assertFalse( OneTimeLink::consume( 'nonexistent-token' ) );
	}

	public function test_consume_returns_false_for_expired_token(): void {
		$url = OneTimeLink::create( 7, ttl_seconds: 30 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = $params['stonewright_otl'] ?? '';
		$this->assertNotEmpty( $token );

		$GLOBALS['stonewright_test_filters']['stonewright_otl_now'] = static fn() => time() + 120;

		$this->assertFalse( OneTimeLink::consume( $token ) );
	}

	public function test_authenticate_sets_current_user_and_auth_cookie(): void {
		$url = OneTimeLink::create( 11 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$user_id = OneTimeLink::authenticate( $token );

		$this->assertSame( 11, $user_id );
		$this->assertSame( 11, $GLOBALS['stonewright_test_set_current_user'] );
		$this->assertSame( 11, $GLOBALS['stonewright_test_auth_cookie']['user_id'] );
		$this->assertFalse( $GLOBALS['stonewright_test_auth_cookie']['remember'] );
	}

	public function test_token_is_hmac_signed_not_a_raw_transient_secret(): void {
		$url = OneTimeLink::create( 3 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$this->assertNotSame( '', $token );
		$this->assertMatchesRegularExpression( '/^swotl_[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token );
		$this->assertFalse( OneTimeLink::consume( $token . 'tampered' ) );
	}

	public function test_consume_accepts_loopback_ipv4_ipv6_swap(): void {
		$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
		$_SERVER['HTTP_USER_AGENT'] = 'StonewrightTest/1.0';

		$url = OneTimeLink::create( 15, 300 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$_SERVER['REMOTE_ADDR'] = '::1';
		$this->assertSame( 15, OneTimeLink::consume( $token ) );
	}

	public function test_consume_rejects_ip_mismatch_without_burning_token(): void {
		$_SERVER['REMOTE_ADDR']     = '192.0.2.10';
		$_SERVER['HTTP_USER_AGENT'] = 'StonewrightTest/1.0';

		$url = OneTimeLink::create( 9, 300 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.99';
		$this->assertFalse( OneTimeLink::consume( $token ) );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$this->assertSame( 9, OneTimeLink::consume( $token ) );
	}

	public function test_consume_rejects_user_agent_mismatch(): void {
		$_SERVER['REMOTE_ADDR']     = '192.0.2.10';
		$_SERVER['HTTP_USER_AGENT'] = 'StonewrightTest/1.0';

		$url = OneTimeLink::create( 8, 300 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$_SERVER['HTTP_USER_AGENT'] = 'OtherAgent/9';
		$this->assertFalse( OneTimeLink::consume( $token ) );
	}

	public function test_issue_is_rate_limited_per_user(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$url = OneTimeLink::create( 4 );
			$this->assertIsString( $url );
		}

		$blocked = OneTimeLink::create( 4 );
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertSame( 'stonewright_otl_rate_limited', $blocked->get_error_code() );
	}

	public function test_authenticate_audits_success_and_failure(): void {
		$_SERVER['REMOTE_ADDR']     = '192.0.2.12';
		$_SERVER['HTTP_USER_AGENT'] = 'StonewrightTest/1.0';

		$url = OneTimeLink::create( 12, 300 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = (string) ( $params['stonewright_otl'] ?? '' );

		$this->assertFalse( OneTimeLink::authenticate( 'not-a-token' ) );
		$this->assertSame( 12, OneTimeLink::authenticate( $token ) );

		$statuses = array_values(
			array_filter(
				$GLOBALS['stonewright_test_wpdb_inserts'],
				static fn( array $insert ): bool => 'stonewright/security-one-time-link' === ( $insert['data']['ability_name'] ?? null )
			)
		);
		$this->assertNotEmpty( $statuses );
		$result_statuses = array_column( array_column( $statuses, 'data' ), 'result_status' );
		$this->assertContains( 'blocked', $result_statuses );
		$this->assertContains( 'ok', $result_statuses );
	}
}
