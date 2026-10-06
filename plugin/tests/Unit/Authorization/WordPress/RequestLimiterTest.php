<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\RequestLimiter;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\FakeWpdb;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * @covers \Stonewright\WpMcp\Authorization\WordPress\RequestLimiter
 */
final class RequestLimiterTest extends TestCase {

	private StorageRig $rig;

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
		$this->rig = new StorageRig();
	}

	protected function tearDown(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
	}

	private function limiter( array $limits = [ 'token' => [ 3, 60 ] ] ): RequestLimiter {
		return new RequestLimiter( $this->rig->db, $this->rig->clock, $limits );
	}

	public function test_requests_are_admitted_up_to_the_limit_then_told_when_to_retry(): void {
		$limiter = $this->limiter();
		for ( $request = 0; $request < 3; $request++ ) {
			self::assertSame( [ 'allowed' => true, 'retry_after' => 0 ], $limiter->admit( 'token', '192.0.2.10' ) );
		}

		self::assertSame( [ 'allowed' => false, 'retry_after' => 60 ], $limiter->admit( 'token', '192.0.2.10' ) );
		$this->rig->at( StorageRig::T + 59 );
		self::assertSame( [ 'allowed' => false, 'retry_after' => 1 ], $limiter->admit( 'token', '192.0.2.10' ) );
		$this->rig->at( StorageRig::T + 60 );
		self::assertTrue( $limiter->admit( 'token', '192.0.2.10' )['allowed'] );

		$rows = $this->rig->rows( 'rate_limits' );
		self::assertCount( 1, $rows );
		self::assertSame( RequestLimiter::bucket( 'token', '192.0.2.10' ), $rows[0]['bucket_key'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $rows[0]['bucket_key'] );
		self::assertSame( (string) ( StorageRig::T + 60 ), $rows[0]['window_started'] );
		self::assertSame( '1', $rows[0]['hits'] );
		self::assertSame( gmdate( 'Y-m-d H:i:s', StorageRig::T + 60 ), $rows[0]['updated_at'] );
	}

	public function test_buckets_are_separate_per_endpoint_and_requester(): void {
		$limiter = $this->limiter( [ 'token' => [ 1, 60 ], 'revocation' => [ 1, 60 ] ] );

		self::assertTrue( $limiter->admit( 'token', '192.0.2.10' )['allowed'] );
		self::assertFalse( $limiter->admit( 'token', '192.0.2.10' )['allowed'] );
		self::assertTrue( $limiter->admit( 'token', '192.0.2.11' )['allowed'] );
		self::assertTrue( $limiter->admit( 'revocation', '192.0.2.10' )['allowed'] );
		self::assertNotSame( RequestLimiter::bucket( 'token', '192.0.2.10' ), RequestLimiter::bucket( 'revocation', '192.0.2.10' ) );
	}

	public function test_an_endpoint_without_a_limit_is_not_counted(): void {
		self::assertSame( [ 'allowed' => true, 'retry_after' => 0 ], $this->limiter()->admit( 'discovery', '192.0.2.10' ) );
		self::assertSame( [], $this->rig->rows( 'rate_limits' ) );
	}

	public function test_the_limit_filter_can_tighten_an_endpoint(): void {
		$GLOBALS['stonewright_test_filters'][ RequestLimiter::FILTER ] = static fn ( array $limit, string $endpoint ): array => 'token' === $endpoint ? [ 1, 30 ] : $limit;
		$limiter = $this->limiter();

		self::assertTrue( $limiter->admit( 'token', '192.0.2.10' )['allowed'] );
		self::assertSame( [ 'allowed' => false, 'retry_after' => 30 ], $limiter->admit( 'token', '192.0.2.10' ) );
	}

	public function test_a_lowered_limit_keeps_an_existing_window_valid(): void {
		$this->rig->db->insert( $this->rig->db->table( 'rate_limits' ), [ 'bucket_key' => RequestLimiter::bucket( 'token', '192.0.2.10' ), 'window_started' => StorageRig::T - 10, 'hits' => 500, 'updated_at' => gmdate( 'Y-m-d H:i:s', StorageRig::T - 10 ) ] );

		self::assertSame( [ 'allowed' => false, 'retry_after' => 50 ], $this->limiter()->admit( 'token', '192.0.2.10' ) );
	}

	public function test_competing_processes_never_admit_more_than_the_limit(): void {
		$other = $this->rig->connection();
		$first = $this->limiter( [ 'token' => [ 2, 60 ] ] );
		$second = new RequestLimiter( $other->db, $other->clock, [ 'token' => [ 2, 60 ] ] );
		$admitted = [];
		$this->rig->wpdb->before = static function ( string $sql, FakeWpdb $connection ) use ( $second, &$admitted ): void {
			if ( str_starts_with( $sql, 'UPDATE' ) || str_starts_with( $sql, 'INSERT' ) ) {
				$connection->before = null;
				$admitted[] = $second->admit( 'token', '192.0.2.10' )['allowed'];
			}
		};

		$admitted[] = $first->admit( 'token', '192.0.2.10' )['allowed'];
		$admitted[] = $first->admit( 'token', '192.0.2.10' )['allowed'];
		$admitted[] = $second->admit( 'token', '192.0.2.10' )['allowed'];

		self::assertSame( 2, count( array_filter( $admitted ) ) );
		self::assertSame( '2', $this->rig->rows( 'rate_limits' )[0]['hits'] );
	}

	public function test_a_storage_failure_does_not_block_requests(): void {
		$this->rig->wpdb->fail_when = static fn ( string $sql ): bool => str_contains( $sql, 'rate_limits' );
		$log = (string) tempnam( sys_get_temp_dir(), 'sw-oauth-limits-' );
		$previous = ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		try {
			self::assertSame( [ 'allowed' => true, 'retry_after' => 0 ], $this->limiter()->admit( 'token', '192.0.2.10' ) );
			self::assertStringContainsString( 'oauth_rate_limit_unavailable', (string) file_get_contents( $log ) );
		} finally {
			ini_set( 'error_log', (string) $previous );
			@unlink( $log );
		}
	}
}
