<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

/**
 * Creates and manages the stonewright_design_directions custom table.
 *
 * Stores persistent, versioned design direction records: a unique slug,
 * lifecycle status, the validated contract payload (and its hash), the
 * evidence source that produced it, and the current revision number.
 */
final class DesignDirectionsTable {

	/** @var string Table name without prefix */
	private const TABLE = 'stonewright_design_directions';

	/** @var string DB schema version option key */
	private const VERSION_OPTION = 'stonewright_design_directions_db_version';

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
	public static function install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql() );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	private static function schema_sql(): string {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			contract_json longtext NOT NULL,
			contract_hash char(64) NOT NULL DEFAULT '',
			source_type varchar(20) NOT NULL DEFAULT '',
			source_refs_json longtext NOT NULL,
			revision int(10) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) {$charset};";
	}
}
