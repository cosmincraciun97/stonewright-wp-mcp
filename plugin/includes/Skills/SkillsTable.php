<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/**
 * Creates and manages the stonewright_skills custom table.
 *
 * @stonewright-status stable
 */
final class SkillsTable {

	/** @var string Table name without prefix */
	private const TABLE = 'stonewright_skills';

	/** @var string DB schema version option key */
	private const VERSION_OPTION = 'stonewright_skills_db_version';

	/** @var string Current schema version */
	private const SCHEMA_VERSION = '1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Install or upgrade the table using dbDelta.
	 * Safe to call on every request (idempotent via version option).
	 */
	public static function create_table(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::run_delta();
	}

	/**
	 * Force a re-install (bypasses version check).
	 */
	public static function force_create_table(): void {
		self::run_delta();
	}

	private static function run_delta(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			description text NOT NULL DEFAULT '',
			content mediumtext NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			source varchar(20) NOT NULL DEFAULT 'user',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Drop the table entirely. Used in uninstall scripts.
	 */
	public static function drop_table(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
		delete_option( self::VERSION_OPTION );
	}
}
