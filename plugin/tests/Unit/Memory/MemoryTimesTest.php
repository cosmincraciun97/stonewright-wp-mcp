<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Memory\Memory;

/**
 * Memory times are stored in UTC by the plugin itself, and reading an entry
 * records only when it was retrieved, never a new "updated" time.
 *
 * @covers \Stonewright\WpMcp\Memory\Memory
 */
final class MemoryTimesTest extends TestCase {

	private mixed $saved_wpdb = null;

	protected function setUp(): void {
		$this->saved_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->saved_wpdb;
	}

	public function test_reading_records_retrieval_without_touching_the_updated_time(): void {
		$recorder        = $this->recorder();
		$GLOBALS['wpdb'] = $recorder;

		Memory::mark_retrieved( [ 5, 5, 0 ] );

		self::assertSame( [], $recorder->updates, 'No generic row update may run, because the table bumps updated_at on any update.' );
		self::assertCount( 1, $recorder->queries );
		self::assertStringContainsString( 'last_retrieved_at', $recorder->queries[0] );
		self::assertStringContainsString( 'updated_at = updated_at', $recorder->queries[0] );
	}

	public function test_new_entries_store_utc_creation_and_update_times(): void {
		$recorder        = $this->recorder();
		$GLOBALS['wpdb'] = $recorder;

		$before = gmdate( 'Y-m-d H:i:s', time() - 5 );
		Memory::put( 'site', 'example-key', [ 'note' => 'synthetic' ] );
		$after = gmdate( 'Y-m-d H:i:s', time() + 5 );

		self::assertCount( 1, $recorder->inserts );
		$data = $recorder->inserts[0];
		foreach ( [ 'created_at', 'updated_at' ] as $column ) {
			self::assertArrayHasKey( $column, $data );
			self::assertGreaterThanOrEqual( $before, $data[ $column ] );
			self::assertLessThanOrEqual( $after, $data[ $column ] );
		}
	}

	private function recorder(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 0;
			public string $last_error = '';
			/** @var list<string> */
			public array $queries = [];
			/** @var list<array<string, mixed>> */
			public array $updates = [];
			/** @var list<array<string, mixed>> */
			public array $inserts = [];

			public function prepare( string $query, mixed ...$args ): string {
				return vsprintf( str_replace( [ '%s', '%d', '%f' ], [ "'%s'", '%d', '%F' ], $query ), $args );
			}

			public function get_var( string $query ): mixed {
				return null;
			}

			public function query( string $query ): int {
				$this->queries[] = $query;
				return 1;
			}

			/** @param array<string, mixed> $data */
			public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int {
				$this->updates[] = $data;
				return 1;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, mixed $format = null ): int {
				$this->inserts[] = $data;
				$this->insert_id = 1;
				return 1;
			}
		};
	}
}
