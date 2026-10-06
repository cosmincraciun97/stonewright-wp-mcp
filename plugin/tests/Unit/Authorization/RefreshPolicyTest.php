<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Refresh\RefreshPolicy;

final class RefreshPolicyTest extends TestCase {

	private const T = 1700000000;

	/** @dataProvider windows */
	public function test_duplicate_window_is_clamped_to_the_supported_range( int $requested, int $effective ): void {
		self::assertSame( $effective, ( new RefreshPolicy( $requested ) )->duplicate_window() );
	}

	public function windows(): array {
		return [
			'negative' => [ -5, 0 ],
			'smallest integer' => [ PHP_INT_MIN, 0 ],
			'zero means strict rotation' => [ 0, 0 ],
			'inside the range' => [ 120, 120 ],
			'maximum' => [ 300, 300 ],
			'above the maximum' => [ 301, 300 ],
			'largest integer' => [ PHP_INT_MAX, 300 ],
		];
	}

	public function test_default_window_is_sixty_seconds(): void {
		self::assertSame( 60, ( new RefreshPolicy() )->duplicate_window() );
	}

	public function test_lifetimes_are_one_hour_thirty_idle_days_and_ninety_family_days(): void {
		$policy = new RefreshPolicy();
		$deadline = $policy->family_deadline( self::T );
		self::assertSame( self::T + 3600, $policy->access_deadline( self::T ) );
		self::assertSame( self::T + 7776000, $deadline );
		self::assertSame( self::T + 2592000, $policy->refresh_deadline( self::T, $deadline ) );
		self::assertSame( $deadline, $policy->refresh_deadline( self::T + 89 * 86400, $deadline ) );
	}

	public function test_window_comparison_is_strict_and_a_backwards_clock_counts_as_no_elapsed_time(): void {
		$policy = new RefreshPolicy( 60 );
		self::assertTrue( $policy->within_duplicate_window( self::T, self::T + 59 ) );
		self::assertFalse( $policy->within_duplicate_window( self::T, self::T + 60 ) );
		self::assertTrue( $policy->within_duplicate_window( self::T, self::T - 3600 ) );
		self::assertFalse( ( new RefreshPolicy( 0 ) )->within_duplicate_window( self::T, self::T ) );
		self::assertFalse( ( new RefreshPolicy( 0 ) )->within_duplicate_window( self::T, self::T - 3600 ) );
	}

	public function test_unrepresentable_times_are_refused(): void {
		$policy = new RefreshPolicy();
		self::assertFalse( $policy->accepts_time( 0 ) );
		self::assertTrue( $policy->accepts_time( PHP_INT_MAX - 7776000 ) );
		self::assertFalse( $policy->accepts_time( PHP_INT_MAX - 7776000 + 1 ) );
		$this->expectException( \InvalidArgumentException::class );
		$policy->family_deadline( PHP_INT_MAX );
	}
}
