<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Design;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Design\Direction\DesignDirectionRepository;
use Stonewright\WpMcp\Design\Direction\DesignDirectionsTable;
use Stonewright\WpMcp\Design\Direction\DesignDirectionVersionsTable;

/**
 * Storage-level tests for the design direction repository.
 *
 * The repository owns every SQL statement, so these tests drive it against a
 * wpdb spy and assert the statements, tables, and row mapping it produces.
 *
 * @covers \Stonewright\WpMcp\Design\Direction\DesignDirectionRepository
 */
final class DesignDirectionRepositoryTest extends TestCase {

	/** @var mixed Saved $wpdb reference restored in tearDown. */
	private mixed $original_wpdb;

	/** @var object Spy installed as $wpdb. */
	private object $wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = $this->make_wpdb();
		$GLOBALS['wpdb']     = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_get_reads_from_the_directions_table_by_id(): void {
		$this->wpdb->row = $this->row();

		$record = ( new DesignDirectionRepository() )->get( 7 );

		$this->assertIsArray( $record );
		$this->assertStringContainsString( DesignDirectionsTable::table_name(), (string) $this->wpdb->last_query );
		$this->assertStringContainsString( 'WHERE id = 7', (string) $this->wpdb->last_query );
	}

	public function test_get_decodes_json_columns(): void {
		$this->wpdb->row = $this->row();

		$record = ( new DesignDirectionRepository() )->get( 7 );

		$this->assertIsArray( $record );
		$this->assertSame( 'Quarry', $record['contract']['identity']['name'] );
		$this->assertSame( [ 'kit' => 'kit:12' ], $record['source_refs'] );
		$this->assertSame( 7, $record['id'] );
		$this->assertSame( 3, $record['revision'] );
	}

	public function test_get_returns_null_when_missing(): void {
		$this->wpdb->row = null;

		$this->assertNull( ( new DesignDirectionRepository() )->get( 99 ) );
	}

	public function test_find_by_slug_queries_the_slug_column(): void {
		$this->wpdb->row = $this->row();

		$record = ( new DesignDirectionRepository() )->find_by_slug( 'quarry' );

		$this->assertIsArray( $record );
		$this->assertStringContainsString( "WHERE slug = 'quarry'", (string) $this->wpdb->last_query );
	}

	public function test_list_without_filters_excludes_nothing(): void {
		$this->wpdb->results = [ $this->row() ];

		$records = ( new DesignDirectionRepository() )->list();

		$this->assertCount( 1, $records );
		$this->assertStringNotContainsString( 'WHERE', (string) $this->wpdb->last_query );
		$this->assertStringContainsString( 'ORDER BY', (string) $this->wpdb->last_query );
	}

	public function test_list_filters_by_status(): void {
		$this->wpdb->results = [];

		( new DesignDirectionRepository() )->list( [ 'status' => 'ready' ] );

		$this->assertStringContainsString( "status = 'ready'", (string) $this->wpdb->last_query );
	}

	public function test_list_rejects_unknown_status_filter(): void {
		$this->wpdb->results = [];

		( new DesignDirectionRepository() )->list( [ 'status' => "ready'; DROP TABLE wp_posts; --" ] );

		$this->assertStringNotContainsString( 'DROP TABLE', (string) $this->wpdb->last_query );
	}

	public function test_save_inserts_a_new_record(): void {
		$id = ( new DesignDirectionRepository() )->save( $this->record() );

		$this->assertSame( 101, $id );
		$this->assertCount( 1, $this->wpdb->inserts );
		$this->assertSame( DesignDirectionsTable::table_name(), $this->wpdb->inserts[0]['table'] );
		$this->assertSame( 'quarry', $this->wpdb->inserts[0]['data']['slug'] );
		$this->assertArrayHasKey( 'created_at', $this->wpdb->inserts[0]['data'] );
	}

