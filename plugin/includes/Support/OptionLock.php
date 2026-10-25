<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

/**
 * A best-effort one-at-a-time lock kept in a row of the options table.
 *
 * The step that decides who holds the lock is a plain INSERT of the row: the options table has a unique key on the
 * option name, so of two processes that insert at once exactly one gets "1 row inserted" and the other gets a
 * duplicate-key error. add_option() is not used: it reads first and then writes with ON DUPLICATE KEY UPDATE, so two
 * processes can both be told they added the row, and with a persistent object cache a remembered miss skips the read.
 * The row is never autoloaded: it must not weigh on every request. Its value is "<time>:<token>", the token being
 * random per acquire. A held lock is read straight from the database, never from the options cache.
 *
 * What is guaranteed: at most one process holds a row that is younger than the time to live. Only the process that
 * wrote a row releases it: the delete names the exact value, so a process that ran past its time to live cannot
 * remove the lock its successor took over. A row older than the time to live belongs to a request that died; it is
 * taken over by deleting exactly that row (compare-and-delete: only one caller can) and inserting once more.
 *
 * What is not: a holder that runs longer than the time to live loses the lock to the next caller and goes on running,
 * so the work under it must stay safe to repeat. A database that cannot be written (or no database handle) never
 * grants the lock.
 */
final class OptionLock {

	private const PREFIX = 'stonewright_lock_';

	/** @var array<string, string> Lock name => the row value this process wrote. */
	private static array $held = [];

	/** @var object|null A database handle used instead of $wpdb. For tests, which swap $wpdb for table doubles. */
	private static ?object $database = null;

	/** Replace the database handle the lock rows are written through. For tests. */
	public static function use_database( ?object $database ): void {
		self::$database = $database;
	}

	private static function database(): ?object {
		$database = self::$database ?? ( $GLOBALS['wpdb'] ?? null );
		return is_object( $database ) ? $database : null;
	}

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

		$acquired = self::insert( $option, $value );
		if ( ! $acquired ) {
			$stale = self::stored_value( $option );
			if ( null !== $stale ) {
				// A bare timestamp (no token) reads as its time; anything unreadable counts as time 0, that is old.
				if ( $now - (int) $stale <= $ttl_seconds || ! self::delete_exact( $option, $stale ) ) {
					return false;
				}
			}
			$acquired = self::insert( $option, $value );
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
		/** @var \wpdb|null $wpdb */
		$wpdb = self::database();
		if ( null === $wpdb || ! method_exists( $wpdb, 'delete' ) ) {
			return false;
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

	/**
	 * Inserts the row; true only when exactly one row went in. A row that already exists is a duplicate-key error,
	 * which is the expected answer of the loser and is not logged.
	 */
	private static function insert( string $option, string $value ): bool {
		/** @var \wpdb|null $wpdb */
		$wpdb = self::database();
		if ( null === $wpdb || ! method_exists( $wpdb, 'insert' ) ) {
			return false;
		}
		$quiet = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A plain INSERT is the atomic step; the options API is not.
			$inserted = $wpdb->insert(
				$wpdb->options,
				[
					'option_name'  => $option,
					'option_value' => $value,
					'autoload'     => 'no',
				]
			);
		} finally {
			if ( null !== $quiet ) {
				$wpdb->suppress_errors( (bool) $quiet );
			}
		}
		if ( 1 !== (int) $inserted ) {
			return false;
		}
		// Readers of the options cache must see the row: forget a cached miss.
		wp_cache_delete( $option, 'options' );
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ $option ] ) ) {
			unset( $missing[ $option ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
		return true;
	}

	/**
	 * The value of the row as the database holds it, null when there is no row.
	 */
	private static function stored_value( string $option ): ?string {
		/** @var \wpdb|null $wpdb */
		$wpdb = self::database();
		if ( null === $wpdb || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The options cache can be stale or remember a miss; only the table says who holds the lock.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option ) );
		return null === $value ? null : (string) $value;
	}
}
