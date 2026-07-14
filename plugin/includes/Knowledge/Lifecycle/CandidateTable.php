<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Knowledge\Lifecycle;

/** Creates the versioned site-local knowledge candidate table. */
final class CandidateTable {

	private const TABLE = 'stonewright_knowledge_candidates';

	private const VERSION_OPTION = 'stonewright_knowledge_candidates_db_version';

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
			topic varchar(191) NOT NULL,
			widget varchar(191) NOT NULL DEFAULT '',
			control_key varchar(191) NOT NULL DEFAULT '',
			fact text NOT NULL,
			recipe mediumtext NOT NULL,
			source_url text NOT NULL,
			source_hash char(64) NOT NULL,
			fetched_at datetime NOT NULL,
			version_constraints_json text NOT NULL,
			evidence_type varchar(32) NOT NULL,
			confidence decimal(5,4) NOT NULL DEFAULT 0.0000,
			verification_task_ids_json text NOT NULL,
			verified_fingerprints_json text NOT NULL,
			verification_count int(10) unsigned NOT NULL DEFAULT 0,
			failure_count int(10) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'candidate',
			semantic_fingerprint char(64) NOT NULL,
			skill_slug varchar(191) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY semantic_fingerprint (semantic_fingerprint),
			KEY topic_status (topic, status),
			KEY expires_at (expires_at)
		) {$charset};";
	}

	private static function run_delta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql() );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}
}
