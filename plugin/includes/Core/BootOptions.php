<?php
/**
 * The options Stonewright reads on every request.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Every option the plugin reads while it boots, kept in WordPress's autoloaded set.
 *
 * WordPress loads the autoloaded options in one query at start-up. An option stored without
 * autoload costs its own query on every request when the object cache is cold or absent, and so
 * does an option that does not exist (a miss is remembered for the request only, and autoload helps
 * only rows that are there). All of these hold a few bytes: a schema version, a done flag, a short
 * slug list, a timestamped notice, the locked origin and the record of a domain mismatch.
 *
 * The names are literal strings so the list reads without loading the classes; a test asserts that
 * each equals the constant of the class that owns it. The two lesson flags are not read at boot any
 * more (the migrations run on an upgrade and once a day); they stay so that a site that still has
 * them stored without autoload is converted with the rest.
 */
final class BootOptions {

	public const NAMES = [
		// Table schema versions.
		'stonewright_skills_db_version',
		'stonewright_skill_versions_db_version',
		'stonewright_design_directions_db_version',
		'stonewright_design_direction_versions_db_version',
		'stonewright_knowledge_candidates_db_version',
		'stonewright_expertise_db_version',
		'stonewright_memory_schema_version',
		'stonewright_audit_schema_version',
		'stonewright_changes_schema_version',
		'stonewright_incident_schema_version',
		// One-time migration flags (read when the migrations run, not at boot).
		'stonewright_draft_lessons_returned_v1',
		'stonewright_legacy_audit_lessons_migrated_v1',
		// Small state read when the code is loaded.
		'stonewright_dangerous_functions_notice',
		'stonewright_registered_widgets',
		'stonewright_widget_scan_cache',
		// The domain lock, checked on plugins_loaded.
		'stonewright_locked_domain',
		'stonewright_domain_mismatch',
		// The name of the change journal file, read on admin and REST requests.
		'stonewright_rescue_journal_file',
	];

	/**
	 * Boot options that do not exist on a healthy site, so their read would cost a query on every
	 * request. Each is stored with its default, an empty string that every reader takes for "none",
	 * and autoloaded; the writer that clears one stores the default instead of deleting the row.
	 */
	public const SEED = [
		'stonewright_domain_mismatch'     => '',
		'stonewright_rescue_journal_file' => '',
	];

	/**
	 * Switches every option above that is stored to autoload, and stores the seeds that are missing.
	 * An option that does not exist yet and is not a seed is left alone: its writer stores it
	 * autoloaded. The plugin needs WordPress 6.7, which has the switch; where it is missing nothing is
	 * converted.
	 */
	public static function autoload_all(): void {
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array_fill_keys( self::NAMES, true ) );
		}

		foreach ( self::SEED as $name => $default ) {
			// A no-op when the option exists.
			add_option( $name, $default, '', true );
		}
	}
}
