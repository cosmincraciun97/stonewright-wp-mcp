<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * A one-at-a-time lock kept in a row of the options table.
 *
 * The options table has a unique key on the option name, so add_option() lets exactly one of two callers insert the
 * row and get the lock. The row is never autoloaded: it must not weigh on every request. A row older than the time
 * to live belongs to a request that died; it is deleted and the insert tried once more.
 */
final class OptionLock {

	private const PREFIX = 'stonewright_lock_';

	/**
	 * Takes the lock.
	 *
	 * @param string   $name        Lock name; the row is stonewright_lock_<name>.
	 * @param int      $ttl_seconds Age after which a held lock counts as abandoned.
	 * @param int|null $now         Current time (tests only).
	 * @return bool True only for the caller that inserted the row.
	 */
	public static function acquire( string $name, int $ttl_seconds, ?int $now = null ): bool {
		$now    = $now ?? time();
		$option = self::PREFIX . $name;

		if ( add_option( $option, $now, '', false ) ) {
			return true;
		}

		$held = get_option( $option, false );
		if ( false !== $held && $now - (int) $held <= $ttl_seconds ) {
			return false;
		}

		// Abandoned (or released in between): clear it and let the INSERT pick one winner.
		delete_option( $option );
		return add_option( $option, $now, '', false );
	}

	public static function release( string $name, ?int $now = null ): void {
		delete_option( self::PREFIX . $name );
	}
}
