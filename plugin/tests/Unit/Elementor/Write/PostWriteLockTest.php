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
}
