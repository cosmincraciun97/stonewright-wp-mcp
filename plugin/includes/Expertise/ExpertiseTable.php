<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Expertise;

/** Stores versioned site overrides for immutable bundled expertise packs. */
final class ExpertiseTable {

	private const VERSION_OPTION = 'stonewright_expertise_db_version';
	private const SCHEMA_VERSION = '1.0';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'stonewright_expertise_packs';
	}

	public static function scorecard_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'stonewright_expertise_scorecards';
	}

	public static function create_tables(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::run_delta();
	}

	public static function force_create_tables(): void {
		self::run_delta();
	}

	/** @return list<string> */
	public static function schema_sql(): array {
		global $wpdb;
		$packs      = self::table_name();
		$scorecards = self::scorecard_table_name();
		$charset    = $wpdb->get_charset_collate();
		return [
			"CREATE TABLE {$packs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pack_id varchar(191) NOT NULL,
				pack_version varchar(32) NOT NULL,
				status varchar(20) NOT NULL,
				pack_hash char(64) NOT NULL,
				pack_json longtext NOT NULL,
				source varchar(20) NOT NULL DEFAULT 'site',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY pack_id (pack_id),
				KEY status (status)
			) {$charset};",
			"CREATE TABLE {$scorecards} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				pack_id varchar(191) NOT NULL,
				pack_hash char(64) NOT NULL,
				runtime_fingerprint char(64) NOT NULL,
				score decimal(5,2) NOT NULL DEFAULT 0.00,
				critical_failures int(10) unsigned NOT NULL DEFAULT 0,
				metrics_json longtext NOT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY pack_runtime (pack_id, runtime_fingerprint),
				KEY score (score)
			) {$charset};",
		];
	}

	private static function run_delta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( self::schema_sql() as $sql ) {
			dbDelta( $sql );
		}
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}
}
