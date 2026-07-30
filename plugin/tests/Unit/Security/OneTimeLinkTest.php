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
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
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
		$url = OneTimeLink::create( 7, ttl_seconds: 300 );
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
		$token = $params['stonewright_otl'] ?? '';

		// Manually expire the transient by overwriting with past timestamp.
		$key = 'stonewright_otl_' . $token;
		$GLOBALS['stonewright_test_transients'][ $key ] = [
			'user_id' => 7,
			'expires' => time() - 1,
		];

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
	}
}
