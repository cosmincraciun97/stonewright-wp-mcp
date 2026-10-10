<?php
/**
 * Layout similarity between a section and a wanted layout.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * A score from 0 to 1 for how closely a section's layout summary matches a wanted layout. The wanted layout
 * is whatever the caller asked for (`columns`, `items` for the number of repeated children, `depth`, and
 * `types` for builder-native node types), or the profile of the requested role when the caller gave none.
 *
 * Only structure counts: the score never reads text, media or style values, and the same inputs always give
 * the same score. Each requested number is compared as `1 - |a - b| / max(a, b)`, the types as the
 * overlap of two sets, and the score is the mean of the components that were requested, to three places.
 */
final class Similarity {

	/** @var array<string, array{columns:int,items:int,depth:int}> */
	private const PROFILES = [
		'hero'         => [ 'columns' => 2, 'items' => 0, 'depth' => 3 ],
		'features'     => [ 'columns' => 3, 'items' => 3, 'depth' => 4 ],
		'testimonials' => [ 'columns' => 3, 'items' => 3, 'depth' => 4 ],
		'pricing'      => [ 'columns' => 3, 'items' => 3, 'depth' => 5 ],
		'faq'          => [ 'columns' => 0, 'items' => 5, 'depth' => 3 ],
		'cta'          => [ 'columns' => 0, 'items' => 0, 'depth' => 2 ],
		'gallery'      => [ 'columns' => 3, 'items' => 6, 'depth' => 3 ],
		'contact'      => [ 'columns' => 2, 'items' => 0, 'depth' => 4 ],
	];

	/**
	 * The layout a role usually has; empty for a role with no profile.
	 *
	 * @return array{columns?:int,items?:int,depth?:int}
	 */
	public static function profile( string $role ): array {
		return self::PROFILES[ $role ] ?? [];
	}

	/**
	 * @param array{columns:int,depth:int,types:array<string,int>,repeated:array{count:int}|null} $summary A {@see LayoutSummary} result.
	 * @param array{columns?:int,items?:int,depth?:int,types?:list<string>}                         $want    The wanted layout.
	 */
	public static function score( array $summary, array $want ): float {
		$parts = [];
		if ( isset( $want['columns'] ) ) {
			$parts[] = self::ratio( (int) $summary['columns'], (int) $want['columns'] );
		}
		if ( isset( $want['items'] ) ) {
			$parts[] = self::ratio( (int) ( $summary['repeated']['count'] ?? 0 ), (int) $want['items'] );
		}
		if ( isset( $want['depth'] ) ) {
			$parts[] = self::ratio( (int) $summary['depth'], (int) $want['depth'] );
		}
		if ( isset( $want['types'] ) && is_array( $want['types'] ) && [] !== $want['types'] ) {
			$have    = array_map( 'strval', array_keys( $summary['types'] ) );
			$wanted  = array_values( array_unique( array_map( 'strval', $want['types'] ) ) );
			$union   = count( array_unique( array_merge( $have, $wanted ) ) );
			$parts[] = 0 === $union ? 1.0 : count( array_intersect( $have, $wanted ) ) / $union;
		}
		if ( [] === $parts ) {
			return 0.5;
		}

		return round( array_sum( $parts ) / count( $parts ), 3 );
	}

	private static function ratio( int $have, int $want ): float {
		$largest = max( $have, $want );

		return 0 === $largest ? 1.0 : 1.0 - abs( $have - $want ) / $largest;
	}
}
