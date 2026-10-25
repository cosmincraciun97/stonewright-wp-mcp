<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Throwable;

/**
 * Keeps a store's table install off the request path.
 *
 * A request trusts the version option (autoloaded, so already in memory) and runs nothing when it is current.
 * Otherwise one process at a time installs, under an OptionLock. A failed install starts a back-off, so a database
 * that refuses the change is asked once per BACKOFF_SECONDS and not on every request. repair() is the same without
 * the version short-circuit, for activation, a plugin upgrade, the daily jobs and a write that hit a missing column.
 */
final class SchemaGate {

	/** Seconds a failed install stays off the request path. */
	public const BACKOFF_SECONDS = 600;

	/** Seconds after which an install lock counts as left by a request that died. */
	public const LOCK_SECONDS = 60;

	private const BACKOFF_PREFIX = 'stonewright_schema_backoff_';

	/** @var array<string, true> Stores already repaired by a failed write in this request. */
	private static array $repaired = [];

	/**
	 * Installs the table when the stored version is older than $version.
	 *
	 * @param string   $key            Short store name; names the lock and the back-off.
	 * @param string   $version_option Option holding the installed version.
	 * @param int      $version        Version this build needs.
	 * @param callable $install        Creates or alters the table (dbDelta).
	 * @param callable $healthy        True when the table has everything this build reads and writes.
	 * @return bool True when the schema is current or was just installed.
	 */
	public static function maybe_install( string $key, string $version_option, int $version, callable $install, callable $healthy ): bool {
		if ( (int) get_option( $version_option, 0 ) >= $version ) {
			return true;
		}
		return self::run( $key, $version_option, $version, $install, $healthy, true );
	}

	/**
	 * Installs the table whatever the stored version says.
	 *
	 * @param string   $key            Short store name; names the lock and the back-off.
	 * @param string   $version_option Option holding the installed version.
	 * @param int      $version        Version this build needs.
	 * @param callable $install        Creates or alters the table (dbDelta).
	 * @param callable $healthy        True when the table has everything this build reads and writes.
	 * @return bool True when the table is healthy afterwards.
	 */
	public static function repair( string $key, string $version_option, int $version, callable $install, callable $healthy ): bool {
		return self::run( $key, $version_option, $version, $install, $healthy, false );
	}

	/**
	 * Runs a write; when it fails because a table or column is missing, repairs the store once per request and
	 * runs the write once more.
	 *
	 * @param string                  $key    Short store name.
	 * @param callable(): (int|false) $write  The $wpdb write; false means it failed.
	 * @param callable(): bool        $repair The store's repair_table().
	 * @return int|false What the write returned, from the retry when there was one.
	 */
	public static function write_or_repair( string $key, callable $write, callable $repair ): int|false {
		$result = $write();
		if ( false !== $result || isset( self::$repaired[ $key ] ) ) {
			return $result;
		}

		global $wpdb;
		if ( ! self::looks_like_schema_error( is_object( $wpdb ) ? (string) ( $wpdb->last_error ?? '' ) : '' ) ) {
			return $result;
		}

		self::$repaired[ $key ] = true;
		$repair();
		return $write();
	}

	/**
	 * True when a database error says the table or a column is missing, which a repair can fix.
	 */
	public static function looks_like_schema_error( string $last_error ): bool {
		return 1 === preg_match( "/unknown column|doesn't exist|no such table/i", $last_error );
	}

	/**
	 * Drops the lock and the back-off of a store (unit tests only).
	 */
	public static function reset_for_tests( string $key ): void {
		unset( self::$repaired[ $key ] );
		delete_transient( self::BACKOFF_PREFIX . $key );
		OptionLock::release( 'schema_' . $key );
	}

	private static function run( string $key, string $version_option, int $version, callable $install, callable $healthy, bool $recheck_version ): bool {
		if ( false !== get_transient( self::BACKOFF_PREFIX . $key ) ) {
			return false;
		}
		if ( ! OptionLock::acquire( 'schema_' . $key, self::LOCK_SECONDS ) ) {
			return false;
		}

		try {
			// The holder before us may have finished between our version read and our lock.
			if ( $recheck_version && (int) get_option( $version_option, 0 ) >= $version ) {
				return true;
			}

			$install();
			if ( $healthy() ) {
				update_option( $version_option, $version, true );
				delete_transient( self::BACKOFF_PREFIX . $key );
				return true;
			}

			self::fail( $key, $version, '' );
			return false;
		} catch ( Throwable $failure ) {
			self::fail( $key, $version, get_class( $failure ) );
			throw $failure;
		} finally {
			OptionLock::release( 'schema_' . $key );
		}
	}

	private static function fail( string $key, int $version, string $error_class ): void {
		set_transient( self::BACKOFF_PREFIX . $key, time(), self::BACKOFF_SECONDS );
		Logger::error(
			'schema_install_failed',
			[
				'store'          => $key,
				'target_version' => $version,
				'error_class'    => $error_class,
			]
		);
	}
}
