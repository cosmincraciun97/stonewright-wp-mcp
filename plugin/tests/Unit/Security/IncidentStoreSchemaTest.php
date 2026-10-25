<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Stonewright\WpMcp\Security\IncidentStore;

/**
 * The incident table is installed from the version option, not from a look at its columns on every request.
 *
 * @covers \Stonewright\WpMcp\Security\IncidentStore
 */
final class IncidentStoreSchemaTest extends TestCase {

	private const VERSION_OPTION = 'stonewright_incident_schema_version';

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
		IncidentStore::reset_schema_health_cache_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
		$GLOBALS['stonewright_test_dbdelta_queries'] = [];
		IncidentStore::reset_schema_health_cache_for_tests();
	}

	public function test_init_with_current_version_issues_no_show_columns(): void {
		update_option( self::VERSION_OPTION, 2 );
		$GLOBALS['wpdb'] = $this->fake_wpdb( [] );

		IncidentStore::maybe_install_table();
		IncidentStore::maybe_install_table();

		self::assertSame( [], $GLOBALS['wpdb']->col_queries );
		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
	}

	public function test_a_missing_version_installs_once_and_records_it(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( [] );
		$GLOBALS['wpdb']->healthy_after_install = true;

		IncidentStore::maybe_install_table();
		IncidentStore::maybe_install_table();

		self::assertSame( 1, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 2, (int) get_option( self::VERSION_OPTION, 0 ) );
	}

	public function test_repair_table_installs_even_with_a_current_version(): void {
		update_option( self::VERSION_OPTION, 2 );
		$GLOBALS['wpdb'] = $this->fake_wpdb( [] );
		$GLOBALS['wpdb']->healthy_after_install = true;

		self::assertTrue( IncidentStore::repair_table() );

		self::assertSame( 1, $GLOBALS['wpdb']->charset_calls );
	}

	public function test_a_schema_error_on_insert_triggers_one_repair_and_one_retry(): void {
		$GLOBALS['wpdb'] = $this->fake_wpdb( [ "Unknown column 'repair_phase' in 'field list'" ] );
		$GLOBALS['wpdb']->healthy_after_install = true;

		$persist = new ReflectionMethod( IncidentStore::class, 'persist' );
		$persist->invoke( null, [ 'incident_id' => str_repeat( 'a', 64 ) ], false );

		self::assertSame( 2, $GLOBALS['wpdb']->inserts );
		self::assertSame( 1, $GLOBALS['wpdb']->charset_calls );
	}

	/**
	 * @param list<string> $insert_errors Schema errors the next inserts fail with, one each.
	 */
	private function fake_wpdb( array $insert_errors ): object {
		return new #[\AllowDynamicProperties] class( $insert_errors ) extends \wpdb {
			public int $charset_calls = 0;
			public int $inserts       = 0;
			public bool $healthy_after_install = false;
			/** @var list<string> */
			public array $col_queries = [];
			/** @var list<string> */
			private array $errors;

			/** @param list<string> $errors */
			public function __construct( array $errors ) {
				$this->errors = $errors;
				$this->prefix = 'wp_';
			}

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return '';
			}

			/** @return list<string> */
			public function get_col( string $query, int $x = 0 ): array {
				$this->col_queries[] = $query;
				return $this->healthy_after_install && $this->charset_calls > 0 ? $this->columns() : [];
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<int, string>   $format
			 */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				++$this->inserts;
				$error = array_shift( $this->errors );
				if ( null !== $error ) {
					$this->last_error = $error;
					return false;
				}
				$this->last_error = '';
				return 1;
			}

			/** @return list<string> */
			private function columns(): array {
				$method = new ReflectionMethod( IncidentStore::class, 'required_columns' );
				return $method->invoke( null );
			}
		};
	}
}
