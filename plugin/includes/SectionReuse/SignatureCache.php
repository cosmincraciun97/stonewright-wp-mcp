<?php
/**
 * Cache of section signatures per source.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SectionReuse;

/**
 * One entry per source post, keyed by the post's modification time and the version of the builder that saved
 * it, so a source is analyzed again only after it changes or the builder is updated. The cache lives in one
 * option that is not autoloaded. It never touches the source post: nothing is written to the post or its meta.
 *
 * It holds the compact summaries a search needs (layout, outline, role, reference ids), never section content.
 */
final class SignatureCache {

	public const OPTION      = 'stonewright_section_signatures';
	public const MAX_ENTRIES = 200;

	/** Changes whenever what a signature holds, or how its role is guessed, changes; older entries are analyzed again. */
	private const RULES = 'r3';

	/** @var array<string, array{m:string,v:string,s:int,sections:list<array<string,mixed>>}>|null */
	private static ?array $entries = null;
	private static bool $dirty     = false;
	private static int $hits       = 0;
	private static int $misses     = 0;
	private static int $sequence   = 0;

	/**
	 * The cached sections of a source, or null when there is no entry for its current state.
	 *
	 * @return list<array<string, mixed>>|null
	 */
	public static function get( int $post_id, string $modified, string $version ): ?array {
		$entries = self::load();
		$entry   = $entries[ (string) $post_id ] ?? null;
		if ( null === $entry || $entry['m'] !== $modified || $entry['v'] !== $version . '#' . self::RULES ) {
			++self::$misses;
			return null;
		}
		++self::$hits;
		self::$entries[ (string) $post_id ]['s'] = ++self::$sequence;
		self::$dirty                             = true;

		return $entry['sections'];
	}

	/** @param list<array<string, mixed>> $sections */
	public static function put( int $post_id, string $modified, string $version, array $sections ): void {
		self::load();
		self::$entries[ (string) $post_id ] = [ 'm' => $modified, 'v' => $version . '#' . self::RULES, 's' => ++self::$sequence, 'sections' => $sections ];
		self::$dirty                        = true;
	}

	/** Writes the cache once, dropping the entries used least recently when it is over its size. */
	public static function flush(): void {
		if ( ! self::$dirty || null === self::$entries ) {
			return;
		}
		$entries = self::$entries;
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			uasort( $entries, static fn( array $left, array $right ): int => $right['s'] <=> $left['s'] );
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES, true );
		}
		update_option( self::OPTION, $entries, false );
		self::$entries = $entries;
		self::$dirty   = false;
	}

	/** @return array{hits:int,misses:int} */
	public static function stats(): array {
		return [ 'hits' => self::$hits, 'misses' => self::$misses ];
	}

	/** Forgets what this request loaded and counted. The stored option stays. */
	public static function reset_for_tests(): void {
		self::$entries  = null;
		self::$dirty    = false;
		self::$hits     = 0;
		self::$misses   = 0;
		self::$sequence = 0;
	}

	/** @return array<string, array{m:string,v:string,s:int,sections:list<array<string,mixed>>}> */
	private static function load(): array {
		if ( null !== self::$entries ) {
			return self::$entries;
		}
		$stored  = get_option( self::OPTION, [] );
		$entries = [];
		foreach ( is_array( $stored ) ? $stored : [] as $id => $entry ) {
			if ( is_array( $entry ) && isset( $entry['m'], $entry['v'], $entry['sections'] ) && is_string( $entry['m'] ) && is_string( $entry['v'] ) && is_array( $entry['sections'] ) ) {
				$entries[ (string) $id ] = [ 'm' => $entry['m'], 'v' => $entry['v'], 's' => (int) ( $entry['s'] ?? 0 ), 'sections' => array_values( $entry['sections'] ) ];
				self::$sequence          = max( self::$sequence, (int) ( $entry['s'] ?? 0 ) );
			}
		}
		self::$entries = $entries;

		return $entries;
	}
}
