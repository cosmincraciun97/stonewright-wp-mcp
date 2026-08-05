<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\OAuth;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\OAuth\OAuthRateLimiter;

/**
 * @covers \Stonewright\WpMcp\OAuth\OAuthRateLimiter
 */
final class OAuthRateLimiterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		unset( $GLOBALS['stonewright_test_options']['stonewright_oauth_rate_limiter_enabled'] );
		OAuthRateLimiter::reset_metrics_for_tests();
		$_SERVER['REMOTE_ADDR'] = '';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_transients'] = [];
		$GLOBALS['stonewright_test_filters'] = [];
		unset( $GLOBALS['stonewright_test_options']['stonewright_oauth_rate_limiter_enabled'] );
		OAuthRateLimiter::reset_metrics_for_tests();
		$_SERVER['REMOTE_ADDR'] = '';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_ipv4_and_ipv6_network_buckets_are_stable(): void {
		self::assertSame( '203.0.113.0/24', OAuthRateLimiter::network_bucket( '203.0.113.12' ) );
		self::assertSame( '203.0.113.0/24', OAuthRateLimiter::network_bucket( '203.0.113.240' ) );
		self::assertSame( '203.0.114.0/24', OAuthRateLimiter::network_bucket( '203.0.114.1' ) );
		self::assertSame( '2001:db8::/64', OAuthRateLimiter::network_bucket( '2001:db8::1234' ) );
		self::assertSame( '2001:db8::/64', OAuthRateLimiter::network_bucket( '2001:db8::abcd' ) );
		self::assertSame( '', OAuthRateLimiter::network_bucket( 'not-an-ip' ) );
	}

	public function test_untrusted_forwarded_headers_cannot_spoof_the_client_identity(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.66, 203.0.113.10, 198.51.100.20';
		self::assertSame( '198.51.100.10', OAuthRateLimiter::client_ip() );

		$GLOBALS['stonewright_test_filters']['stonewright_oauth_trusted_proxy_ips'] = static fn( mixed $value ): array => [ '198.51.100.10', '198.51.100.20' ];
		self::assertSame( '203.0.113.10', OAuthRateLimiter::client_ip() );
	}

	public function test_atomic_upsert_resets_hits_before_advancing_the_window(): void {
		$method = new \ReflectionMethod( OAuthRateLimiter::class, 'atomic_upsert_sql' );
		$sql = (string) $method->invoke( null, 'wp_stonewright_oauth_rate_limits' );

		self::assertLessThan(
			strpos( $sql, 'window_started = IF' ),
			strpos( $sql, 'hits = IF' ),
			'Hits must test the old window before window_started is assigned in MySQL left-to-right evaluation.'
		);
	}

	public function test_client_and_network_keys_share_the_expected_throttle_scope(): void {
		$first  = OAuthRateLimiter::endpoint( 'token', '203.0.113.12', 'client-a' );
		$second = OAuthRateLimiter::endpoint( 'token', '203.0.113.240', 'client-a' );
		$other  = OAuthRateLimiter::endpoint( 'token', '203.0.114.12', 'client-a' );
		$client = OAuthRateLimiter::endpoint( 'token', '203.0.113.12', 'client-b' );

		self::assertTrue( $first['allowed'] );
		self::assertSame( $first['remaining'] - 1, $second['remaining'] );
		self::assertNotSame( $second['remaining'], $other['remaining'] );
		self::assertNotSame( $second['remaining'], $client['remaining'] );
	}

	public function test_successful_refresh_reservation_can_be_released_without_forgiving_failures(): void {
		$key = OAuthRateLimiter::identity_key( '203.0.113.12', 'client-a' );
		self::assertTrue( OAuthRateLimiter::hit( 'refresh', $key, 1, MINUTE_IN_SECONDS )['allowed'] );
		self::assertFalse( OAuthRateLimiter::hit( 'refresh', $key, 1, MINUTE_IN_SECONDS )['allowed'] );

		OAuthRateLimiter::release( 'refresh', $key, MINUTE_IN_SECONDS );

		self::assertTrue( OAuthRateLimiter::hit( 'refresh', $key, 1, MINUTE_IN_SECONDS )['allowed'] );
	}

	public function test_feature_flag_disables_new_limiter_without_persisting_counters(): void {
		$GLOBALS['stonewright_test_filters']['stonewright_oauth_rate_limiter_enabled'] = static fn( mixed $value ): bool => false;
		for ( $i = 0; $i < 4; ++$i ) {
			self::assertTrue( OAuthRateLimiter::hit( 'refresh', 'client-a|203.0.113.0/24', 1, MINUTE_IN_SECONDS )['allowed'] );
		}
		self::assertSame( [], $GLOBALS['stonewright_test_transients'] );
	}

	public function test_limiter_exposes_count_only_storm_metrics(): void {
		for ( $i = 0; $i < OAuthRateLimiter::ENDPOINT_LIMIT + 2; ++$i ) {
			OAuthRateLimiter::hit( 'token', 'client-a|203.0.113.0/24', OAuthRateLimiter::ENDPOINT_LIMIT, MINUTE_IN_SECONDS );
		}
		for ( $i = 0; $i < 2; ++$i ) {
			OAuthRateLimiter::hit( 'token', 'client-b|203.0.114.0/24', OAuthRateLimiter::ENDPOINT_LIMIT, MINUTE_IN_SECONDS );
		}

		$metrics = OAuthRateLimiter::metrics_snapshot( 'token' );
		self::assertSame( 2, $metrics['limited_requests'] );
		self::assertSame( 1, $metrics['distinct_client_fingerprints'] );
		self::assertTrue( $metrics['cooldown_active'] );
		self::assertArrayNotHasKey( 'client_id', $metrics );
		self::assertArrayNotHasKey( 'ip', $metrics );
	}
}
