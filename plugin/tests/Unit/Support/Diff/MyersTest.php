<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Support\Diff;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Support\Diff\Myers;

/**
 * @covers \Stonewright\WpMcp\Support\Diff\Myers
 */
final class MyersTest extends TestCase {

	public function test_matches_are_a_longest_common_subsequence(): void {
		mt_srand( 4242 );
		for ( $round = 0; $round < 200; $round++ ) {
			$a = [];
			$b = [];
			$n = mt_rand( 0, 14 );
			$m = mt_rand( 0, 14 );
			for ( $i = 0; $i < $n; $i++ ) {
				$a[] = 'l' . mt_rand( 0, 5 );
			}
			for ( $i = 0; $i < $m; $i++ ) {
				$b[] = 'l' . mt_rand( 0, 5 );
			}

			$pairs = Myers::matches( $a, $b, 1000 );

			$this->assertIsArray( $pairs );
			$this->assertSame( $this->lcs_length( $a, $b ), count( $pairs ), 'round ' . $round );
			$prev_a = -1;
			$prev_b = -1;
			foreach ( $pairs as [ $i, $j ] ) {
				$this->assertGreaterThan( $prev_a, $i );
				$this->assertGreaterThan( $prev_b, $j );
				$this->assertSame( $a[ $i ], $b[ $j ] );
				$prev_a = $i;
				$prev_b = $j;
			}
		}
	}

	public function test_identical_and_empty_inputs(): void {
		$this->assertSame( [], Myers::matches( [], [], 10 ) );
		$this->assertSame( [], Myers::matches( [ 'a' ], [], 10 ) );
		$this->assertSame( [ [ 0, 0 ], [ 1, 1 ] ], Myers::matches( [ 'a', 'b' ], [ 'a', 'b' ], 10 ) );
	}

	public function test_returns_null_above_the_edit_distance_budget(): void {
		$a = [];
		$b = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$a[] = 'x' . $i;
			$b[] = 'x' . ( 59 - $i );
		}
		$this->assertNull( Myers::matches( $a, $b, 20 ) );
	}

	/**
	 * @param list<string> $a
	 * @param list<string> $b
	 */
	private function lcs_length( array $a, array $b ): int {
		$n   = count( $a );
		$m   = count( $b );
		$row = array_fill( 0, $m + 1, 0 );
		for ( $i = 1; $i <= $n; $i++ ) {
			$prev = $row;
			for ( $j = 1; $j <= $m; $j++ ) {
				$row[ $j ] = $a[ $i - 1 ] === $b[ $j - 1 ] ? $prev[ $j - 1 ] + 1 : max( $prev[ $j ], $row[ $j - 1 ] );
			}
		}
		return $row[ $m ];
	}
}
