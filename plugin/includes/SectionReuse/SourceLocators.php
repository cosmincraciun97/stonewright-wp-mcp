<?php
/**
 * The locators an extract accepts for one source post.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * Lists, for one source post, the locators that stonewright-section-reuse-extract accepts: every section the
 * search offers (the top-level elements or blocks) and, for Elementor, the nested containers the search lists
 * under `inner`. It builds them with the code the search uses ({@see SectionSource::sections()},
 * {@see NestedContainers}), so the two cannot drift. An extract that matched nothing returns this list so the
 * next call can name a locator that exists. Reads only.
 */
final class SourceLocators {

	/** Most locators one answer lists. */
	public const MAX = 20;

	/** Largest edit distance at which an id counts as a near miss of a valid one. */
	private const NEAR_MISS_DISTANCE = 2;

	/**
	 * @return array{items:list<array<string,mixed>>,total:int,truncated:bool}
	 */
	public static function of( object $post ): array {
		$all = self::all( $post );

		return [
			'items'     => array_slice( $all['items'], 0, self::MAX ),
			'total'     => count( $all['items'] ),
			'truncated' => $all['truncated'] || count( $all['items'] ) > self::MAX,
		];
	}

	/**
	 * The valid locator that the one given most likely meant, or null when none is close.
	 *
	 * @param array<string, mixed> $locator The locator that matched nothing.
	 * @return array{locator:array<string,mixed>,reason:string}|null
	 */
	public static function closest( object $post, array $locator ): ?array {
		$items = self::all( $post )['items'];
		if ( 'element' === (string) ( $locator['kind'] ?? '' ) ) {
			return self::closest_id( $items, is_scalar( $locator['id'] ?? null ) ? (string) $locator['id'] : '' );
		}
		$anchor = is_scalar( $locator['anchor'] ?? null ) ? strtolower( trim( (string) $locator['anchor'] ) ) : '';
		if ( '' === $anchor ) {
			return null;
		}
		foreach ( $items as $item ) {
			$have = (string) ( $item['locator']['anchor'] ?? '' );
			if ( '' !== $have && strtolower( $have ) === $anchor ) {
				return [ 'locator' => $item['locator'], 'reason' => 'The anchor matches this section when case and spaces are ignored.' ];
			}
		}

		return null;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array{locator:array<string,mixed>,reason:string}|null
	 */
	private static function closest_id( array $items, string $id ): ?array {
		$wanted = strtolower( trim( $id ) );
		if ( '' === $wanted ) {
			return null;
		}
		$best          = null;
		$best_distance = self::NEAR_MISS_DISTANCE + 1;
		foreach ( $items as $item ) {
			$have = (string) ( $item['locator']['id'] ?? '' );
			if ( '' === $have ) {
				continue;
			}
			if ( strtolower( $have ) === $wanted ) {
				return [ 'locator' => $item['locator'], 'reason' => 'The id matches this section when case and spaces are ignored.' ];
			}
			$distance = strlen( $wanted ) >= 3 ? levenshtein( $wanted, strtolower( $have ) ) : $best_distance;
			if ( $distance < $best_distance ) {
				$best          = $item;
				$best_distance = $distance;
			}
		}

		return null === $best ? null : [ 'locator' => $best['locator'], 'reason' => 'The id differs from this section by a few characters.' ];
	}

	/**
	 * Top-level sections first, then nested containers, each in document order.
	 *
	 * @return array{items:list<array<string,mixed>>,truncated:bool}
	 */
	private static function all( object $post ): array {
		$top       = [];
		$nested    = [];
		$truncated = false;
		foreach ( SectionSource::sections( $post ) as $section ) {
			$builder = (string) $section['builder'];
			$node    = $section['node'];
			if ( Builder::is_elementor( $builder ) ) {
				$top[]  = [ 'builder' => $builder ] + NestedContainers::entry( $builder, $node, 1 );
				$inner  = NestedContainers::of( $builder, $node );
				$truncated = $truncated || $inner['truncated'];
				foreach ( $inner['items'] as $entry ) {
					$nested[] = [ 'builder' => $builder ] + $entry;
				}
				continue;
			}
			$entry = [
				'builder' => $builder,
				'locator' => $section['locator'],
				'depth'   => 1,
				'type'    => (string) ( $node['blockName'] ?? '' ),
			];
			$heading = SectionInspector::inspect( $builder, $node )['outline']['heading'];
			if ( '' !== $heading ) {
				$entry['heading'] = $heading;
			}
			$top[] = $entry;
		}

		return [ 'items' => array_merge( $top, $nested ), 'truncated' => $truncated ];
	}
}
