<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Write;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Write\CssDirectoryLease;

/**
 * @covers \Stonewright\WpMcp\Elementor\Write\CssDirectoryLease
 */
final class CssDirectoryLeaseTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_before_option_delete'], $GLOBALS['stonewright_test_before_option_update'] );
	}

	public function test_second_owner_is_busy_until_the_shared_directory_lease_is_released(): void {
		$first  = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 30 );
		$second = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-two', 30 );

		self::assertIsArray( $first );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_elementor_css_lease_busy', $second->get_error_code() );
		self::assertTrue( CssDirectoryLease::release( $first ) );
		self::assertIsArray( CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-two', 30 ) );
	}

	public function test_expired_lease_can_be_taken_over_without_double_hashing_scope(): void {
		$first = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 30 );
		self::assertIsArray( $first );
		$key = $first['key'];
		$GLOBALS['stonewright_test_options'][ $key ]['expires_at'] = time() - 1;

		$replacement = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-two', 30 );

		self::assertIsArray( $replacement );
		self::assertSame( CssDirectoryLease::scope_hash( 'uploads/elementor/css' ), $replacement['scope'] );
		self::assertSame( 'txn-two', $replacement['owner'] );
	}

	public function test_expired_takeover_cannot_delete_a_newer_live_lease(): void {
		$first = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 30 );
		self::assertIsArray( $first );
	$GLOBALS['stonewright_test_options'][ $first['key'] ]['expires_at'] = time() - 1;
		$GLOBALS['stonewright_test_before_option_delete'] = static function ( string $option ): void {
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'scope'       => CssDirectoryLease::scope_hash( 'uploads/elementor/css' ),
				'owner'       => 'txn-three',
				'acquired_at' => time(),
				'expires_at'  => time() + 30,
				'ttl'         => 30,
			];
		};

		$replacement = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-two', 30 );

		self::assertInstanceOf( \WP_Error::class, $replacement );
		self::assertSame( 'stonewright_elementor_css_lease_busy', $replacement->get_error_code() );
		self::assertSame( 'txn-three', $GLOBALS['stonewright_test_options'][ $first['key'] ]['owner'] );
	}

	public function test_reclaim_succeeds_after_expiry_when_no_successor_owns_the_lease(): void {
		$lease = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_options'][ $lease['key'] ]['expires_at'] = time() - 1;

		$reclaimed = CssDirectoryLease::reclaim( $lease, 120 );

		self::assertIsArray( $reclaimed );
		self::assertSame( 'txn-one', $reclaimed['owner'] );
		self::assertGreaterThan( time(), $reclaimed['expires_at'] );
	}

	public function test_reclaim_refuses_when_a_different_live_owner_holds_the_lease(): void {
		$lease = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_options'][ $lease['key'] ] = [
			'scope'       => $lease['scope'],
			'owner'       => 'txn-foreign',
			'acquired_at' => time(),
			'expires_at'  => time() + 120,
			'ttl'         => 120,
		];

		$reclaimed = CssDirectoryLease::reclaim( $lease, 120 );

		self::assertInstanceOf( \WP_Error::class, $reclaimed );
		self::assertSame( 'stonewright_elementor_css_lease_busy', $reclaimed->get_error_code() );
	}

	public function test_owner_can_renew_and_wrong_owner_cannot_release(): void {
		$lease = CssDirectoryLease::acquire( 'uploads/elementor/css', 'txn-one', 5 );
		self::assertIsArray( $lease );

		$renewed = CssDirectoryLease::renew( $lease, 60 );
		self::assertIsArray( $renewed );
		self::assertGreaterThan( $lease['expires_at'], $renewed['expires_at'] );

		$foreign = $renewed;
		$foreign['owner'] = 'txn-two';
		self::assertFalse( CssDirectoryLease::release( $foreign ) );
		self::assertInstanceOf( \WP_Error::class, CssDirectoryLease::renew( $foreign, 60 ) );
	}
}
