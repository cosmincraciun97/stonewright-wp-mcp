<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Elementor\Write;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Elementor\Write\PostWriteLock;

/**
 * @covers \Stonewright\WpMcp\Elementor\Write\PostWriteLock
 */
final class PostWriteLockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset(
			$GLOBALS['stonewright_test_before_option_delete'],
			$GLOBALS['stonewright_test_before_option_update'],
			$GLOBALS['stonewright_test_option_cas_miss_remaining']
		);
	}

	public function test_second_owner_is_busy_until_first_releases(): void {
		$first  = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		$second = PostWriteLock::acquire( 9049, 'txn-two', 30 );

		self::assertIsArray( $first );
		self::assertInstanceOf( \WP_Error::class, $second );
		self::assertSame( 'stonewright_elementor_write_busy', $second->get_error_code() );
		self::assertGreaterThan( time(), $second->get_error_data()['lock_expires_at'] );
		self::assertTrue( PostWriteLock::release( 9049, 'txn-one' ) );
		self::assertIsArray( PostWriteLock::acquire( 9049, 'txn-two', 30 ) );
	}

	public function test_wrong_owner_cannot_release_lock(): void {
		PostWriteLock::acquire( 9049, 'txn-one', 30 );

		self::assertFalse( PostWriteLock::release( 9049, 'txn-two' ) );
		self::assertInstanceOf( \WP_Error::class, PostWriteLock::acquire( 9049, 'txn-two', 30 ) );
	}

	public function test_expired_lease_can_be_replaced(): void {
		PostWriteLock::acquire( 9049, 'txn-one', 30 );
		$key = array_key_first( $GLOBALS['stonewright_test_options'] );
		$GLOBALS['stonewright_test_options'][ $key ]['expires_at'] = time() - 1;

		$replacement = PostWriteLock::acquire( 9049, 'txn-two', 30 );

		self::assertIsArray( $replacement );
		self::assertSame( 'txn-two', $replacement['owner'] );
	}

	public function test_expired_takeover_cannot_delete_a_newer_live_lease(): void {
		PostWriteLock::acquire( 9049, 'txn-one', 30 );
		$key = array_key_first( $GLOBALS['stonewright_test_options'] );
		$GLOBALS['stonewright_test_options'][ $key ]['expires_at'] = time() - 1;
		$GLOBALS['stonewright_test_before_option_delete'] = static function ( string $option ): void {
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'post_id'    => 9049,
				'owner'      => 'txn-three',
				'expires_at' => time() + 30,
			];
		};

		$replacement = PostWriteLock::acquire( 9049, 'txn-two', 30 );

		self::assertInstanceOf( \WP_Error::class, $replacement );
		self::assertSame( 'stonewright_elementor_write_busy', $replacement->get_error_code() );
		self::assertSame( 'txn-three', $GLOBALS['stonewright_test_options'][ $key ]['owner'] );
	}

	public function test_release_cannot_delete_a_newer_owner_after_the_lease_is_observed(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_before_option_delete'] = static function ( string $option ): void {
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'post_id'     => 9049,
				'owner'       => 'txn-two',
				'acquired_at' => time(),
				'expires_at'  => time() + 30,
			];
		};

		self::assertFalse( PostWriteLock::release( 9049, 'txn-one' ) );
		self::assertSame( 'txn-two', $GLOBALS['stonewright_test_options'][ 'stonewright_elementor_lock_9049' ]['owner'] );
	}

	public function test_owner_can_renew_its_lease_without_extending_another_owner(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 5 );
		self::assertIsArray( $lease );
		$renewed = PostWriteLock::renew( $lease, 60 );

		self::assertIsArray( $renewed );
		self::assertSame( 'txn-one', $renewed['owner'] );
		self::assertGreaterThan( $lease['expires_at'], $renewed['expires_at'] );

		$other = PostWriteLock::acquire( 9050, 'txn-two', 5 );
		self::assertIsArray( $other );
		$foreign = PostWriteLock::renew( [ 'post_id' => 9050, 'owner' => 'txn-one' ] + $other, 60 );
		self::assertInstanceOf( \WP_Error::class, $foreign );
	}

	public function test_renew_retries_a_cas_miss_while_the_live_lease_is_still_ours(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_option_cas_miss_remaining'] = 1;

		$renewed = PostWriteLock::renew( $lease, 60 );

		self::assertIsArray( $renewed );
		self::assertSame( 'txn-one', $renewed['owner'] );
		self::assertSame( 0, (int) ( $GLOBALS['stonewright_test_option_cas_miss_remaining'] ?? 0 ) );
		self::assertTrue( PostWriteLock::owned_by( 9049, 'txn-one' ) );
		self::assertGreaterThan( $lease['expires_at'], $renewed['expires_at'] );
	}

	public function test_renew_retries_with_the_fresh_observed_row_after_a_serialization_cas_miss(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_before_option_update'] = static function ( string $option ): void {
			$row = $GLOBALS['stonewright_test_options'][ $option ];
			$row['expires_at'] = (string) $row['expires_at'];
			$GLOBALS['stonewright_test_options'][ $option ] = $row;
		};

		$renewed = PostWriteLock::renew( $lease, 60 );

		self::assertIsArray( $renewed );
		self::assertSame( 'txn-one', $renewed['owner'] );
		self::assertTrue( PostWriteLock::owned_by( 9049, 'txn-one' ) );
	}

	public function test_renew_returns_the_live_lease_after_repeated_cas_misses_while_owned(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_option_cas_miss_remaining'] = 3;

		$renewed = PostWriteLock::renew( $lease, 60 );

		self::assertIsArray( $renewed );
		self::assertSame( 'txn-one', $renewed['owner'] );
		self::assertTrue( PostWriteLock::owned_by( 9049, 'txn-one' ) );
		self::assertSame( 'txn-one', $GLOBALS['stonewright_test_options']['stonewright_elementor_lock_9049']['owner'] );
	}

	public function test_renew_still_fails_when_a_cas_miss_reveals_a_foreign_owner(): void {
		$lease = PostWriteLock::acquire( 9049, 'txn-one', 30 );
		self::assertIsArray( $lease );
		$GLOBALS['stonewright_test_before_option_update'] = static function ( string $option ): void {
			$GLOBALS['stonewright_test_options'][ $option ] = [
				'post_id'     => 9049,
				'owner'       => 'txn-foreign',
				'acquired_at' => time(),
				'expires_at'  => time() + 30,
			];
		};

		$renewed = PostWriteLock::renew( $lease, 60 );

		self::assertInstanceOf( \WP_Error::class, $renewed );
		self::assertSame( 'stonewright_elementor_lock_lost', $renewed->get_error_code() );
		self::assertSame( 'txn-foreign', $GLOBALS['stonewright_test_options']['stonewright_elementor_lock_9049']['owner'] );
		self::assertFalse( PostWriteLock::owned_by( 9049, 'txn-one' ) );
	}
}
