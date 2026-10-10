<?php
/**
 * Longest common subsequence of two sequences, by the O(ND) greedy algorithm.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Support\Diff;

/**
 * Matches the elements two sequences have in common, in order.
 *
 * The edit script of two sequences is the set of elements outside the match: an
 * element of the first sequence that is not matched was deleted, an element of
 * the second that is not matched was inserted. The search follows the furthest
 * reaching path on each diagonal, one edit at a time, so the work grows with the
 * number of edits and not with the product of the lengths.
 *
 * Three steps keep the work small before the search starts: the common prefix
 * and suffix are matched directly, and an element that occurs in only one of the
 * two remaining ranges is left out because it can never be part of a match.
 */
final class Myers {

	/**
	 * Index pairs of the elements the two sequences share, ascending in both.
	 *
	 * @param array<int, string|int> $a      First sequence.
	 * @param array<int, string|int> $b      Second sequence.
	 * @param int                    $max_d  Most edits to search for. The result is null when
	 *                                       the sequences need more than this.
	 * @return list<array{0: int, 1: int}>|null Pairs of (index in a, index in b).
	 */
	public static function matches( array $a, array $b, int $max_d ): ?array {
		$a = array_values( $a );
		$b = array_values( $b );
		$n = count( $a );
		$m = count( $b );

		$prefix = 0;
		while ( $prefix < $n && $prefix < $m && $a[ $prefix ] === $b[ $prefix ] ) {
			++$prefix;
		}
		$suffix = 0;
		while ( $suffix < $n - $prefix && $suffix < $m - $prefix && $a[ $n - 1 - $suffix ] === $b[ $m - 1 - $suffix ] ) {
			++$suffix;
		}

		$pairs = [];
		for ( $i = 0; $i < $prefix; $i++ ) {
			$pairs[] = [ $i, $i ];
		}

		$core = self::middle( $a, $b, $prefix, $n - $suffix, $m - $suffix, $max_d );
		if ( null === $core ) {
			return null;
		}
		foreach ( $core as $pair ) {
			$pairs[] = $pair;
		}

		for ( $i = $suffix; $i > 0; $i-- ) {
			$pairs[] = [ $n - $i, $m - $i ];
		}

		return $pairs;
	}

	/**
	 * Matches inside the ranges a[$from..$a_end) and b[$from..$b_end).
	 *
	 * @param array<int, string|int> $a
	 * @param array<int, string|int> $b
	 * @return list<array{0: int, 1: int}>|null
	 */
	private static function middle( array $a, array $b, int $from, int $a_end, int $b_end, int $max_d ): ?array {
		if ( $a_end <= $from || $b_end <= $from ) {
			return [];
		}

		$in_a = [];
		for ( $i = $from; $i < $a_end; $i++ ) {
			$in_a[ $a[ $i ] ] = true;
		}
		$in_b = [];
		for ( $j = $from; $j < $b_end; $j++ ) {
			$in_b[ $b[ $j ] ] = true;
		}

		$ids        = [];
		$left       = [];
		$left_index = [];
		for ( $i = $from; $i < $a_end; $i++ ) {
			if ( isset( $in_b[ $a[ $i ] ] ) ) {
				$left[]       = $ids[ $a[ $i ] ] ??= count( $ids );
				$left_index[] = $i;
			}
		}
		$right       = [];
		$right_index = [];
		for ( $j = $from; $j < $b_end; $j++ ) {
			if ( isset( $in_a[ $b[ $j ] ] ) ) {
				$right[]       = $ids[ $b[ $j ] ] ??= count( $ids );
				$right_index[] = $j;
			}
		}

		$found = self::search( $left, $right, $max_d );
		if ( null === $found ) {
			return null;
		}

		$out = [];
		foreach ( $found as [ $i, $j ] ) {
			$out[] = [ $left_index[ $i ], $right_index[ $j ] ];
		}
		return $out;
	}

	/**
	 * The greedy search with a snapshot of the frontier after every edit count.
	 *
	 * @param list<int> $a
	 * @param list<int> $b
	 * @return list<array{0: int, 1: int}>|null
	 */
	private static function search( array $a, array $b, int $max_d ): ?array {
		$n = count( $a );
		$m = count( $b );
		if ( 0 === $n || 0 === $m ) {
			return [];
		}

		$max    = max( 0, min( $n + $m, $max_d ) );
		$offset = $max + 1;
		$v      = array_fill( 0, 2 * $max + 3, 0 );
		$trace  = [];

		for ( $d = 0; $d <= $max; $d++ ) {
			for ( $k = -$d; $k <= $d; $k += 2 ) {
				if ( $k === -$d || ( $k !== $d && $v[ $offset + $k - 1 ] < $v[ $offset + $k + 1 ] ) ) {
					$x = $v[ $offset + $k + 1 ];
				} else {
					$x = $v[ $offset + $k - 1 ] + 1;
				}
				$y = $x - $k;
				while ( $x < $n && $y < $m && $a[ $x ] === $b[ $y ] ) {
					++$x;
					++$y;
				}
				$v[ $offset + $k ] = $x;
				if ( $x >= $n && $y >= $m ) {
					return self::backtrack( $trace, $d, $n, $m );
				}
			}
			$trace[ $d ] = array_slice( $v, $offset - $d, 2 * $d + 1 );
		}

		return null;
	}

	/**
	 * Walks the snapshots from the end back to the start and collects the diagonals.
	 *
	 * @param array<int, list<int>> $trace Frontier after each edit count; entry d holds diagonals -d..d.
	 * @return list<array{0: int, 1: int}>
	 */
	private static function backtrack( array $trace, int $edits, int $n, int $m ): array {
		$x       = $n;
		$y       = $m;
		$reverse = [];

		for ( $d = $edits; $d > 0; $d-- ) {
			$prev = $trace[ $d - 1 ];
			$base = $d - 1;
			$k    = $x - $y;
			if ( $k === -$d || ( $k !== $d && $prev[ $base + $k - 1 ] < $prev[ $base + $k + 1 ] ) ) {
				$from_k = $k + 1;
			} else {
				$from_k = $k - 1;
			}
			$from_x = $prev[ $base + $from_k ];
			$from_y = $from_x - $from_k;
			$mid_x  = $from_k === $k + 1 ? $from_x : $from_x + 1;
			$mid_y  = $mid_x - $k;
			while ( $x > $mid_x && $y > $mid_y ) {
				--$x;
				--$y;
				$reverse[] = [ $x, $y ];
			}
			$x = $from_x;
			$y = $from_y;
		}
		while ( $x > 0 && $y > 0 ) {
			--$x;
			--$y;
			$reverse[] = [ $x, $y ];
		}

		return array_reverse( $reverse );
	}
}
