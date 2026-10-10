<?php
/**
 * Removal of everything the plugin owns.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * What uninstall.php runs when the site owner asked for all plugin data to go, by defining
 * the constant STONEWRIGHT_REMOVE_ALL_DATA as true. Without it no data is removed.
 *
 * The rescue helper, the must-use plugin file that the plugin installs, is code and not data. It is
 * removed when the plugin is deleted whatever the setting says, by remove_code(), which uninstall.php
 * runs before it looks at the setting.
 *
 * The removal list is the constants below: every table the plugin creates, the name
 * prefixes of its options (this covers the OAuth signing and encryption keys) and of its
 * transients, and the events it schedules. A site is cleaned in this order: events,
 * tables, options and transients, then the change journal files in uploads/stonewright-state/.
 * On a multisite network every site is cleaned, because deleting the plugin removes it for all
 * of them. The object cache is flushed once at the end; transients that live only in an
 * external object cache expire on their own.
 *
 * WordPress includes uninstall.php without loading the plugin, so this class uses no other
 * plugin class except RescueInstaller, which uninstall.php loads first and which needs no other
 * class either, and the change journal classes, which uninstall.php loads when the data is to go.
 * UninstallerTest keeps the lists in step with the code that creates the data.
 */
final class Uninstaller {

	/** The constant that turns removal on when it is defined as true, for example in wp-config.php. */
	public const CONSTANT = 'STONEWRIGHT_REMOVE_ALL_DATA';

	/**
	 * Every table the plugin creates, without the site prefix. Tables outside this list are
	 * never touched, whatever their name.
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'stonewright_audit_log',
		'stonewright_design_direction_versions',
		'stonewright_design_directions',
		'stonewright_expertise_packs',
		'stonewright_expertise_scorecards',
		'stonewright_incidents',
		'stonewright_knowledge_candidates',
		'stonewright_memory',
		'stonewright_oauth_access_tokens',
		'stonewright_oauth_auth_codes',
		'stonewright_oauth_clients',
		'stonewright_oauth_consents',
		'stonewright_oauth_families',
		'stonewright_oauth_rate_limits',
		'stonewright_oauth_rate_metrics',
		'stonewright_oauth_refresh_tokens',
		'stonewright_skill_versions',
		'stonewright_skills',
	];

	/**
	 * Every option whose name starts with one of these is removed: settings, schema
	 * versions, locks, snapshots and the OAuth private and encryption key options.
	 *
	 * @var list<string>
	 */
	public const OPTION_PREFIXES = [ 'stonewright_' ];

	/**
	 * Every transient whose name, without WordPress's own prefix, starts with one of these
	 * is removed. sw_cc_ names the custom-code approval transients.
	 *
	 * @var list<string>
	 */
	public const TRANSIENT_PREFIXES = [ 'stonewright_', 'sw_cc_' ];

	/**
	 * Events the plugin schedules: the OAuth clean-up and the audit retention.
	 *
	 * @var list<string>
	 */
	public const SCHEDULED_HOOKS = [ 'stonewright_oauth_gc', 'stonewright_audit_retention' ];

	/** Sites read from a network per get_sites() call. */
	private const SITE_PAGE = 100;

	/** @param bool $network Whether to clean every site of a multisite network instead of the current site. */
	public function __construct( private \wpdb $db, private bool $network = false ) {}

	/** Whether the site owner turned removal on: the constant is defined and is the boolean true. */
	public static function requested(): bool {
		return defined( self::CONSTANT ) && true === constant( self::CONSTANT );
	}

	/**
	 * Remove the code the plugin installed outside its own folder: the rescue helper. It runs on every
	 * uninstall, with or without the setting that removes the data, and changes no data.
	 *
	 * @return bool Whether no helper is left.
	 */
	public static function remove_code(): bool {
		return RescueInstaller::remove();
	}

	/** The entry point of uninstall.php: remove everything when it was asked for, nothing otherwise. */
	public static function run(): void {
		global $wpdb;
		if ( self::requested() ) {
			( new self( $wpdb, is_multisite() ) )->remove_everything();
		}
	}

	/** Remove the plugin's data from the current site, or from every site of the network. */
	public function remove_everything(): void {
		if ( $this->network ) {
			$this->remove_from_every_site();
		} else {
			$this->remove_from_current_site();
		}
		wp_cache_flush();
	}

	private function remove_from_every_site(): void {
		$offset = 0;
		do {
			$sites = get_sites(
				[
					'fields'  => 'ids',
					'number'  => self::SITE_PAGE,
					'offset'  => $offset,
					'orderby' => 'id',
					'order'   => 'ASC',
				]
			);
			$sites = is_array( $sites ) ? $sites : [];
			$read = count( $sites );
			foreach ( $sites as $site ) {
				switch_to_blog( (int) $site );
				try {
					$this->remove_from_current_site();
				} finally {
					restore_current_blog();
				}
			}
			$offset += self::SITE_PAGE;
		} while ( self::SITE_PAGE === $read );
	}

	private function remove_from_current_site(): void {
		foreach ( self::SCHEDULED_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		foreach ( self::TABLES as $table ) {
			$this->db->query( 'DROP TABLE IF EXISTS `' . $this->db->prefix . $table . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- Fixed plugin table names; this is the explicit removal.
		}
		foreach ( $this->option_names() as $name ) {
			delete_option( $name );
		}
		$this->erase_state_files();
	}

	/**
	 * Deletes the change journal file of the current site, with its lock and the files that close the
	 * folder to the web (uploads/stonewright-state/). Files in that folder that the journal did not
	 * write stay, and so does the folder then. uninstall.php loads the journal classes for this.
	 */
	private function erase_state_files(): void {
		if ( class_exists( \Stonewright\WpMcp\Security\ChangeJournal::class ) ) {
			\Stonewright\WpMcp\Security\ChangeJournal::erase_state_files();
		}
	}

	/**
	 * Names of the options and transient rows of the current site that belong to the plugin.
	 *
	 * @return list<string>
	 */
	private function option_names(): array {
		$names = [];
		foreach ( $this->name_prefixes() as $prefix ) {
			$like = $this->db->esc_like( $prefix ) . '%';
			$found = $this->db->get_col( $this->db->prepare( "SELECT option_name FROM {$this->db->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name is WordPress's own; the value is prepared.
			foreach ( $found as $name ) {
				$names[ (string) $name ] = true;
			}
		}
		return array_map( 'strval', array_keys( $names ) );
	}

	/**
	 * Prefixes of the stored option names to remove: the option prefixes, and each transient
	 * prefix behind the names WordPress gives transients, their expiry rows and site transients.
	 *
	 * @return list<string>
	 */
	private function name_prefixes(): array {
		$prefixes = self::OPTION_PREFIXES;
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			foreach ( [ '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' ] as $kind ) {
				$prefixes[] = $kind . $prefix;
			}
		}
		return $prefixes;
	}
}
