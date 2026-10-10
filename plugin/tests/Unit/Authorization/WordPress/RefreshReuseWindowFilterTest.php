<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization\WordPress;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationLifecycle;
use Stonewright\WpMcp\Authorization\WordPress\AuthorizationStorage;
use Stonewright\WpMcp\Tests\Unit\Authorization\WordPress\Fixtures\StorageRig;

/**
 * The stonewright_oauth_refresh_reuse_window filter sets how long a refresh credential
 * that was just rotated is answered with the current credential instead of being
 * treated as a replay: the default is 60 seconds, a value is clamped to 0 to 300, and a
 * value that is not a number leaves the default in place.
 *
 * @covers \Stonewright\WpMcp\Authorization\WordPress\AuthorizationStorage
 */
final class RefreshReuseWindowFilterTest extends TestCase {

	protected function setUp(): void {
		StorageRig::reset_globals();
		$GLOBALS['stonewright_test_filters'] = [];
		AuthorizationLifecycle::use_storage( null );
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_filters'] = [];
		StorageRig::reset_globals();
	}

	private static function window_for( mixed $filtered ): int {
		$GLOBALS['stonewright_test_filters'][ AuthorizationStorage::DUPLICATE_WINDOW_FILTER ] = static fn (): mixed => $filtered;
		return AuthorizationStorage::wordpress()->policy()->duplicate_window();
	}

	public function test_the_filter_name_is_the_documented_one(): void {
		self::assertSame( 'stonewright_oauth_refresh_reuse_window', AuthorizationStorage::DUPLICATE_WINDOW_FILTER );
	}

	public function test_the_default_is_sixty_seconds_without_a_filter(): void {
		self::assertSame( 60, AuthorizationStorage::wordpress()->policy()->duplicate_window() );
	}

	public function test_a_filtered_number_of_seconds_is_used(): void {
		self::assertSame( 120, self::window_for( 120 ) );
		self::assertSame( 90, self::window_for( '90' ) );
		self::assertSame( 1, self::window_for( 1 ) );
	}

	public function test_zero_means_strict_single_use_rotation(): void {
		self::assertSame( 0, self::window_for( 0 ) );
		self::assertFalse( AuthorizationStorage::wordpress()->policy()->within_duplicate_window( 1000, 1000 ) );
	}

	public function test_a_value_outside_the_range_is_clamped(): void {
		self::assertSame( 300, self::window_for( 99999 ) );
		self::assertSame( 0, self::window_for( -5 ) );
	}

	public function test_a_value_that_is_not_a_number_leaves_the_default(): void {
		foreach ( [ 'soon', null, [ 30 ], false ] as $value ) {
			self::assertSame( 60, self::window_for( $value ), json_encode( $value ) );
		}
	}

	public function test_the_filtered_window_is_strict_at_its_end(): void {
		$GLOBALS['stonewright_test_filters'][ AuthorizationStorage::DUPLICATE_WINDOW_FILTER ] = static fn (): int => 200;

		$policy = AuthorizationStorage::wordpress()->policy();

		self::assertTrue( $policy->within_duplicate_window( 1000, 1199 ) );
		self::assertFalse( $policy->within_duplicate_window( 1000, 1200 ), 'The window is strict: outside at exactly consumed_at + window.' );
	}
}
