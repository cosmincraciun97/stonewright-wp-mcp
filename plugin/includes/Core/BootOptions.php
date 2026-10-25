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
 * autoload costs its own query on every request when the object cache is cold or absent, so a
 * handful of small version flags and lists cost a handful of round trips per page. All of these
 * hold a few bytes: a schema version, a done flag, a short slug list, a timestamped notice.
 *
 * The names are literal strings so the list reads without loading the classes; a test asserts that
 * each equals the constant of the class that owns it.
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
		// One-time migration flags.
		'stonewright_draft_lessons_returned_v1',
		'stonewright_legacy_audit_lessons_migrated_v1',
		// Small state read when the code is loaded.
		'stonewright_dangerous_functions_notice',
		'stonewright_registered_widgets',
		'stonewright_widget_scan_cache',
	];

	/**
	 * Switches every option above that is stored to autoload. An option that does not exist yet is
	 * left alone: its writer stores it autoloaded.
	 */
	public static function autoload_all(): void {
		if ( function_exists( 'wp_set_option_autoload_values' ) ) {
			wp_set_option_autoload_values( array_fill_keys( self::NAMES, true ) );
			return;
		}

		// WordPress before 6.4 has no switch: store each existing option again, autoloaded.
		$missing = new \stdClass();
		foreach ( self::NAMES as $name ) {
			$value = get_option( $name, $missing );
			if ( $value === $missing ) {
				continue;
			}
			delete_option( $name );
			add_option( $name, $value, '', true );
		}
	}
}
