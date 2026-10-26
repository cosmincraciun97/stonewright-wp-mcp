<?php
/**
 * The plugin's ephemeral runtime state: what it caches, locks and remembers between requests.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Names the rows that hold no data worth keeping, and deletes them.
 *
 * Settings, tables, OAuth clients and keys, memory, skills and audit history are data. A lock row, a back-off,
 * a cached verdict, the remembered state of the rescue helper or an editor baseline is not: it is rebuilt by the
 * next request that needs it. Deactivation and every uninstall clear it, with or without STONEWRIGHT_REMOVE_ALL_DATA,
 * so a site that removes the plugin does not keep rows nobody reads.
 *
 * uninstall.php loads this file directly, without the plugin: it uses no other plugin class, and the prefixes
 * below are literals. RuntimeStateTest keeps them equal to the constants of the classes that write the rows.
 */
final class RuntimeState {

	/**
	 * Options whose name starts with one of these. The rescue helper's key and session prefixes are the helper's own
	 * constants, copied here because the helper is a separate file that loads without the plugin.
	 *
	 * @var list<string>
	 */
	public const OPTION_PREFIXES = [
		'stonewright_elementor_editor_baseline_',
		'stonewright_rescue_journal_seen',
		'stonewright_rescue_mu_state',
		'stonewright_widget_scan_cache',
		'stonewright_mcp_preflight',
		'stonewright_lock_',
		'stonewright_rescue_key_',
		'stonewright_rescue_sess_',
		'stonewright_site_pressure',
	];

	/**
	 * Transients whose name, without WordPress's own prefix, starts with one of these.
	 *
	 * @var list<string>
	 */
	public const TRANSIENT_PREFIXES = [
		'stonewright_schema_backoff_',
		'stonewright_oauth_schema_backoff',
		'stonewright_probe_loopback_failing',
		'stonewright_elementor_editor_baseline_',
		'stonewright_elementor_saves_',
	];

	/** Sites read from a network per get_sites() call. */
	private const SITE_PAGE = 100;

	/**
	 * Delete the runtime rows of the current site: options, transients and their expiry rows. Rows that live only in
	 * an external object cache expire on their own.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function clear( \wpdb $db ): int {
		$removed = self::delete_rows( $db, self::row_prefixes() );
		// The deletions above keep the cache of autoloaded options right; these two are for rows another process wrote since.
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return $removed;
	}

	/**
	 * Delete the runtime rows of the current site, or of every site when the plugin runs on a network and
	 * removal covers all of them.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function clear_everywhere( \wpdb $db, bool $network ): int {
		if ( ! $network ) {
			return self::clear( $db );
		}
		$removed = 0;
		$offset  = 0;
		do {
			$sites = get_sites( [ 'fields' => 'ids', 'number' => self::SITE_PAGE, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ] );
			$sites = is_array( $sites ) ? $sites : [];
			$read  = count( $sites );
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );
				try {
					$removed += self::clear( $db );
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::SITE_PAGE;
		} while ( self::SITE_PAGE === $read );
		return $removed;
	}

	/**
	 * Delete the rows of the current site whose option name starts with one of the prefixes. For a caller that owns
	 * a family of rows and must not depend on the lists above.
	 *
	 * @param list<string> $prefixes Stored option names, with WordPress's transient prefixes already added when they apply.
	 * @return int Number of rows deleted.
	 */
	public static function delete_rows( \wpdb $db, array $prefixes ): int {
		$removed = 0;
		foreach ( $prefixes as $prefix ) {
			$like  = $db->esc_like( $prefix ) . '%';
			$found = $db->get_col( $db->prepare( "SELECT option_name FROM {$db->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name is WordPress's own; the value is prepared.
			foreach ( is_array( $found ) ? $found : [] as $name ) {
				if ( delete_option( (string) $name ) ) {
					++$removed;
				}
			}
		}
		return $removed;
	}

	/**
	 * The stored name prefixes to search: the option prefixes, and each transient prefix behind the names WordPress gives
	 * transients, their expiry rows and site transients.
	 *
	 * @return list<string>
	 */
	public static function row_prefixes(): array {
		$prefixes = self::OPTION_PREFIXES;
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			foreach ( [ '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ] as $kind ) {
				$prefixes[] = $kind . $prefix;
			}
		}
		return $prefixes;
	}
}
