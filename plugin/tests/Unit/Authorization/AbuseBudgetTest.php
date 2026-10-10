<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Authorization\Decisions\AbuseBudget;

final class AbuseBudgetTest extends TestCase {

	public function test_budget_is_bounded_and_resets_at_the_window_boundary(): void {
		$budget = new AbuseBudget( 2, 60 );
		$first = $budget->admit( null, 100 );
		$second = $budget->admit( $first['window'], 101 );
		$third = $budget->admit( $second['window'], 102 );
		self::assertTrue( $first['allowed'] );
		self::assertTrue( $second['allowed'] );
		self::assertFalse( $third['allowed'] );
		self::assertSame( 58, $third['retry_after'] );
		self::assertSame( 2, $third['window']['count'] );
		self::assertTrue( $budget->admit( $third['window'], 160 )['allowed'] );
	}

	public function test_clock_rollback_cannot_reset_a_spent_budget(): void {
		$outcome = ( new AbuseBudget( 2, 60 ) )->admit( [ 'started_at' => 100, 'count' => 2 ], 90 );
		self::assertFalse( $outcome['allowed'] );
		self::assertSame( 60, $outcome['retry_after'] );
	}

	public function test_corrupt_window_fails_closed(): void {
		$this->expectException( \InvalidArgumentException::class );
		( new AbuseBudget( 2, 60 ) )->admit( [ 'started_at' => 100, 'count' => -1 ], 110 );
	}
}
