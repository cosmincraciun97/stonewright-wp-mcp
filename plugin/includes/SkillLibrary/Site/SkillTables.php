<?php
/**
 * Schema of the two site tables that hold skills and their earlier revisions.
 *
 * @package Stonewright
 * @license AGPL-3.0-or-later
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\SkillLibrary\Site;

/** Creates and upgrades the tables in place; rows are never dropped or emptied. */
final class SkillTables {

	public const SKILLS = 'stonewright_skills';

	public const VERSIONS = 'stonewright_skill_versions';

	public const SKILLS_OPTION = 'stonewright_skills_db_version';

	public const VERSIONS_OPTION = 'stonewright_skill_versions_db_version';

	public const SKILLS_SCHEMA = '1.3';

	public const VERSIONS_SCHEMA = '1.0';

	public static function skills_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::SKILLS;
	}

	public static function versions_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::VERSIONS;
	}

	/** Runs on every boot and touches the database only when a recorded schema version differs. */
	public static function ensure(): void {
		if ( self::SKILLS_SCHEMA === get_option( self::SKILLS_OPTION ) && self::VERSIONS_SCHEMA === get_option( self::VERSIONS_OPTION ) ) {
			return;
		}
		self::install();
	}

	/** Creates or upgrades both tables, upgrades existing rows, and records the schema versions. */
	public static function install(): void {
		$recorded = get_option( self::SKILLS_OPTION, '' );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::skills_definition() );
		dbDelta( self::versions_definition() );
		if ( is_string( $recorded ) && '' !== $recorded && version_compare( $recorded, self::SKILLS_SCHEMA, '<' ) ) {
			self::upgrade_rows();
		}
		update_option( self::SKILLS_OPTION, self::SKILLS_SCHEMA, false );
		update_option( self::VERSIONS_OPTION, self::VERSIONS_SCHEMA, false );
	}

	public static function skills_definition(): string {
		global $wpdb;
		$table   = self::skills_table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(191) NOT NULL,
			title varchar(255) NOT NULL DEFAULT '',
			description text NOT NULL,
			content mediumtext NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 1,
			enable_agentic tinyint(1) NOT NULL DEFAULT 1,
			enable_prompt tinyint(1) NOT NULL DEFAULT 1,
			source varchar(20) NOT NULL DEFAULT 'user',
			status varchar(20) NOT NULL DEFAULT 'active',
			topic varchar(191) NOT NULL DEFAULT '',
			semantic_fingerprint char(64) NOT NULL DEFAULT '',
			version_constraints_json text NOT NULL,
			verification_count int(10) unsigned NOT NULL DEFAULT 0,
			revision int(10) unsigned NOT NULL DEFAULT 1,
			conflict_json text NOT NULL,
			trashed_at datetime DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY topic_status (topic,status),
			KEY trashed_at (trashed_at),
			KEY semantic_fingerprint (semantic_fingerprint)
		) {$charset};";
	}

	public static function versions_definition(): string {
		global $wpdb;
		$table   = self::versions_table();
		$charset = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skill_id bigint(20) unsigned NOT NULL,
			revision int(10) unsigned NOT NULL,
			snapshot_json longtext NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY skill_revision (skill_id,revision),
			KEY skill_id (skill_id)
		) {$charset};";
	}

	/** Text columns that an upgrade adds to existing rows start as an empty JSON list. */
	private static function upgrade_rows(): void {
		global $wpdb;
		$table = self::skills_table();
		foreach ( [ 'version_constraints_json', 'conflict_json' ] as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and column names; the statement carries no input values.
			$wpdb->query( "UPDATE {$table} SET {$column} = '[]' WHERE {$column} = ''" );
		}
	}
}
