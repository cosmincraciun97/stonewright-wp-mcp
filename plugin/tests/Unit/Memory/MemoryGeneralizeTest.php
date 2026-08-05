<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\MemoryGeneralize;
use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Memory\Scrubber;
use Stonewright\WpMcp\Security\ConfirmationToken;

/**
 * A generalization sweep is a bulk rewrite of the agent's own instructions, so
 * it defaults to reporting rather than writing, walks the table in bounded
 * batches with an explicit cursor, and never claims a single page cleaned
 * everything.
 *
 * @covers \Stonewright\WpMcp\Abilities\System\MemoryGeneralize
 * @covers \Stonewright\WpMcp\Memory\Scrubber
 */
final class MemoryGeneralizeTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 5;
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_memory_enabled' => true,
			'stonewright_mode'           => 'development',
		];
		$GLOBALS['wpdb']                             = self::make_wpdb( self::seed_rows() );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']   = [];
		$GLOBALS['stonewright_test_user_caps'] = [];
	}

	public function test_dry_run_is_the_default(): void {
		$result = ( new MemoryGeneralize() )->execute( [] );

		self::assertIsArray( $result );
		self::assertFalse( $result['applied'] );
		self::assertSame( 3, $result['scanned'] );
		self::assertSame( 0, $result['changed'] );
		self::assertSame( [], $GLOBALS['wpdb']->updates, 'A dry run must not write.' );
		self::assertCount( 3, $result['proposals'] );
	}

	public function test_proposals_describe_the_intended_change(): void {
		$result = ( new MemoryGeneralize() )->execute( [] );

		$by_id = [];
		foreach ( $result['proposals'] as $proposal ) {
			$by_id[ (int) $proposal['id'] ] = $proposal;
		}

		self::assertSame( 'generalize', $by_id[31]['action'] );
		self::assertSame( '_global', $by_id[31]['changes']['scope'] );
		self::assertSame( 'generalize', $by_id[32]['action'] );
		self::assertArrayNotHasKey( 'scope', $by_id[32]['changes'] );
		self::assertSame( 'review_for_deletion', $by_id[33]['action'] );

		foreach ( $result['proposals'] as $proposal ) {
			self::assertLessThanOrEqual( Scrubber::PREVIEW_MAX_LENGTH, mb_strlen( (string) $proposal['before'] ) );
			self::assertLessThanOrEqual( Scrubber::PREVIEW_MAX_LENGTH, mb_strlen( (string) $proposal['after'] ) );
		}
	}

	public function test_apply_writes_only_the_planned_fields(): void {
		$result = ( new MemoryGeneralize() )->execute( [ 'apply' => true ] );

		self::assertTrue( $result['applied'] );
		self::assertSame( 2, $result['changed'] );

		$updated = [];
		foreach ( $GLOBALS['wpdb']->updates as $update ) {
			$updated[ (int) $update['where']['id'] ] = $update['data'];
		}

		// The sweep walks newest-id first, so 32 is written before 31.
		self::assertSame( [ 32, 31 ], array_keys( $updated ) );
		self::assertSame( '_global', $updated[31]['scope'] );
		self::assertStringContainsString( Scrubber::HOST_PLACEHOLDER, (string) $updated[31]['name'] );
		self::assertStringNotContainsString( 'client-a.test', (string) $updated[31]['value_json'] );
		// A project row keeps its scope: it documents this installation.
		self::assertArrayNotHasKey( 'scope', $updated[32] );
	}

	public function test_apply_never_deletes_hollow_rows(): void {
		( new MemoryGeneralize() )->execute( [ 'apply' => true ] );

		self::assertSame( [], $GLOBALS['wpdb']->deletes );
		foreach ( $GLOBALS['wpdb']->updates as $update ) {
			self::assertNotSame( 33, (int) $update['where']['id'] );
		}
	}

	public function test_apply_reports_partial_database_failures(): void {
		$GLOBALS['wpdb']->fail_update_ids = [ 32 ];

		$result = ( new MemoryGeneralize() )->execute( [ 'apply' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_memory_generalize_partial_failure', $result->get_error_code() );
		self::assertSame(
			[
				'status'      => 500,
				'changed'     => 1,
				'failed_ids'  => [ 32 ],
				'next_cursor' => null,
				'done'        => true,
			],
			$result->get_error_data()
		);
	}

	public function test_cursor_walks_the_table_in_bounded_batches(): void {
		$first = ( new MemoryGeneralize() )->execute( [ 'limit' => 2 ] );

		self::assertSame( 2, $first['scanned'] );
		self::assertFalse( $first['done'], 'A partial page must never report the sweep as finished.' );
		self::assertSame( '2', $first['next_cursor'] );

		$second = ( new MemoryGeneralize() )->execute(
			[
				'limit'  => 2,
				'cursor' => $first['next_cursor'],
			]
		);

		self::assertSame( 1, $second['scanned'] );
		self::assertTrue( $second['done'] );
		self::assertNull( $second['next_cursor'] );

		$seen = array_merge(
			array_column( $first['proposals'], 'id' ),
			array_column( $second['proposals'], 'id' )
		);
		self::assertSame( [ 33, 32, 31 ], array_map( 'intval', $seen ) );
	}

	public function test_permission_callback_requires_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		self::assertFalse( ( new MemoryGeneralize() )->permission_callback( [] ) );
	}

	public function test_production_safe_apply_without_a_token_is_refused(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new MemoryGeneralize() )->execute( [ 'apply' => true ] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_confirmation_required', $result->get_error_code() );
		self::assertSame( [], $GLOBALS['wpdb']->updates );
	}

	public function test_production_safe_apply_rejects_a_token_issued_for_other_args(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new MemoryGeneralize() )->execute(
			[
				'apply'              => true,
				'limit'              => 2,
				'confirmation_token' => ConfirmationToken::issue(
					'stonewright/memory-generalize',
					[
						'apply' => true,
						'limit' => 100,
					]
				),
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( [], $GLOBALS['wpdb']->updates );
	}

	public function test_production_safe_apply_accepts_a_matching_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$args   = [
			'apply' => true,
			'limit' => 2,
		];
		$result = ( new MemoryGeneralize() )->execute(
			$args + [ 'confirmation_token' => ConfirmationToken::issue( 'stonewright/memory-generalize', $args ) ]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['applied'] );
		self::assertNotSame( [], $GLOBALS['wpdb']->updates );
	}

	public function test_production_safe_dry_run_needs_no_token(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mode'] = 'production-safe';

		$result = ( new MemoryGeneralize() )->execute( [] );

		self::assertIsArray( $result );
		self::assertFalse( $result['applied'] );
	}

	public function test_the_sweep_is_a_context_token_gated_mutation(): void {
		$result = AbilityRegistry::execute_with_context_guard( new MemoryGeneralize(), [] );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_context_required', $result->get_error_code() );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function seed_rows(): array {
		return [
			[
				'id'                  => 31,
				'type'                => 'feedback',
				'scope'               => 'client-a.test',
				'memory_key'          => 'hero-spacing-client-a.test',
				'name'                => 'Hero spacing on client-a.test',
				'value_json'          => '{"lesson":"Client rejects hero padding under 48px, see post 4821."}',
				'confidence'          => 1.0,
				'topic'               => 'elementor',
				'version_fingerprint' => '',
				'expires_at'          => null,
				'status'              => 'active',
				'precedence'          => 500,
				'created_at'          => '2026-07-01 00:00:00',
				'updated_at'          => '2026-07-01 00:00:00',
				'last_retrieved_at'   => null,
			],
			[
				'id'                  => 32,
				'type'                => 'project',
				'scope'               => 'client-a.test',
				'memory_key'          => 'homepage-kit',
				'name'                => 'Homepage kit source',
				'value_json'          => '{"note":"Homepage hero pulls its kit from https://client-a.test/kit."}',
				'confidence'          => 1.0,
				'topic'               => '',
				'version_fingerprint' => '',
				'expires_at'          => null,
				'status'              => 'active',
				'precedence'          => 0,
				'created_at'          => '2026-07-02 00:00:00',
				'updated_at'          => '2026-07-02 00:00:00',
				'last_retrieved_at'   => null,
			],
			[
				'id'                  => 33,
				'type'                => 'reference',
				'scope'               => 'client-a.test',
				'memory_key'          => 'client-a.test',
				'name'                => 'client-a.test',
				'value_json'          => '{"url":"https://client-a.test"}',
				'confidence'          => 1.0,
				'topic'               => '',
				'version_fingerprint' => '',
				'expires_at'          => null,
				'status'              => 'active',
				'precedence'          => 0,
				'created_at'          => '2026-07-03 00:00:00',
				'updated_at'          => '2026-07-03 00:00:00',
				'last_retrieved_at'   => null,
			],
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private static function make_wpdb( array $rows ): object {
		return new class( $rows ) {
			public string $prefix     = 'wp_';
			public string $last_error = '';
			public int $insert_id     = 0;

			/** @var array<int, array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			/** @var array<int, array{data:array<string,mixed>,where:array<string,mixed>}> */
			public array $updates = [];

			/** @var array<int, array<string, mixed>> */
			public array $deletes = [];

			/** @var list<int> */
			public array $fail_update_ids = [];

			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/** @var array<int, mixed> */
			private array $last_args = [];

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function get_charset_collate(): string {
				return '';
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_args = $args;
				return $query;
			}

			public function get_var( string $query = '' ): mixed {
				return 'table_exists';
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( ! str_contains( $query, 'stonewright_memory' ) ) {
					return [];
				}

				$sorted = $this->rows;
				usort( $sorted, static fn( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );

				$limit  = isset( $this->last_args[0] ) ? (int) $this->last_args[0] : 100;
				$offset = isset( $this->last_args[1] ) ? (int) $this->last_args[1] : 0;

				return array_slice( $sorted, $offset, $limit );
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				$this->inserts[] = [
					'table' => $table,
					'data'  => $data,
				];
				return 1;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int|false {
				if ( str_ends_with( $table, 'stonewright_memory' ) ) {
					$this->updates[] = [
						'data'  => $data,
						'where' => $where,
					];
					if ( in_array( (int) ( $where['id'] ?? 0 ), $this->fail_update_ids, true ) ) {
						return false;
					}
				}
				return 1;
			}

			/** @param array<string, mixed> $where */
			public function delete( string $table, array $where, array $where_format = [] ): int {
				$this->deletes[] = $where;
				return 1;
			}
		};
	}
}
