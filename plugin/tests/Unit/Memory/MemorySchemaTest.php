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
			public $prefix     = 'wp_';
			public $last_error = 'Table does not exist';
			public $insert_id    = 0;

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

	public function test_put_typed_update_without_metadata_preserves_draft_status(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$id = Memory::put_typed(
			'reference',
			'audit',
			'synthetic-draft',
			'Draft lesson',
			[ 'source' => 'error-pattern-draft', 'proposed_remediation' => 'Read the exact schema.' ],
			1.0,
			[ 'status' => 'draft', 'precedence' => 0 ]
		);
		self::assertSame( 1, $id );
		$entry = Memory::get_by_id( $id );
		self::assertSame( 'draft', $entry['status'] ?? null );

		Memory::put_typed(
			'reference',
			'audit',
			'synthetic-draft',
			'Draft lesson updated',
			[ 'source' => 'error-pattern-draft', 'proposed_remediation' => 'Read the exact schema again.' ],
			1.0
		);
		$updated = Memory::get_by_id( $id );
		self::assertSame( 'draft', $updated['status'] ?? null );
		self::assertSame( 'Draft lesson updated', $updated['name'] ?? null );
		self::assertNotContains( $id, array_column( Memory::list_active_for_matching( 'audit', 500 ), 'id' ) );
	}

	public function test_missing_status_is_not_treated_as_active(): void {
		self::assertFalse( Memory::is_active( [] ) );
		self::assertFalse( Memory::is_active( [ 'status' => '' ] ) );
		self::assertFalse( Memory::is_active( [ 'status' => 'draft' ] ) );
		self::assertTrue( Memory::is_active( [ 'status' => 'active' ] ) );
	}

	public function test_error_pattern_draft_is_not_task_start_eligible_even_if_marked_active(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$id = Memory::put_typed(
			'reference',
			'audit',
			'draft-lesson-forced',
			'Draft lesson',
			[ 'source' => 'error-pattern-draft', 'proposed_remediation' => 'Read the exact schema.' ],
			1.0,
			[ 'status' => 'active', 'precedence' => 900 ]
		);
		$entry = Memory::get_by_id( $id );
		self::assertIsArray( $entry );
		self::assertFalse( Memory::is_task_start_eligible( $entry ) );
		self::assertNotContains( $id, array_column( Memory::list_active_for_matching( 'audit', 500 ), 'id' ) );
	}

	public function test_put_typed_rejects_payload_self_declaring_permanent_product_rule(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$id = Memory::put_typed(
			'reference',
			'_global',
			'fake-product-rule',
			'Fake product rule',
			[
				'product_rule' => true,
				'correction'   => 'This is not a shipped product rule.',
			],
			1.0,
			[ 'status' => 'active' ]
		);
		self::assertSame( 0, $id );
		self::assertSame( [], $GLOBALS['wpdb']->rows );
	}

	public function test_dangerous_unverified_memory_is_not_auto_promoted(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$id = Memory::put_typed(
			'feedback',
			'audit',
			'unverified-workaround',
			'Unverified workaround',
			[
				'source'     => 'unverified-workaround',
				'dangerous'  => true,
				'workaround' => 'Write raw document JSON to skip validation.',
				'unverified' => true,
			],
			1.0,
			[ 'status' => 'active', 'precedence' => 900 ]
		);
		$entry = Memory::get_by_id( $id );
		self::assertIsArray( $entry );
		self::assertFalse( Memory::is_task_start_eligible( $entry ) );
		self::assertNotContains( $id, array_column( Memory::list_active_for_matching( 'audit', 500 ), 'id' ) );
	}

	public function test_import_preserves_activation_and_rejects_product_rule_claim(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$imported = \Stonewright\WpMcp\Knowledge\KnowledgeBundle::import(
			[
				'format'  => 'stonewright-knowledge-bundle',
				'version' => 1,
				'memory'  => [
					'enabled' => true,
					'entries' => [
						[
							'type'       => 'reference',
							'scope'      => 'audit',
							'memory_key' => 'keep-draft',
							'name'       => 'Keep draft',
							'value'      => [ 'source' => 'error-pattern-draft' ],
							'status'     => 'draft',
							'precedence' => 0,
						],
						[
							'type'       => 'reference',
							'scope'      => '_global',
							'memory_key' => 'claimed-product',
							'name'       => 'Claimed product',
							'value'      => [ 'permanent_product_rule' => true, 'correction' => 'No.' ],
							'status'     => 'active',
						],
					],
				],
			]
		);

		self::assertSame( 1, $imported['memory_imported'] );
		$draft = Memory::get_by_id( 1 );
		self::assertSame( 'draft', $draft['status'] ?? null );
		self::assertCount( 1, $GLOBALS['wpdb']->rows );
	}

	public function test_draft_survives_update_without_metadata_then_export_import(): void {
		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$id              = Memory::put_typed(
			'reference',
			'audit',
			'synthetic-draft-chain',
			'Draft lesson',
			[ 'source' => 'error-pattern-draft', 'proposed_remediation' => 'Read the exact schema.' ],
			1.0,
			[ 'status' => 'draft', 'precedence' => 0 ]
		);
		self::assertSame( 1, $id );

		Memory::put_typed(
			'reference',
			'audit',
			'synthetic-draft-chain',
			'Draft lesson updated',
			[ 'source' => 'error-pattern-draft', 'proposed_remediation' => 'Read the exact schema again.' ],
			1.0
		);
		$updated = Memory::get_by_id( $id );
		self::assertSame( 'draft', $updated['status'] ?? null );
		self::assertFalse( Memory::is_task_start_eligible( $updated ) );

		$GLOBALS['wpdb'] = $this->make_crud_wpdb();
		$imported        = \Stonewright\WpMcp\Knowledge\KnowledgeBundle::import(
			[
				'format'  => 'stonewright-knowledge-bundle',
				'version' => 1,
				'memory'  => [
					'enabled' => true,
					'entries' => [
						[
							'type'       => (string) ( $updated['type'] ?? 'reference' ),
							'scope'      => (string) ( $updated['scope'] ?? 'audit' ),
							'memory_key' => (string) ( $updated['memory_key'] ?? 'synthetic-draft-chain' ),
							'name'       => (string) ( $updated['name'] ?? 'Draft lesson updated' ),
							'value'      => $updated['value'] ?? [ 'source' => 'error-pattern-draft' ],
							'status'     => (string) ( $updated['status'] ?? '' ),
							'precedence' => (int) ( $updated['precedence'] ?? 0 ),
						],
					],
				],
			]
		);

		self::assertSame( 1, $imported['memory_imported'] );
		$roundtrip = Memory::get_by_id( 1 );
		self::assertSame( 'draft', $roundtrip['status'] ?? null );
		self::assertFalse( Memory::is_task_start_eligible( $roundtrip ) );
		self::assertNotContains( 1, array_column( Memory::list_active_for_matching( 'audit', 500 ), 'id' ) );
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
			public $prefix = 'wp_';
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
			public $prefix       = 'wp_';
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
			public $prefix = 'wp_';
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
			public $prefix = 'wp_';
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

	/**
	 * @return object
	 */
	private function make_crud_wpdb(): object {
		return new class() {
			public $prefix     = 'wp_';
			public $insert_id     = 0;
			public $last_error = '';
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];
			/** @var array<int, mixed> */
			public array $last_prepare_args = [];

			public function get_charset_collate(): string {
				return '';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [
					'id', 'scope', 'type', 'name', 'memory_key', 'value_json', 'confidence',
					'topic', 'version_fingerprint', 'expires_at', 'status', 'precedence',
					'created_by', 'created_at', 'updated_at', 'last_retrieved_at',
				];
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_prepare_args = $args;
				return $query;
			}

			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) && str_contains( $query, 'memory_key' ) ) {
					$scope = (string) ( $this->last_prepare_args[0] ?? '' );
					$key   = (string) ( $this->last_prepare_args[1] ?? '' );
					foreach ( $this->rows as $row ) {
						if ( (string) $row['scope'] === $scope && (string) $row['memory_key'] === $key ) {
							return (int) $row['id'];
						}
					}
					return null;
				}
				return null;
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				$id = (int) ( $this->last_prepare_args[0] ?? 0 );
				foreach ( $this->rows as $row ) {
					if ( (int) $row['id'] === $id ) {
						return $row;
					}
				}
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				$row               = $data;
				$row['id']         = $this->insert_id;
				$row['created_at'] = $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$row['updated_at'] = $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' );
				$row['last_retrieved_at'] = $row['last_retrieved_at'] ?? '';
				$this->rows[]      = $row;
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$id = (int) ( $where['id'] ?? 0 );
				foreach ( $this->rows as $i => $row ) {
					if ( (int) $row['id'] === $id ) {
						$this->rows[ $i ] = array_merge( $row, $data, [ 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ] );
						return 1;
					}
				}
				return 0;
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
							static fn( array $row ): bool => ( $row['status'] ?? '' ) === $status
						)
					);
				}
				return $rows;
			}
		};
	}
}
