<?php
/**
 * Creation and versioning of the stonewright_changes table.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ChangeLedger;

/**
 * @covers \Stonewright\WpMcp\Security\ChangeLedger
 */
final class ChangeLedgerSchemaTest extends TestCase {

	/** @var list<string> */
	private const COLUMNS = [
		'id',
		'change_id',
		'parent_id',
		'kind',
		'change_set_id',
		'ability',
		'actor',
		'client',
		'family',
		'resource_type',
		'resource_id',
		'status',
		'before_ref',
		'before_sha256',
		'before_bytes',
		'after_ref',
		'after_sha256',
		'after_bytes',
		'restorable',
		'restorable_reason',
		'summary',
		'created_at',
		'settled_at',
	];

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
		ChangeLedger::reset_schema_health_cache_for_tests();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb']                             = $this->original_wpdb;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
		ChangeLedger::reset_schema_health_cache_for_tests();
	}

	/**
	 * A wpdb whose SHOW COLUMNS answer changes once dbDelta has run (it calls get_charset_collate).
	 *
	 * @param list<string> $before Columns while the table is as it was.
	 * @param list<string> $after  Columns once dbDelta has run.
	 */
	private function wpdb( array $before, array $after ): object {
		return new class( $before, $after ) {
			public $prefix = 'wp_';
			public int $charset_calls = 0;

			/** @param list<string> $before @param list<string> $after */
			public function __construct( private array $before, private array $after ) {}

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return 'DEFAULT CHARACTER SET utf8mb4';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->charset_calls > 0 ? $this->after : $this->before;
			}
		};
	}

	public function test_the_table_name_carries_the_site_prefix(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [], [] );

		self::assertSame( 'wp_stonewright_changes', ChangeLedger::table_name() );
		self::assertSame( 'stonewright_changes', ChangeLedger::TABLE );
	}

	public function test_a_site_without_the_table_creates_it_with_dbdelta_and_records_the_version(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [], self::COLUMNS );

		ChangeLedger::maybe_install_table();

		self::assertCount( 1, $GLOBALS['stonewright_test_dbdelta_queries'] );
		$sql = $GLOBALS['stonewright_test_dbdelta_queries'][0];
		self::assertStringStartsWith( 'CREATE TABLE wp_stonewright_changes (', $sql );
		self::assertStringContainsString( 'DEFAULT CHARACTER SET utf8mb4', $sql );
		self::assertSame( ChangeLedger::SCHEMA_VERSION, (int) get_option( ChangeLedger::SCHEMA_OPTION, 0 ) );
		self::assertSame( 'stonewright_changes_schema_version', ChangeLedger::SCHEMA_OPTION );
	}

	public function test_the_create_statement_defines_every_required_column_once_with_its_keys(): void {
		$GLOBALS['wpdb'] = $this->wpdb( [], self::COLUMNS );
		ChangeLedger::maybe_install_table();
		$sql = $GLOBALS['stonewright_test_dbdelta_queries'][0];

		self::assertSame( self::COLUMNS, ChangeLedger::COLUMNS );
		foreach ( self::COLUMNS as $column ) {
			self::assertSame( 1, preg_match_all( '/^\s+' . preg_quote( $column, '/' ) . ' [A-Za-z]/m', $sql ), $column . ' is defined once' );
		}
		self::assertStringContainsString( 'PRIMARY KEY (id)', $sql );
		self::assertStringContainsString( 'UNIQUE KEY change_id_idx (change_id)', $sql );
		foreach ( [ 'parent_idx (parent_id)', 'change_set_idx (change_set_id)', 'status_idx (status)', 'created_idx (created_at)', 'actor_idx (actor)', 'ability_idx (ability)' ] as $key ) {
			self::assertStringContainsString( 'KEY ' . $key, $sql );
		}
		self::assertStringContainsString( 'KEY resource_idx (resource_type, resource_id)', $sql );
		self::assertStringContainsString( 'KEY family_idx (family, created_at)', $sql );
	}

	public function test_a_site_at_the_current_version_with_every_column_does_not_run_dbdelta(): void {
		update_option( ChangeLedger::SCHEMA_OPTION, ChangeLedger::SCHEMA_VERSION );
		$GLOBALS['wpdb'] = $this->wpdb( self::COLUMNS, self::COLUMNS );

		ChangeLedger::maybe_install_table();
		ChangeLedger::maybe_install_table();

		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( [], $GLOBALS['stonewright_test_dbdelta_queries'] );
	}

	public function test_a_site_on_an_older_schema_version_is_upgraded_in_place(): void {
		update_option( ChangeLedger::SCHEMA_OPTION, 0 );
		$GLOBALS['wpdb'] = $this->wpdb( self::COLUMNS, self::COLUMNS );

		ChangeLedger::maybe_install_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls, 'an older version goes through dbDelta, which adds what is missing and keeps the rows' );
		self::assertSame( ChangeLedger::SCHEMA_VERSION, (int) get_option( ChangeLedger::SCHEMA_OPTION, 0 ) );
	}

	public function test_a_table_that_lost_a_column_is_reported_unhealthy_and_repaired(): void {
		update_option( ChangeLedger::SCHEMA_OPTION, ChangeLedger::SCHEMA_VERSION );
		$GLOBALS['wpdb'] = $this->wpdb( array_values( array_diff( self::COLUMNS, [ 'summary' ] ) ), self::COLUMNS );

		self::assertFalse( ChangeLedger::table_schema_ok() );
		ChangeLedger::maybe_install_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertTrue( ChangeLedger::table_schema_ok() );
	}

	public function test_the_version_is_not_recorded_while_the_table_is_still_incomplete(): void {
		delete_option( ChangeLedger::SCHEMA_OPTION );
		$GLOBALS['wpdb'] = $this->wpdb( [], [ 'id', 'change_id' ] );

		ChangeLedger::maybe_install_table();

		self::assertSame( 0, (int) get_option( ChangeLedger::SCHEMA_OPTION, 0 ), 'the next request tries again' );
		self::assertFalse( ChangeLedger::table_schema_ok() );
	}

	public function test_a_healthy_check_is_remembered_for_the_rest_of_the_request(): void {
		update_option( ChangeLedger::SCHEMA_OPTION, ChangeLedger::SCHEMA_VERSION );
		$GLOBALS['wpdb'] = $this->wpdb( self::COLUMNS, self::COLUMNS );
		ChangeLedger::maybe_install_table();

		$GLOBALS['wpdb'] = $this->wpdb( [], [] );
		ChangeLedger::maybe_install_table();

		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
	}
}
