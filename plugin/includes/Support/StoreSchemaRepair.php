<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Support;

use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The once-a-day look at the four tables the plugin owns.
 *
 * A request trusts the stored schema version, so a table that lost a column behind a current version is found here
 * (or by a write that fails on it, or by an upgrade or an activation). A healthy table costs one SHOW COLUMNS and
 * nothing else; an unhealthy one is repaired, through any back-off.
 */
final class StoreSchemaRepair {

	/** @var list<class-string> */
	private const STORES = [ Memory::class, AuditLog::class, ChangeLedger::class, IncidentStore::class ];

	public static function repair_unhealthy(): void {
		global $wpdb;
		// Without a database handle that can read columns and build a CREATE TABLE there is nothing to look at.
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
			return;
		}
		foreach ( self::STORES as $store ) {
			if ( ! $store::table_schema_ok() ) {
				$store::repair_table();
			}
		}
	}
}
