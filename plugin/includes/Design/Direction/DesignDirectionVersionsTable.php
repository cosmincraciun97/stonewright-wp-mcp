<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Design\Direction;

/**
 * Creates and manages the stonewright_design_direction_versions custom table.
 *
 * Stores immutable snapshots of each design direction revision, used to
 * audit and roll back a direction's contract over time.
 */
final class DesignDirectionVersionsTable {

	/** @var string Table name without prefix */
	private const TABLE = 'stonewright_design_direction_versions';

	/** @var string DB schema version option key */
	private const VERSION_OPTION = 'stonewright_design_direction_versions_db_version';

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
			direction_id bigint(20) unsigned NOT NULL,
			revision int(10) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			contract_json longtext NOT NULL,
			contract_hash char(64) NOT NULL DEFAULT '',
			source_type varchar(20) NOT NULL DEFAULT '',
			source_refs_json longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY direction_revision (direction_id, revision),
			KEY direction_id (direction_id)
		) {$charset};";
	}
}
