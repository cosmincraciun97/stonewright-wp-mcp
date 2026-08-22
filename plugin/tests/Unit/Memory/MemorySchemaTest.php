<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Memory;

/**
 * @covers \Stonewright\WpMcp\Memory\Memory
 */
final class MemorySchemaTest extends TestCase {

	private mixed $original_wpdb;

	/** @var list<string> */
	private const V4_COLUMNS = [
		'id',
		'scope',
		'type',
		'name',
		'memory_key',
		'value_json',
		'confidence',
		'topic',
		'version_fingerprint',
		'expires_at',
		'status',
		'precedence',
		'created_by',
		'created_at',
		'updated_at',
		'last_retrieved_at',
	];

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
		Memory::reset_schema_health_cache_for_tests();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_schema_ok_false_when_table_missing(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( [] );

		self::assertFalse( Memory::table_schema_ok() );
	}

	public function test_schema_ok_false_when_columns_incomplete(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( [ 'id', 'scope', 'memory_key', 'value_json' ] );

		self::assertFalse( Memory::table_schema_ok() );
	}

	public function test_schema_ok_true_when_all_v4_columns_present(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( self::V4_COLUMNS );

		self::assertTrue( Memory::table_schema_ok() );
	}

	public function test_schema_version_not_bumped_when_columns_missing(): void {
		delete_option( 'stonewright_memory_schema_version' );
		$GLOBALS['wpdb'] = $this->make_wpdb( [] );

		Memory::maybe_install_table();

		self::assertFalse( Memory::table_schema_ok() );
		self::assertSame( 0, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	public function test_schema_ok_after_install_bumps_version_to_4(): void {
		delete_option( 'stonewright_memory_schema_version' );
		// Simulate successful dbDelta: columns present after install.
		$GLOBALS['wpdb'] = $this->make_wpdb( self::V4_COLUMNS );

		Memory::maybe_install_table();

		self::assertTrue( Memory::table_schema_ok() );
		self::assertSame( 4, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	public function test_list_active_for_matching_includes_high_precedence_beyond_newest_fifty(): void {
		$old = $this->matching_row( 1, 'user', 'elementor', 'keep-native-widgets', 'Keep native widgets', 900 );
		$rows = [ $old ];
		for ( $id = 2; $id <= 60; $id++ ) {
			$rows[] = $this->matching_row( $id, 'generic', 'other', 'filler-' . $id, 'Filler ' . $id, 0 );
		}
		$GLOBALS['wpdb'] = $this->make_matching_wpdb( $rows );

		$newest_fifty = Memory::list_all( 50, 0 );
		self::assertCount( 50, $newest_fifty );
		self::assertNotContains( 1, array_column( $newest_fifty, 'id' ) );

		$matched = Memory::list_active_for_matching( 'elementor', 500 );
		self::assertLessThanOrEqual( 500, count( $matched ) );
		self::assertContains( 1, array_column( $matched, 'id' ) );
		self::assertSame( 1, (int) $matched[0]['id'] );
		self::assertSame( 'active', $matched[0]['status'] );
	}

	public function test_put_typed_failure_is_logged_and_returns_zero(): void {
		$log_file = tempnam( sys_get_temp_dir(), 'sw-mem-log-' );
		self::assertNotFalse( $log_file );
		$previous_log = ini_get( 'error_log' );
		ini_set( 'error_log', $log_file );

		$GLOBALS['wpdb'] = new class() {
			public string $prefix     = 'wp_';
			public string $last_error = 'Table does not exist';
			public int $insert_id    = 0;

			public function get_var( string $query ): mixed {
				return null;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $data
			 * @return false Always fails (broken table fixture).
			 */
			public function insert( string $table, array $data, array $format = [] ): bool {
				return false;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 * @return false Always fails (broken table fixture).
			 */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): bool {
				return false;
			}
		};

		$id = Memory::put_typed( 'feedback', 'audit', 'learning-test', 'Test', [ 'x' => 1 ] );

		ini_set( 'error_log', (string) $previous_log );
		$log = (string) file_get_contents( $log_file );
		@unlink( $log_file );

		self::assertSame( 0, $id );
		self::assertStringContainsString( 'memory_put_failed', $log );
		self::assertStringContainsString( 'learning-test', $log );
		self::assertStringContainsString( 'Table does not exist', $log );
	}

	public function test_put_typed_blocks_credential_material_before_database_write(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( self::V4_COLUMNS );
		$credential      = implode( '-', [ 'real', 'private', 'value' ] );

		$id = Memory::put_typed(
			'user',
			'project',
			'unsafe-secret',
			'Unsafe secret',
			[ 'application_password' => $credential ]
		);

		self::assertSame( 0, $id );
	}

	public function test_maybe_install_skips_dbdelta_when_version_and_schema_ok(): void {
		update_option( 'stonewright_memory_schema_version', 4 );
		$GLOBALS['wpdb'] = new class( self::V4_COLUMNS ) {
			public string $prefix = 'wp_';
			public int $charset_calls = 0;
			/** @var array<int, string> */
			private array $columns;

			/** @param array<int, string> $columns */
			public function __construct( array $columns ) {
				$this->columns = $columns;
			}

			public function get_charset_collate(): string {
				++$this->charset_calls;
				return '';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->columns;
			}
		};

		Memory::maybe_install_table();

		// Healthy schema: verify columns, do not re-run dbDelta path.
		self::assertSame( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertSame( 4, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	public function test_maybe_install_repairs_when_version_current_but_columns_missing(): void {
		update_option( 'stonewright_memory_schema_version', 4 );
		// Incomplete columns: must attempt reinstall (charset/dbDelta path).
		$GLOBALS['wpdb'] = new class() {
			public string $prefix       = 'wp_';
			public int $charset_calls   = 0;
			/** @var array<int, string> */
			public array $columns       = [ 'id', 'scope', 'memory_key' ];

			public function get_charset_collate(): string {
				++$this->charset_calls;
				// After "dbDelta" simulate columns becoming complete.
				$this->columns = [
					'id',
					'scope',
					'type',
					'name',
					'memory_key',
					'value_json',
					'confidence',
					'topic',
					'version_fingerprint',
					'expires_at',
					'status',
					'precedence',
					'created_by',
					'created_at',
					'updated_at',
					'last_retrieved_at',
				];
				return 'DEFAULT CHARSET=utf8mb4';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->columns;
			}
		};

		Memory::maybe_install_table();

		self::assertGreaterThan( 0, $GLOBALS['wpdb']->charset_calls );
		self::assertTrue( Memory::table_schema_ok() );
		self::assertSame( 4, (int) get_option( 'stonewright_memory_schema_version', 0 ) );
	}

	/**
	 * @param array<int, string> $columns
	 */
	private function make_wpdb( array $columns ): object {
		return new class( $columns ) {
			public string $prefix = 'wp_';
			/** @var array<int, string> */
			private array $columns;

			/** @param array<int, string> $columns */
			public function __construct( array $columns ) {
				$this->columns = $columns;
			}

			public function get_charset_collate(): string {
				return 'DEFAULT CHARSET=utf8mb4';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->columns;
			}
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function matching_row( int $id, string $type, string $scope, string $key, string $name, int $precedence ): array {
		return [
			'id'                  => $id,
			'type'                => $type,
			'scope'               => $scope,
			'memory_key'          => $key,
			'name'                => $name,
			'value_json'          => wp_json_encode( $name ),
			'confidence'          => '1.0000',
			'topic'               => $name,
			'version_fingerprint' => '',
			'expires_at'          => '',
			'status'              => 'active',
			'precedence'          => $precedence,
			'created_by'          => 1,
			'created_at'          => '2026-01-01 00:00:00',
			'updated_at'          => '2026-01-01 00:00:00',
			'last_retrieved_at'   => '',
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_matching_wpdb( array $rows ): object {
		return new class( $rows ) {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $rows;
			/** @var array<int, mixed> */
			public array $last_prepare_args = [];

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_prepare_args = $args;
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				$rows = $this->rows;
				if ( str_contains( $query, 'status' ) ) {
					$status = 'active';
					foreach ( $this->last_prepare_args as $arg ) {
						if ( is_string( $arg ) && in_array( $arg, [ 'active', 'draft', 'stale', 'rejected' ], true ) ) {
							$status = $arg;
							break;
						}
					}
					$rows = array_values(
						array_filter(
							$rows,
							static fn( array $row ): bool => ( $row['status'] ?? 'active' ) === $status
						)
					);
				}
				if ( str_contains( $query, 'ORDER BY precedence' ) ) {
					usort(
						$rows,
						static fn( array $a, array $b ): int => ( (int) ( $b['precedence'] ?? 0 ) <=> (int) ( $a['precedence'] ?? 0 ) )
							?: ( (int) $b['id'] <=> (int) $a['id'] )
					);
				} else {
					usort(
						$rows,
						static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id']
					);
				}
				$ints = [];
				foreach ( $this->last_prepare_args as $arg ) {
					if ( is_int( $arg ) || ( is_numeric( $arg ) && (string) (int) $arg === (string) $arg ) ) {
						$ints[] = (int) $arg;
					}
				}
				$limit  = $ints[0] ?? 100;
				$offset = $ints[1] ?? 0;
				return array_slice( $rows, $offset, $limit );
			}
		};
	}
}
