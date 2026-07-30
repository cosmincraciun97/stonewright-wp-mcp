<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Skills;

/** Stores immutable snapshots used to audit and roll back site skills. */
final class SkillVersionsTable {

	private const TABLE = 'stonewright_skill_versions';

	private const VERSION_OPTION = 'stonewright_skill_versions_db_version';

	private const SCHEMA_VERSION = '1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::run_delta();
	}

	public static function force_create_table(): void {
		self::run_delta();
	}

	public static function schema_sql(): string {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skill_id bigint(20) unsigned NOT NULL,
			revision int(10) unsigned NOT NULL,
			snapshot_json longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY skill_revision (skill_id, revision),
			KEY skill_id (skill_id)
		) {$charset};";
	}

	private static function run_delta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql() );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}
}