	public function test_save_encodes_contract_and_source_refs_as_json(): void {
		( new DesignDirectionRepository() )->save( $this->record() );

		$data = $this->wpdb->inserts[0]['data'];

		$this->assertIsString( $data['contract_json'] );
		$this->assertIsString( $data['source_refs_json'] );
		$this->assertSame( 'Quarry', json_decode( $data['contract_json'], true )['identity']['name'] );
	}

	public function test_save_updates_an_existing_record_by_id(): void {
		$record       = $this->record();
		$record['id'] = 7;

		$id = ( new DesignDirectionRepository() )->save( $record );

		$this->assertSame( 7, $id );
		$this->assertSame( [], $this->wpdb->inserts );
		$this->assertCount( 1, $this->wpdb->updates );
		$this->assertSame( [ 'id' => 7 ], $this->wpdb->updates[0]['where'] );
		$this->assertArrayNotHasKey( 'created_at', $this->wpdb->updates[0]['data'] );
	}

	public function test_save_rejects_a_record_without_a_slug(): void {
		$record         = $this->record();
		$record['slug'] = '';

		$result = ( new DesignDirectionRepository() )->save( $record );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_invalid', $result->get_error_code() );
	}

	public function test_save_reports_a_failed_write(): void {
		$this->wpdb->fail_writes = true;

		$result = ( new DesignDirectionRepository() )->save( $this->record() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_direction_write_failed', $result->get_error_code() );
	}

	public function test_add_version_writes_to_the_versions_table(): void {
		$result = ( new DesignDirectionRepository() )->add_version(
			[
				'direction_id'  => 7,
				'revision'      => 4,
				'status'        => 'ready',
				'contract'      => [ 'identity' => [ 'name' => 'Quarry' ] ],
				'contract_hash' => str_repeat( 'a', 64 ),
				'source_type'   => 'import',
				'source_refs'   => [],
			]
		);

		$this->assertIsInt( $result );
		$this->assertSame( DesignDirectionVersionsTable::table_name(), $this->wpdb->inserts[0]['table'] );
		$this->assertSame( 4, $this->wpdb->inserts[0]['data']['revision'] );
		$this->assertSame( 7, $this->wpdb->inserts[0]['data']['direction_id'] );
	}

	public function test_versions_are_returned_newest_first(): void {
		$this->wpdb->results = [];

		( new DesignDirectionRepository() )->versions( 7 );

		$this->assertStringContainsString( DesignDirectionVersionsTable::table_name(), (string) $this->wpdb->last_query );
		$this->assertStringContainsString( 'WHERE direction_id = 7', (string) $this->wpdb->last_query );
		$this->assertStringContainsString( 'ORDER BY revision DESC', (string) $this->wpdb->last_query );
	}

	public function test_version_returns_a_single_revision(): void {
		$this->wpdb->row = [
			'id'            => 3,
			'direction_id'  => 7,
			'revision'      => 2,
			'status'        => 'ready',
			'contract_json' => (string) wp_json_encode( [ 'identity' => [ 'name' => 'Quarry' ] ] ),
			'contract_hash' => str_repeat( 'b', 64 ),
			'source_type'   => 'import',
			'source_refs_json' => '[]',
			'created_at'    => '2026-07-24 10:00:00',
		];

		$version = ( new DesignDirectionRepository() )->version( 7, 2 );

		$this->assertIsArray( $version );
		$this->assertSame( 2, $version['revision'] );
		$this->assertSame( 'Quarry', $version['contract']['identity']['name'] );
		$this->assertStringContainsString( 'revision = 2', (string) $this->wpdb->last_query );
	}

	public function test_archive_sets_the_archived_status(): void {
		$result = ( new DesignDirectionRepository() )->archive( 7 );

		$this->assertTrue( $result );
		$this->assertSame( 'archived', $this->wpdb->updates[0]['data']['status'] );
		$this->assertSame( [ 'id' => 7 ], $this->wpdb->updates[0]['where'] );
	}

	public function test_archive_reports_a_failed_write(): void {
		$this->wpdb->fail_writes = true;

		$result = ( new DesignDirectionRepository() )->archive( 7 );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_transaction_helpers_are_no_ops_without_a_real_wpdb(): void {
		$repository = new DesignDirectionRepository();

		$repository->begin_transaction();
		$repository->commit_transaction();
		$repository->rollback_transaction();

		$this->assertSame( [], $this->wpdb->queries );
	}

	/**
	 * A stored row as the database returns it.
	 *
	 * @return array<string,mixed>
	 */
	private function row(): array {
		return [
			'id'               => 7,
			'slug'             => 'quarry',
			'status'           => 'ready',
			'contract_json'    => (string) wp_json_encode(
				[
					'schema_version' => '1.0',
					'identity'       => [
						'name'    => 'Quarry',
						'summary' => 'Stone and precision.',
					],
				]
			),
			'contract_hash'    => str_repeat( 'c', 64 ),
			'source_type'      => 'import',
			'source_refs_json' => (string) wp_json_encode( [ 'kit' => 'kit:12' ] ),
			'revision'         => 3,
			'created_at'       => '2026-07-24 09:00:00',
			'updated_at'       => '2026-07-24 10:00:00',
		];
	}

	/**
	 * A record as the service hands it to the repository.
	 *
	 * @return array<string,mixed>
	 */
	private function record(): array {
		return [
			'slug'          => 'quarry',
			'status'        => 'ready',
			'contract'      => [
				'schema_version' => '1.0',
				'identity'       => [
					'name'    => 'Quarry',
					'summary' => 'Stone and precision.',
				],
			],
			'contract_hash' => str_repeat( 'd', 64 ),
			'source_type'   => 'import',
			'source_refs'   => [ 'kit' => 'kit:12' ],
			'revision'      => 1,
		];
	}

	/**
	 * A wpdb spy that records statements and returns canned rows.
	 */
	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 101;
			public bool $fail_writes = false;
			public ?string $last_query = null;

			/** @var array<string,mixed>|null */
			public ?array $row = null;

			/** @var list<array<string,mixed>> */
			public array $results = [];

			/** @var list<array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			/** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
			public array $updates = [];

			/** @var list<string> */
			public array $queries = [];

			public function get_charset_collate(): string {
				return '';
			}

			/**
			 * @param array<int,mixed> $args
			 */
			public function prepare( string $query, ...$args ): string {
				$flat = [];
				foreach ( $args as $arg ) {
					$flat[] = is_array( $arg ) ? implode( ',', $arg ) : $arg;
				}

				$query = str_replace( [ '%d', '%s' ], [ '%d', "'%s'" ], $query );

				return vsprintf( $query, $flat );
			}

			/**
			 * @return array<string,mixed>|null
			 */
			public function get_row( string $query, string $output = 'ARRAY_A' ): ?array {
				$this->last_query = $query;
				return $this->row;
			}

			/**
			 * @return list<array<string,mixed>>
			 */
			public function get_results( string $query, string $output = 'ARRAY_A' ): array {
				$this->last_query = $query;
				return $this->results;
			}

			/**
			 * @param array<string,mixed> $data
			 * @param array<int,string>   $format
			 */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				if ( $this->fail_writes ) {
					return false;
				}

				$this->inserts[] = [
					'table' => $table,
					'data'  => $data,
				];

				return 1;
			}

			/**
			 * @param array<string,mixed> $data
			 * @param array<string,mixed> $where
			 * @param array<int,string>   $format
			 * @param array<int,string>   $where_format
			 */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
				if ( $this->fail_writes ) {
					return false;
				}

				$this->updates[] = [
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				];

				return 1;
			}

			public function query( string $query ): int|bool {
				$this->queries[] = $query;
				return true;
			}
		};
	}
}
