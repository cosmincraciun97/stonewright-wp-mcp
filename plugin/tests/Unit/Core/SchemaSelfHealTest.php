<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Core\PluginRegistration;
use Stonewright\WpMcp\Memory\Memory;
use Stonewright\WpMcp\Security\AuditLog;
use Stonewright\WpMcp\Security\ChangeLedger;
use Stonewright\WpMcp\Security\IncidentStore;
use Stonewright\WpMcp\Support\SchemaGate;
use Stonewright\WpMcp\Support\StoreSchemaRepair;

/**
 * A table that lost a column repairs itself on a plugin upgrade and on the daily job, and a back-off left by an
 * earlier failure does not stop either.
 *
 * @covers \Stonewright\WpMcp\Support\StoreSchemaRepair
 * @covers \Stonewright\WpMcp\Core\PluginRegistration::maybe_upgrade
 * @covers \Stonewright\WpMcp\Security\AuditLog::run_scheduled_retention
 */
final class SchemaSelfHealTest extends TestCase {

	/** Store key => [ class, table ]. */
	private const STORES = [
		'memory'   => [ Memory::class, 'wptests_stonewright_memory' ],
		'audit'    => [ AuditLog::class, 'wptests_stonewright_audit_log' ],
		'changes'  => [ ChangeLedger::class, 'wptests_stonewright_changes' ],
		'incident' => [ IncidentStore::class, 'wptests_stonewright_incidents' ],
	];

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->reset();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$this->reset();
	}

	private function reset(): void {
		$GLOBALS['stonewright_test_options']         = [ 'active_plugins' => [] ];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
		foreach ( array_keys( self::STORES ) as $key ) {
			SchemaGate::reset_for_tests( $key );
		}
	}

	public function test_a_plugin_upgrade_repairs_all_four_tables(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( array_keys( self::STORES ) );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-upgrade';

		PluginRegistration::maybe_upgrade();

		self::assertEqualsCanonicalizing( array_column( self::STORES, 1 ), $this->installed_tables() );
	}

	public function test_a_plugin_upgrade_repairs_even_when_an_earlier_attempt_left_a_backoff(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( array_keys( self::STORES ) );
		$GLOBALS['stonewright_test_options']['stonewright_version'] = '0.0.0-before-upgrade';
		foreach ( array_keys( self::STORES ) as $key ) {
			set_transient( 'stonewright_schema_backoff_' . $key, 1, SchemaGate::BACKOFF_SECONDS );
		}

		PluginRegistration::maybe_upgrade();

		self::assertEqualsCanonicalizing( array_column( self::STORES, 1 ), $this->installed_tables() );
		self::assertSame( [], $this->backoffs(), 'The repairs succeeded, so the back-offs are cleared.' );
	}

	public function test_the_daily_job_repairs_only_the_tables_that_are_unhealthy(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( [ 'audit', 'incident' ] );

		AuditLog::run_scheduled_retention();

		self::assertEqualsCanonicalizing( [ 'wptests_stonewright_audit_log', 'wptests_stonewright_incidents' ], $this->installed_tables() );
	}

	public function test_the_daily_job_installs_nothing_when_every_table_is_healthy(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( [] );

		StoreSchemaRepair::repair_unhealthy();

		self::assertSame( [], $this->installed_tables() );
		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 4, $GLOBALS['wpdb']->show_columns, 'One cheap check per table.' );
	}

	public function test_the_daily_job_leaves_a_database_handle_that_cannot_read_columns_alone(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wptests_';
		};

		StoreSchemaRepair::repair_unhealthy();

		self::assertSame( [], $this->installed_tables() );
	}

	public function test_the_daily_job_repairs_through_a_backoff(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( [ 'memory' ] );
		set_transient( 'stonewright_schema_backoff_memory', 1, SchemaGate::BACKOFF_SECONDS );

		StoreSchemaRepair::repair_unhealthy();

		self::assertSame( [ 'wptests_stonewright_memory' ], $this->installed_tables() );
	}

	/**
	 * @return list<string> Tables dbDelta was asked to create.
	 */
	private function installed_tables(): array {
		$tables = [];
		foreach ( $GLOBALS['stonewright_test_dbdelta_queries'] as $sql ) {
			if ( 1 === preg_match( '/CREATE TABLE (wptests_stonewright_(?:memory|audit_log|changes|incidents)) \(/', (string) $sql, $match ) ) {
				$tables[] = $match[1];
			}
		}
		return $tables;
	}

	/**
	 * @return list<string> Back-off transients still set.
	 */
	private function backoffs(): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['stonewright_test_transients'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'stonewright_schema_backoff_' )
			)
		);
	}

	/**
	 * A wpdb whose SHOW COLUMNS lacks a column for each broken store until dbDelta has been asked to create that
	 * store's table.
	 *
	 * @param list<string> $broken Store keys whose table lacks a column.
	 */
	private function fake_wpdb( array $broken ): object {
		$tables = [];
		foreach ( self::STORES as $key => [ $class, $table ] ) {
			$columns          = ChangeLedger::class === $class ? ChangeLedger::COLUMNS : ( new ReflectionMethod( $class, 'required_columns' ) )->invoke( null );
			$tables[ $table ] = [
				'full'      => $columns,
				'short'     => array_slice( $columns, 1 ),
				'is_broken' => in_array( $key, $broken, true ),
			];
		}

		return new class( $tables, $this->original_wpdb ) {
			public $prefix            = 'wptests_';
			public int $charset_calls = 0;
			public int $show_columns  = 0;

			/** @param array<string, array{full: list<string>, short: list<string>, is_broken: bool}> $tables */
			public function __construct( private array $tables, private object $inner ) {}

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return '';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				++$this->show_columns;
				$created = implode( "
", $GLOBALS['stonewright_test_dbdelta_queries'] ?? [] );
				foreach ( $this->tables as $table => $spec ) {
					if ( str_ends_with( $query, ' ' . $table ) ) {
						return $spec['is_broken'] && ! str_contains( $created, 'CREATE TABLE ' . $table . ' (' ) ? $spec['short'] : $spec['full'];
					}
				}
				return [];
			}

			/** Everything else (the other tables the upgrade touches) goes to the ordinary test wpdb. */
			public function __call( string $name, array $arguments ): mixed {
				return $this->inner->$name( ...$arguments );
			}

			public function __get( string $name ): mixed {
				return $this->inner->$name;
			}
		};
	}
}
