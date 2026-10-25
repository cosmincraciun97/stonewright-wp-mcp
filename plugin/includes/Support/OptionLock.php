<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * A one-at-a-time lock kept in a row of the options table.
 *
 * The options table has a unique key on the option name, so add_option() lets exactly one of two callers insert the
 * row and get the lock. The row is never autoloaded: it must not weigh on every request. Its value is
 * "<time>:<token>", the token being random per acquire. Only the process that wrote a row releases it: the delete
 * names the exact value, so a process that ran past its time to live cannot remove the lock its successor took
 * over. A row older than the time to live belongs to a request that died; it is taken over by deleting exactly
 * that row (compare-and-delete: only one caller can) and inserting once more.
 */
final class OptionLock {

	private const PREFIX = 'stonewright_lock_';

	/** @var array<string, string> Lock name => the row value this process wrote. */
	private static array $held = [];

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
		$value  = $now . ':' . bin2hex( random_bytes( 8 ) );

		$acquired = add_option( $option, $value, '', false );
		if ( ! $acquired ) {
			$stale = get_option( $option, false );
			if ( false !== $stale ) {
				// A bare timestamp (no token) reads as its time; anything unreadable counts as time 0, that is old.
				if ( $now - (int) $stale <= $ttl_seconds || ! self::delete_exact( $option, (string) $stale ) ) {
					return false;
				}
			}
			$acquired = add_option( $option, $value, '', false );
		}

		if ( $acquired ) {
			self::$held[ $name ] = $value;
		}
		return $acquired;
	}

	/**
	 * Releases the lock if this process still owns it; a lock another process took over is left alone.
	 *
	 * @param string   $name Lock name.
	 * @param int|null $now  Unused; kept so both methods take the test clock.
	 */
	public static function release( string $name, ?int $now = null ): void {
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		$value = self::$held[ $name ];
		unset( self::$held[ $name ] );
		self::delete_exact( self::PREFIX . $name, $value );
	}

	/**
	 * Deletes the row only while it still holds this value.
	 */
	private static function delete_exact( string $option, string $value ): bool {
		global $wpdb;
		/** @var \wpdb|null $wpdb */
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			// No database handle to compare on (a test double): read, then delete.
			if ( (string) get_option( $option, '' ) !== $value ) {
				return false;
			}
			return delete_option( $option );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A compare-and-delete on a lock row: delete_option() cannot name the value.
		$deleted = $wpdb->delete(
			$wpdb->options,
			[
				'option_name'  => $option,
				'option_value' => $value,
			]
		);
		wp_cache_delete( $option, 'options' );
		return 1 === (int) $deleted;
	}
}
