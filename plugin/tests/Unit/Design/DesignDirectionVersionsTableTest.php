<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;

/**
 * Unit tests for the DesignDirectionVersionsTable schema.
 *
 * These tests mock the wpdb global and read the private schema_sql() via
 * reflection so no real DB is required.
 *
 * @covers \Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable
 */
final class DesignDirectionVersionsTableTest extends TestCase {

	/** @var mixed Saved $wpdb reference restored in tearDown. */
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_table_name_includes_prefix(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();
		$this->assertSame( 'wp_stonewright_design_direction_versions', DesignDirectionVersionsTable::table_name() );
	}

	public function test_schema_contains_direction_revision_unique_key(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();
		$sql              = $this->schema_sql();

		$this->assertStringContainsString( 'direction_id bigint(20) unsigned NOT NULL', $sql );
		$this->assertStringContainsString( 'revision int(10) unsigned NOT NULL', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY direction_revision (direction_id, revision)', $sql );
	}

	public function test_schema_stores_complete_immutable_snapshot(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();
		$sql              = $this->schema_sql();

		// The version row mirrors the full directions row payload so each
		// revision is a standalone, complete snapshot.
		$this->assertStringContainsString( 'status varchar(20) NOT NULL', $sql );
		$this->assertStringContainsString( 'contract_json longtext NOT NULL', $sql );
		$this->assertStringContainsString( 'contract_hash char(64) NOT NULL', $sql );
		$this->assertStringContainsString( 'source_type varchar(20) NOT NULL', $sql );
		$this->assertStringContainsString( 'source_refs_json longtext NOT NULL', $sql );
	}

	public function test_schema_contains_utc_created_at(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();
		$sql              = $this->schema_sql();

		$this->assertStringContainsString( 'created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP', $sql );
	}

	public function test_install_is_idempotent_via_version_option(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();

		DesignDirectionVersionsTable::install();
		$version_after_first = get_option( 'stonewright_design_direction_versions_db_version' );

		DesignDirectionVersionsTable::install();
		$version_after_second = get_option( 'stonewright_design_direction_versions_db_version' );

		$this->assertNotFalse( $version_after_first );
		$this->assertSame( $version_after_first, $version_after_second );
	}

	/**
	 * Invokes the private schema_sql() method via reflection. install()/table_name()
	 * are the only public methods on this class by design.
	 */
	private function schema_sql(): string {
		$method = new ReflectionMethod( DesignDirectionVersionsTable::class, 'schema_sql' );
		return (string) $method->invoke( null );
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};
	}
}
