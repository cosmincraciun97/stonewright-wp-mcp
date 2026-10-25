<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Stonewright\WpMcp\Support\OptionLock;

/**
 * @covers \Stonewright\WpMcp\Support\OptionLock
 */
final class OptionLockTest extends TestCase {

	protected function setUp(): void {
		$this->reset();
	}

	protected function tearDown(): void {
		$this->reset();
	}

	private function reset(): void {
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_option_autoload'] = [];
		unset( $GLOBALS['stonewright_test_before_option_delete'] );
		( new ReflectionProperty( OptionLock::class, 'held' ) )->setValue( null, [] );
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
		self::assertStringStartsWith( '1061:', $GLOBALS['stonewright_test_options']['stonewright_lock_job'], 'The takeover records its own time.' );
		self::assertFalse( OptionLock::acquire( 'job', 60, 1062 ) );
	}

	public function test_locks_with_different_names_do_not_block_each_other(): void {
		self::assertTrue( OptionLock::acquire( 'one', 60, 1000 ) );
		self::assertTrue( OptionLock::acquire( 'two', 60, 1000 ) );
	}

	public function test_the_lock_row_is_not_autoloaded(): void {
		OptionLock::acquire( 'job', 60, 1000 );

		self::assertMatchesRegularExpression( '/^1000:[0-9a-f]{16}$/', $GLOBALS['stonewright_test_options']['stonewright_lock_job'] );
		self::assertFalse( $GLOBALS['stonewright_test_option_autoload']['stonewright_lock_job'] );
	}

	public function test_release_does_not_delete_a_lock_another_process_took_over(): void {
		self::assertTrue( OptionLock::acquire( 'job', 60, 1000 ) );
		// The first process ran past its ttl; a second one took the lock over (a second request does not share our memory).
		$GLOBALS['stonewright_test_options']['stonewright_lock_job'] = '1100:feedfacefeedface';

		OptionLock::release( 'job' );

		self::assertSame( '1100:feedfacefeedface', $GLOBALS['stonewright_test_options']['stonewright_lock_job'], 'A release only removes the row this process wrote.' );
		self::assertFalse( OptionLock::acquire( 'job', 60, 1101 ) );
	}

	public function test_stale_takeover_only_deletes_the_exact_stale_row(): void {
		$GLOBALS['stonewright_test_options']['stonewright_lock_job'] = '1000:aaaaaaaaaaaaaaaa';
		// Between our read of the stale row and our delete, another process takes the lock over.
		$GLOBALS['stonewright_test_before_option_delete'] = static function (): void {
			$GLOBALS['stonewright_test_options']['stonewright_lock_job'] = '1100:bbbbbbbbbbbbbbbb';
		};

		self::assertFalse( OptionLock::acquire( 'job', 60, 1200 ), 'The delete misses the fresh row, so this caller does not get the lock.' );
		self::assertSame( '1100:bbbbbbbbbbbbbbbb', $GLOBALS['stonewright_test_options']['stonewright_lock_job'] );
	}

	public function test_a_stale_row_is_taken_over_by_deleting_exactly_that_row(): void {
		$GLOBALS['stonewright_test_options']['stonewright_lock_job'] = '1000:aaaaaaaaaaaaaaaa';

		self::assertTrue( OptionLock::acquire( 'job', 60, 1100 ) );
		self::assertStringStartsWith( '1100:', $GLOBALS['stonewright_test_options']['stonewright_lock_job'] );
		self::assertNotSame( '1100:aaaaaaaaaaaaaaaa', $GLOBALS['stonewright_test_options']['stonewright_lock_job'] );

		OptionLock::release( 'job' );

		self::assertArrayNotHasKey( 'stonewright_lock_job', $GLOBALS['stonewright_test_options'] );
	}

	public function test_a_lock_row_without_a_token_still_ages_out(): void {
		// A bare timestamp, as an earlier build wrote it.
		$GLOBALS['stonewright_test_options']['stonewright_lock_job'] = '1000';

		self::assertFalse( OptionLock::acquire( 'job', 60, 1050 ) );
		self::assertTrue( OptionLock::acquire( 'job', 60, 1100 ) );
	}

	public function test_release_of_a_lock_held_by_nobody_is_harmless(): void {
		OptionLock::release( 'nobody' );

		self::assertSame( [], $GLOBALS['stonewright_test_options'] );
		self::assertTrue( OptionLock::acquire( 'nobody', 60, 1000 ) );
	}
}
