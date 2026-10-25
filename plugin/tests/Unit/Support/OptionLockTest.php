<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\OptionLock;

/**
 * @covers \Stonewright\WpMcp\Support\OptionLock
 */
final class OptionLockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_option_autoload'] = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_option_autoload'] = [];
	}

	public function test_first_acquire_wins_and_second_fails_until_release(): void {
		self::assertTrue( OptionLock::acquire( 'job', 60, 1000 ) );
		self::assertFalse( OptionLock::acquire( 'job', 60, 1001 ) );

		OptionLock::release( 'job' );

		self::assertTrue( OptionLock::acquire( 'job', 60, 1002 ) );
	}

	public function test_a_stale_lock_is_taken_over_after_its_ttl(): void {
		self::assertTrue( OptionLock::acquire( 'job', 60, 1000 ) );

		self::assertFalse( OptionLock::acquire( 'job', 60, 1060 ), 'A lock exactly as old as the ttl is still held.' );
		self::assertTrue( OptionLock::acquire( 'job', 60, 1061 ) );
		self::assertSame( 1061, $GLOBALS['stonewright_test_options']['stonewright_lock_job'], 'The takeover records its own time.' );
		self::assertFalse( OptionLock::acquire( 'job', 60, 1062 ) );
	}

	public function test_locks_with_different_names_do_not_block_each_other(): void {
		self::assertTrue( OptionLock::acquire( 'one', 60, 1000 ) );
		self::assertTrue( OptionLock::acquire( 'two', 60, 1000 ) );
	}

	public function test_the_lock_row_is_not_autoloaded(): void {
		OptionLock::acquire( 'job', 60, 1000 );

		self::assertSame( 1000, $GLOBALS['stonewright_test_options']['stonewright_lock_job'] );
		self::assertFalse( $GLOBALS['stonewright_test_option_autoload']['stonewright_lock_job'] );
	}

	public function test_release_of_a_lock_held_by_nobody_is_harmless(): void {
		OptionLock::release( 'nobody' );

		self::assertSame( [], $GLOBALS['stonewright_test_options'] );
		self::assertTrue( OptionLock::acquire( 'nobody', 60, 1000 ) );
	}
}
