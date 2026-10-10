<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Memory\LearningRecord;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * @covers \Stonewright\WpMcp\Abilities\Memory\LearningRecord
 */
final class LearningRecordTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 5;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options'] = [ 'stonewright_memory_enabled' => true ];
		$GLOBALS['wpdb'] = $this->make_wpdb();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_records_correction_to_memory_without_auto_creating_a_skill(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'scope'      => 'elementor',
				'topic'      => 'Elementor HTML widget',
				'correction' => 'Do not use HTML widgets for Elementor v3 design implementations.',
				'lesson'     => 'Use native Elementor widgets and configure their Content, Style, and Advanced controls.',
			]
		);

		self::assertIsArray( $result );
		self::assertSame( true, $result['ok'] );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'plugin', $result['backend'] );
		self::assertSame( 'learning-elementor-html-widget', $result['memory_key'] );
		self::assertNull( $result['skill_slug'] );
		self::assertStringStartsWith( 'wp:stonewright_memory#', (string) $result['storage_ref'] );

		$memory_insert = $GLOBALS['wpdb']->inserts[0] ?? [];
		self::assertStringEndsWith( 'stonewright_memory', (string) ( $memory_insert['table'] ?? '' ) );
		// Explicit learning is project/user-authored, not audit feedback.
		self::assertSame( 'project', $memory_insert['data']['type'] );
		self::assertSame( 'elementor', $memory_insert['data']['scope'] );
		self::assertSame( 'learning-elementor-html-widget', $memory_insert['data']['memory_key'] );
		self::assertStringContainsString( 'Do not use HTML widgets', $memory_insert['data']['value_json'] );

		$skill_inserts = array_filter(
			$GLOBALS['wpdb']->inserts,
			static fn( array $insert ): bool => str_ends_with( (string) $insert['table'], 'stonewright_skills' )
		);
		self::assertSame( [], array_values( $skill_inserts ) );
	}

	public function test_stores_trigger_severity_and_source(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'scope'      => 'project',
				'topic'      => 'HTTP is fine',
				'correction' => 'Do not require HTTPS on local.',
				'trigger'    => 'setup diagnostics',
				'severity'  => 'high',
				'source'     => 'user-correction',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['verified'] );
		$memory_insert = $GLOBALS['wpdb']->inserts[0] ?? [];
		$value         = (string) ( $memory_insert['data']['value_json'] ?? '' );
		self::assertStringContainsString( 'user-correction', $value );
		self::assertStringContainsString( 'high', $value );
		self::assertStringContainsString( 'setup diagnostics', $value );
		self::assertSame( 'project', $memory_insert['data']['type'] );
		self::assertGreaterThanOrEqual( 700, (int) ( $memory_insert['data']['precedence'] ?? 0 ) );
	}

	public function test_accepts_legacy_text_and_returns_canonical_receipt(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'text'  => 'Always use native Elementor typography controls.',
				'scope' => 'user',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['stored'] );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'plugin', $result['backend'] );
		self::assertSame( 'user', $result['scope'] );
		self::assertNotEmpty( $result['memory_id'] );
		$memory_insert = $GLOBALS['wpdb']->inserts[0] ?? [];
		self::assertSame( 'user', $memory_insert['data']['type'] );
	}

	public function test_canonical_request_with_explicit_user_source(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'topic'      => 'Device tabs',
				'correction' => 'Use Elementor toolbar device tabs, never resize the editor window.',
				'scope'      => 'user',
				'source'     => 'explicit-user-request',
				'evidence'   => 'User correction in chat',
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'user', $result['memory_type'] );
	}

	public function test_opt_in_skill_is_saved_as_disabled_draft(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'scope'        => 'elementor',
				'topic'        => 'Elementor HTML widget',
				'correction'   => 'Use native Elementor widgets.',
				'update_skill' => true,
			]
		);

		self::assertSame( 'learned-elementor-html-widget', $result['skill_slug'] );
		self::assertSame( 'draft', $result['skill_status'] );
		$skill_insert = $GLOBALS['wpdb']->inserts[1] ?? [];
		self::assertStringEndsWith( 'stonewright_skills', (string) ( $skill_insert['table'] ?? '' ) );
		self::assertSame( 'learned-elementor-html-widget', $skill_insert['data']['slug'] );
		self::assertStringContainsString( 'Use when working on Elementor HTML widget', $skill_insert['data']['description'] );
		self::assertStringContainsString( 'Use native Elementor widgets', $skill_insert['data']['content'] );
		self::assertSame( 0, $skill_insert['data']['enabled'] );
		self::assertSame( 'draft', $skill_insert['data']['status'] );
	}

	public function test_a_learning_draft_for_the_same_topic_is_updated(): void {
		$wpdb            = $this->make_site_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$request         = [
			'scope'        => 'elementor',
			'topic'        => 'Native widgets',
			'correction'   => 'Use native Elementor widgets.',
			'update_skill' => true,
		];

		$first = ( new LearningRecord() )->execute( $request );

		self::assertIsArray( $first );
		self::assertSame( [ 'learned-native-widgets', 'draft' ], [ $first['skill_slug'], $first['skill_status'] ] );
		$id = $first['skill_id'];
		self::assertIsInt( $id );
		self::assertSame( [ 'user', 'draft', '0', 'Native widgets', '1' ], $this->skill_columns( $wpdb->skills->skills[ $id ] ) );

		$second = ( new LearningRecord() )->execute( array_replace( $request, [ 'skill_content' => '# Learned: refined' ] ) );

		self::assertIsArray( $second );
		self::assertArrayNotHasKey( 'skill_error', $second );
		self::assertSame( [ $id, 'draft' ], [ $second['skill_id'], $second['skill_status'] ] );
		self::assertSame( [ 'user', 'draft', '0', 'Native widgets', '2' ], $this->skill_columns( $wpdb->skills->skills[ $id ] ) );
		self::assertSame( '# Learned: refined', $wpdb->skills->skills[ $id ]['content'] );
	}

	/**
	 * @dataProvider skills_learning_does_not_own
	 * @param array<string, mixed> $stored
	 */
	public function test_a_skill_learning_does_not_own_is_left_unchanged_while_the_lesson_is_still_stored( array $stored ): void {
		$wpdb            = $this->make_site_wpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->skills->seed_skill( $stored + [ 'slug' => 'learned-native-widgets', 'title' => 'Written elsewhere', 'description' => 'Use when widgets come up.', 'content' => '# Not written by learning' ] );
		$before = $wpdb->skills->skills;

		$result = ( new LearningRecord() )->execute(
			[
				'scope'         => 'elementor',
				'topic'         => 'Native widgets',
				'correction'    => 'Use native Elementor widgets.',
				'update_skill'  => true,
				'skill_content' => '# Learned: replacement',
			]
		);

		self::assertSame( $before, $wpdb->skills->skills, 'The stored skill keeps its text, status, exposure, and revision.' );
		self::assertSame( [], $wpdb->skills->versions );
		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertTrue( $result['verified'] );
		self::assertNotEmpty( $result['memory_id'], 'The lesson itself is still stored.' );
		self::assertSame( 'learning-native-widgets', $result['memory_key'] );
		self::assertSame( 'stonewright_skill_slug_taken', $result['skill_error'] ?? null );
		self::assertSame( [ null, 'learned-native-widgets', null ], [ $result['skill_id'], $result['skill_slug'], $result['skill_status'] ] );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function skills_learning_does_not_own(): array {
		return [
			'active skill for the same topic'      => [ [ 'status' => 'active', 'enabled' => 1, 'topic' => 'Native widgets' ] ],
			'draft written for another topic'      => [ [ 'status' => 'draft', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => 'Typography' ] ],
			'draft without a topic'                => [ [ 'status' => 'draft', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => '' ] ],
			'imported draft for the same topic'    => [ [ 'status' => 'draft', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => 'Native widgets', 'source' => 'uploaded' ] ],
			'verified knowledge candidate'         => [ [ 'status' => 'draft', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => 'Native widgets', 'source' => 'candidate' ] ],
			'stale skill for the same topic'       => [ [ 'status' => 'stale', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => 'Native widgets' ] ],
			'trashed draft for the same topic'     => [ [ 'status' => 'trashed', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'topic' => 'Native widgets', 'trashed_at' => '2026-10-06 08:00:00' ] ],
			'shipped skill'                        => [ [ 'status' => 'active', 'enabled' => 1, 'topic' => 'Native widgets', 'source' => 'builtin' ] ],
		];
	}

	public function test_learning_record_returns_error_when_store_fails(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( false );

		$result = ( new LearningRecord() )->execute(
			[
				'topic'      => 'X',
				'correction' => 'Y',
			]
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'stonewright_memory_write_failed', $result->get_error_code() );
		self::assertStringContainsString( 'memory table is unavailable', $result->get_error_message() );
	}

	public function test_user_correction_is_active_and_not_an_error_pattern_draft(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'scope'      => 'elementor',
				'topic'      => 'Native widgets',
				'correction' => 'Use native Elementor widgets.',
			]
		);

		self::assertIsArray( $result );
		$memory_insert = $GLOBALS['wpdb']->inserts[0]['data'] ?? [];
		self::assertSame( 'active', $memory_insert['status'] ?? null );
		$value = json_decode( (string) ( $memory_insert['value_json'] ?? '' ), true );
		self::assertIsArray( $value );
		self::assertNotSame( 'error-pattern-draft', $value['source'] ?? null );
		self::assertArrayNotHasKey( 'product_rule', $value );
		self::assertTrue(
			\Stonewright\WpMcp\Memory\Memory::is_task_start_eligible(
				[
					'status' => 'active',
					'value'  => $value,
				]
			)
		);
	}

	/**
	 * @param array<string, string|null> $row
	 * @return array<int, string|null>
	 */
	private function skill_columns( array $row ): array {
		return [ $row['source'], $row['status'], $row['enabled'], $row['topic'], $row['revision'] ];
	}

	/**
	 * Memory statements go to the memory stand-in and skill statements to the in-memory skill tables.
	 */
	private function make_site_wpdb(): object {
		return new class( $this->make_wpdb(), new SkillTablesDouble() ) {
			public $prefix     = 'wp_';
			public $insert_id  = 0;
			public $last_error = '';

			public function __construct(
				public object $memory,
				public SkillTablesDouble $skills
			) {}

			public function prepare( string $query, mixed ...$args ): string {
				return $this->target( $query )->prepare( $query, ...$args );
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return $this->target( $query )->get_row( $query, $output );
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->target( $query )->get_results( $query, $output );
			}

			public function get_var( string $query ): mixed {
				return $this->target( $query )->get_var( $query );
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return $this->memory->get_col( $query, $x );
			}

			public function query( string $query ): int|bool {
				return $this->target( $query ) === $this->skills ? $this->skills->query( $query ) : 0;
			}

			public function esc_like( string $text ): string {
				return $this->skills->esc_like( $text );
			}

			public function get_charset_collate(): string {
				return $this->skills->get_charset_collate();
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				$target           = $this->target( $table );
				$result           = $target->insert( $table, $data, $format );
				$this->insert_id  = (int) $target->insert_id;
				$this->last_error = (string) $target->last_error;
				return $result;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|false {
				return $this->target( $table )->update( $table, $data, $where, $format ?? [], $where_format ?? [] );
			}

			/** @param array<string, mixed> $where */
			public function delete( string $table, array $where, mixed $where_format = null ): int|false {
				return $this->target( $table ) === $this->skills ? $this->skills->delete( $table, $where, $where_format ) : 0;
			}

			private function target( string $text ): object {
				return str_contains( $text, 'stonewright_skill' ) ? $this->skills : $this->memory;
			}
		};
	}

	private function make_wpdb( bool $insert_ok = true ): object {
		return new class( $insert_ok ) {
			public $prefix     = 'wp_';
			public $insert_id     = 100;
			public $last_error = '';
			private bool $insert_ok;

			/** @var array<int, array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			/** @var array<int, array<string,mixed>> */
			public array $rows_by_id = [];

			public function __construct( bool $insert_ok ) {
				$this->insert_ok = $insert_ok;
				if ( ! $insert_ok ) {
					$this->last_error = 'Table does not exist';
				}
			}

			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) ) {
					return null;
				}
				return 'table_exists';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query . ' /*' . implode( ',', array_map( 'strval', $args ) ) . '*/';
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				// Return last memory insert for readback verification.
				if ( str_contains( $query, 'stonewright_memory' ) && str_contains( $query, 'WHERE id' ) ) {
					foreach ( array_reverse( $this->inserts ) as $insert ) {
						if ( str_ends_with( (string) $insert['table'], 'stonewright_memory' ) ) {
							$data = $insert['data'];
							return [
								'id'                   => $this->insert_id,
								'type'                 => $data['type'] ?? 'project',
								'scope'                => $data['scope'] ?? 'project',
								'memory_key'           => $data['memory_key'] ?? '',
								'name'                 => $data['name'] ?? '',
								'value_json'           => $data['value_json'] ?? '{}',
								'confidence'           => $data['confidence'] ?? 1,
								'topic'                => $data['topic'] ?? '',
								'version_fingerprint'  => '',
								'expires_at'           => null,
								'status'               => 'active',
								'precedence'           => $data['precedence'] ?? 0,
								'created_at'           => '2026-07-22 00:00:00',
								'updated_at'           => '2026-07-22 00:00:00',
							];
						}
					}
				}
				return null;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				return [];
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int|false {
				if ( ! $this->insert_ok ) {
					return false;
				}
				++$this->insert_id;
				$this->inserts[] = [
					'table' => $table,
					'data'  => $data,
				];
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->inserts[] = [
					'table' => $table,
					'data'  => array_merge( $data, $where ),
				];
				return 1;
			}
		};
	}
}
