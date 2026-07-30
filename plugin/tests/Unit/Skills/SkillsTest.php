<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Security\ConfirmationToken;
use Stonewright\WpMcp\Skills\Skills;
use Stonewright\WpMcp\Skills\SkillsTable;

/**
 * Unit tests for the Skills system.
 *
 * These tests mock the wpdb global so no real DB is required.
 *
 * @covers \Stonewright\WpMcp\Skills\Skills
 * @covers \Stonewright\WpMcp\Skills\SkillsTable
 */
final class SkillsTest extends TestCase {

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

	// ------------------------------------------------------------------
	// SkillsTable
	// ------------------------------------------------------------------

	public function test_table_name_includes_prefix(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();
		$this->assertSame( 'wp_stonewright_skills', SkillsTable::table_name() );
	}

	public function test_schema_does_not_set_default_on_text_columns(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();

		$sql = SkillsTable::schema_sql();

		$this->assertStringContainsString( 'description text NOT NULL', $sql );
		$this->assertStringNotContainsString( 'description text NOT NULL DEFAULT', $sql );
		$this->assertStringNotContainsString( 'content mediumtext NOT NULL DEFAULT', $sql );
	}

	// ------------------------------------------------------------------
	// Skills::instructions_block
	// ------------------------------------------------------------------

	public function test_instructions_block_empty_when_no_skills(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [] );
		$block            = Skills::instructions_block();
		$this->assertSame( '', $block );
	}

	public function test_instructions_block_includes_skill_index_without_full_content(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
			[
				'id'          => '1',
				'slug'        => 'test-skill',
				'title'       => 'Test Skill',
				'description' => 'Does something useful',
				'content'     => '## Steps\n\nDo X then Y.',
				'enabled'     => '1',
				'source'      => 'user',
			],
		] );

		$block = Skills::instructions_block();

		$this->assertStringContainsString( '## Site Skills', $block );
		$this->assertStringContainsString( '- `test-skill` - Test Skill', $block );
		$this->assertStringContainsString( 'Does something useful', $block );
		$this->assertStringContainsString( 'stonewright/skills-get', $block );
		$this->assertStringNotContainsString( '## Steps', $block );
		$this->assertStringNotContainsString( 'Do X then Y', $block );
	}

	public function test_instructions_block_includes_multiple_skills(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
			[
				'id' => '1', 'slug' => 'skill-a', 'title' => 'Skill A',
				'description' => '', 'content' => 'Content A', 'enabled' => '1', 'source' => 'builtin',
			],
			[
				'id' => '2', 'slug' => 'skill-b', 'title' => 'Skill B',
				'description' => '', 'content' => 'Content B', 'enabled' => '1', 'source' => 'user',
			],
		] );

		$block = Skills::instructions_block();
		$this->assertStringContainsString( '- `skill-a` - Skill A', $block );
		$this->assertStringContainsString( '- `skill-b` - Skill B', $block );
		$this->assertStringNotContainsString( 'Content A', $block );
		$this->assertStringNotContainsString( 'Content B', $block );
	}

	public function test_instructions_block_omits_prompt_only_skills(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
			[
				'id'             => '1',
				'slug'           => 'agentic-skill',
				'title'          => 'Agentic Skill',
				'description'    => 'Auto routing hint',
				'content'        => 'Auto content',
				'enabled'        => '1',
				'enable_agentic' => '1',
				'enable_prompt'  => '1',
				'source'         => 'user',
			],
			[
				'id'             => '2',
				'slug'           => 'prompt-only-skill',
				'title'          => 'Prompt Only Skill',
				'description'    => 'Manual playbook',
				'content'        => 'Prompt content',
				'enabled'        => '1',
				'enable_agentic' => '0',
				'enable_prompt'  => '1',
				'source'         => 'user',
			],
		] );

		$block = Skills::instructions_block();

		$this->assertStringContainsString( 'agentic-skill', $block );
		$this->assertStringNotContainsString( 'prompt-only-skill', $block );
	}

	public function test_list_prompt_returns_prompt_enabled_skills(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
			[
				'id'             => '1',
				'slug'           => 'agentic-only',
				'title'          => 'Agentic Only',
				'description'    => '',
				'content'        => 'Auto content',
				'enabled'        => '1',
				'enable_agentic' => '1',
				'enable_prompt'  => '0',
				'source'         => 'user',
			],
			[
				'id'             => '2',
				'slug'           => 'prompt-skill',
				'title'          => 'Prompt Skill',
				'description'    => '',
				'content'        => 'Prompt content',
				'enabled'        => '1',
				'enable_agentic' => '0',
				'enable_prompt'  => '1',
				'source'         => 'user',
			],
		] );

		$skills = Skills::list_prompt();

		$this->assertCount( 1, $skills );
		$this->assertSame( 'prompt-skill', $skills[0]['slug'] );
	}

	public function test_memory_instructions_block_routes_to_relevant_refs_without_raw_dump(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_memory_rows( [
			[
				'id'          => '1',
				'type'        => 'feedback',
				'scope'       => 'project',
				'memory_key'  => 'elementor-no-html-widgets',
				'name'        => 'Do not auto-render HTML widgets',
				'value_json'  => wp_json_encode( [
					'rule'  => 'Use native Elementor widgets first.',
					'notes' => str_repeat( 'x', 500 ),
				] ),
				'confidence'  => '1.0000',
				'created_at'  => '2026-05-24 00:00:00',
				'updated_at'  => '2026-05-24 00:00:00',
			],
		] );

		$block = \Stonewright\WpMcp\Memory\Memory::instructions_block();

		$this->assertStringContainsString( '## Site Memory', $block );
		$this->assertStringContainsString( 'highest-priority relevant memory references', $block );
		$this->assertStringContainsString( 'stonewright/memory-get', $block );
		$this->assertStringNotContainsString( 'elementor-no-html-widgets', $block );
		$this->assertLessThan( 900, strlen( $block ) );
	}

	public function test_memory_instructions_block_does_not_inject_scalar_memory_values(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_memory_rows( [
			[
				'id'          => '1',
				'type'        => 'feedback',
				'scope'       => 'nzeb-frontend',
				'memory_key'  => 'custom-css-approval',
				'name'        => 'Custom CSS requires approval',
				'value_json'  => wp_json_encode( 'Custom CSS must be approved by the user before writing.' ),
				'confidence'  => '1.0000',
				'created_at'  => '2026-05-24 00:00:00',
				'updated_at'  => '2026-05-24 00:00:00',
			],
		] );

		$block = \Stonewright\WpMcp\Memory\Memory::instructions_block();

		$this->assertStringNotContainsString( 'custom-css-approval', $block );
		$this->assertStringNotContainsString( 'Custom CSS must be approved', $block );
	}

	// ------------------------------------------------------------------
	// Skills::list / get / save / toggle / delete — via wpdb mock
	// ------------------------------------------------------------------

	public function test_list_returns_empty_array_when_table_missing(): void {
		// table_exists returns false.
		$GLOBALS['wpdb'] = $this->make_wpdb_no_table();
		$this->assertSame( [], Skills::list() );
	}

	public function test_get_returns_null_when_table_missing(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_no_table();
		$this->assertNull( Skills::get( 'anything' ) );
	}

	public function test_save_returns_nonzero_when_slug_is_valid(): void {
		// Use the no-table stub — get_var returns null so table_exists() = false.
		// save() will still call wpdb->insert() and return insert_id.
		// The bootstrap wpdb has insert_id = 1 initially; the no-table mock
		// has no insert(), so use a stub that supports it.
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [] );
		$id = Skills::save( [ 'slug' => 'test-slug', 'title' => 'Test', 'content' => 'c' ] );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_save_blocks_credential_material_before_database_write(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [] );

		$id = Skills::save(
			[
				'slug'    => 'unsafe-secret',
				'title'   => 'Unsafe secret',
				'content' => 'Authorization: Bearer abcdefghijklmnop',
			]
		);

		self::assertSame( 0, $id );
	}

	public function test_user_save_cannot_bypass_credential_guard_by_claiming_builtin_source(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [] );

		$id = Skills::save(
			[
				'slug'    => 'unsafe-builtin-claim',
				'title'   => 'Unsafe built-in claim',
				'content' => 'Authorization: Bearer test-sensitive-token',
				'source'  => 'builtin',
			]
		);

		self::assertSame( 0, $id );
	}

	public function test_save_defaults_skill_mode_flags_from_enabled(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 100;

			/** @var array<string, mixed> */
			public array $inserted = [];

			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}

			public function get_row( string $q, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				$this->inserted = $data;
				$this->insert_id++;
				return 1;
			}
		};

		$id = Skills::save( [
			'slug'    => 'disabled-skill',
			'title'   => 'Disabled Skill',
			'content' => 'Content',
			'enabled' => false,
		] );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 0, $GLOBALS['wpdb']->inserted['enabled'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->inserted['enable_agentic'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->inserted['enable_prompt'] );
	}

	public function test_save_reactivates_existing_draft_when_enabled_is_true(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_captured_update(
			[
				'id'             => '10',
				'slug'           => 'draft-skill',
				'title'          => 'Draft Skill',
				'description'    => '',
				'content'        => 'Old content',
				'enabled'        => '0',
				'enable_agentic' => '0',
				'enable_prompt'  => '0',
				'source'         => 'user',
				'status'         => 'draft',
				'revision'       => '1',
			]
		);

		$id = Skills::save(
			[
				'slug'    => 'draft-skill',
				'title'   => 'Draft Skill',
				'content' => 'Updated content',
				'enabled' => true,
			]
		);

		self::assertSame( 10, $id );
		self::assertSame( 1, $GLOBALS['wpdb']->updated['enabled'] );
		self::assertSame( 'active', $GLOBALS['wpdb']->updated['status'] );
	}

	public function test_toggle_reactivates_existing_draft(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_captured_update(
			[
				'id'      => '11',
				'slug'    => 'toggle-skill',
				'enabled' => '0',
				'source'  => 'user',
				'status'  => 'draft',
			]
		);

		self::assertTrue( Skills::toggle( 11, true ) );
		self::assertSame( 1, $GLOBALS['wpdb']->updated['enabled'] );
		self::assertSame( 'active', $GLOBALS['wpdb']->updated['status'] );
	}

	public function test_delete_refuses_builtin_skills(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public bool $delete_called = false;

			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}

			/** @return array<string, string> */
			public function get_row( string $q, string $output = 'OBJECT' ): array {
				return [
					'id'     => '10',
					'slug'   => 'builtin-skill',
					'source' => 'builtin',
				];
			}

			/** @param array<string, mixed> $where */
			public function delete( string $table, array $where ): int {
				$this->delete_called = true;
				return 1;
			}
		};

		$this->assertFalse( Skills::delete( 10 ) );
		$this->assertFalse( $GLOBALS['wpdb']->delete_called );
	}

	// ------------------------------------------------------------------
	// AgentInstructions integration — skills block injected
	// ------------------------------------------------------------------

	public function test_agent_instructions_contains_skills_header_when_skills_present(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [
			[
				'id' => '1', 'slug' => 'demo-skill', 'title' => 'Demo',
				'description' => 'Does demos', 'content' => 'Demo content', 'enabled' => '1', 'source' => 'user',
			],
		] );

		// Call AgentInstructions::default() — needs get_option available.
		// We test the Skills::instructions_block() proxy instead.
		$block = Skills::instructions_block();
		$this->assertStringContainsString( '## Site Skills', $block );
		$this->assertStringContainsString( 'stonewright/skills-get', $block );
	}

	// ------------------------------------------------------------------
	// Trash, restore, and hard delete
	// ------------------------------------------------------------------

	public function test_schema_carries_a_nullable_trashed_at_column(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();

		$this->assertStringContainsString( 'trashed_at datetime DEFAULT NULL', SkillsTable::schema_sql() );
	}

	public function test_trash_disables_an_active_skill_and_stamps_the_time(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'             => '21',
					'slug'           => 'spacing-rules',
					'title'          => 'Spacing rules',
					'enabled'        => '1',
					'enable_agentic' => '1',
					'enable_prompt'  => '1',
					'source'         => 'user',
					'status'         => 'active',
					'trashed_at'     => null,
				],
			]
		);

		$this->assertTrue( Skills::trash( 21 ) );

		$updated = $GLOBALS['wpdb']->updated;
		$this->assertSame( 'trashed', $updated['status'] );
		$this->assertSame( 0, $updated['enabled'] );
		$this->assertSame( 0, $updated['enable_agentic'] );
		$this->assertSame( 0, $updated['enable_prompt'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $updated['trashed_at'] );
	}

	/**
	 * @dataProvider protected_sources
	 */
	public function test_trash_refuses_skills_stonewright_ships( string $source ): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'     => '30',
					'slug'   => 'shipped-skill',
					'source' => $source,
					'status' => 'active',
				],
			]
		);

		$error = Skills::trash( 30 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stonewright_skill_protected', $error->get_error_code() );
		$this->assertSame( [], $GLOBALS['wpdb']->updated );
	}

	/** @return array<string, array{string}> */
	public static function protected_sources(): array {
		return [
			'builtin'  => [ 'builtin' ],
			'playbook' => [ 'playbook' ],
		];
	}

	public function test_trash_refuses_a_skill_that_is_already_trashed(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'         => '31',
					'slug'       => 'already-gone',
					'source'     => 'user',
					'status'     => 'trashed',
					'enabled'    => '0',
					'trashed_at' => '2026-07-01 09:00:00',
				],
			]
		);

		$error = Skills::trash( 31 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stonewright_skill_already_trashed', $error->get_error_code() );
	}

	public function test_trash_refuses_a_skill_that_does_not_exist(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle( [] );

		$error = Skills::trash( 999 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stonewright_skill_not_found', $error->get_error_code() );
	}

	public function test_trashed_skills_never_reach_an_agent(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'             => '40',
					'slug'           => 'live-skill',
					'title'          => 'Live skill',
					'description'    => 'Use when the site is live.',
					'enabled'        => '1',
					'enable_agentic' => '1',
					'enable_prompt'  => '1',
					'source'         => 'user',
					'status'         => 'active',
				],
				[
					'id'             => '41',
					'slug'           => 'binned-skill',
					'title'          => 'Binned skill',
					'description'    => 'Use when nothing at all.',
					'enabled'        => '0',
					'enable_agentic' => '0',
					'enable_prompt'  => '0',
					'source'         => 'user',
					'status'         => 'trashed',
					'trashed_at'     => '2026-07-20 12:00:00',
				],
			]
		);

		$this->assertSame( [ 'live-skill' ], array_column( Skills::list(), 'slug' ) );
		$this->assertSame( [ 'live-skill' ], array_column( Skills::list_agentic(), 'slug' ) );
		$this->assertStringNotContainsString( 'binned-skill', Skills::instructions_block() );
	}

	public function test_list_trashed_returns_only_the_trashed_rows(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'     => '50',
					'slug'   => 'live-skill',
					'source' => 'user',
					'status' => 'active',
				],
				[
					'id'         => '51',
					'slug'       => 'binned-skill',
					'source'     => 'user',
					'status'     => 'trashed',
					'trashed_at' => '2026-07-20 12:00:00',
				],
			]
		);

		$this->assertSame( [ 'binned-skill' ], array_column( Skills::list_trashed(), 'slug' ) );
	}

	public function test_restore_returns_a_skill_to_a_disabled_draft(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'         => '60',
					'slug'       => 'binned-skill',
					'source'     => 'user',
					'status'     => 'trashed',
					'enabled'    => '0',
					'trashed_at' => '2026-07-20 12:00:00',
				],
			]
		);

		$this->assertTrue( Skills::restore( 60 ) );

		$updated = $GLOBALS['wpdb']->updated;
		$this->assertSame( 'draft', $updated['status'] );
		$this->assertSame( 0, $updated['enabled'] );
		$this->assertNull( $updated['trashed_at'] );
	}

	public function test_restore_refuses_a_skill_that_is_not_trashed(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle(
			[
				[
					'id'     => '61',
					'slug'   => 'live-skill',
					'source' => 'user',
					'status' => 'active',
				],
			]
		);

		$error = Skills::restore( 61 );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'stonewright_skill_not_trashed', $error->get_error_code() );
	}

	public function test_hard_delete_refuses_an_unconfirmed_call_in_production_safe_mode(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle( [ $this->trashed_row() ] );
		update_option( 'stonewright_mode', 'production-safe' );

		try {
			$error = Skills::destroy( 70 );

			$this->assertInstanceOf( \WP_Error::class, $error );
			$this->assertStringStartsWith( 'stonewright_confirmation_', $error->get_error_code() );
			$this->assertSame( [], $GLOBALS['wpdb']->deleted );
		} finally {
			delete_option( 'stonewright_mode' );
		}
	}

	public function test_hard_delete_accepts_a_matching_confirmation_token(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle( [ $this->trashed_row() ] );
		update_option( 'stonewright_mode', 'production-safe' );

		try {
			$token = ConfirmationToken::issue( Skills::DESTROY_ABILITY, [ 'id' => 70 ] );

			$this->assertTrue( Skills::destroy( 70, $token ) );
			$this->assertSame( [ [ 'id' => 70 ] ], $GLOBALS['wpdb']->deleted );
		} finally {
			delete_option( 'stonewright_mode' );
		}
	}

	public function test_hard_delete_needs_no_token_outside_production_safe_mode(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_lifecycle( [ $this->trashed_row() ] );

		$this->assertTrue( Skills::destroy( 70 ) );
		$this->assertTrue( Skills::delete( 70 ) );
	}

	/** @return array<string, mixed> */
	private function trashed_row(): array {
		return [
			'id'         => '70',
			'slug'       => 'binned-skill',
			'source'     => 'user',
			'status'     => 'trashed',
			'enabled'    => '0',
			'trashed_at' => '2026-07-20 12:00:00',
		];
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * wpdb stub that remembers rows and answers id lookups from prepare() args.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_wpdb_lifecycle( array $rows ): object {
		return new class( $rows ) {
			public string $prefix    = 'wp_';
			public int    $insert_id = 90;

			/** @var array<string, mixed> */
			public array $updated = [];

			/** @var list<array<string, mixed>> */
			public array $deleted = [];

			/** @var list<mixed> */
			private array $last_args = [];

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( private array $rows ) {}

			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $q, mixed ...$args ): string {
				$this->last_args = $args;
				return $q;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $q, string $output = 'OBJECT' ): ?array {
				$needle = (string) ( $this->last_args[0] ?? '' );

				foreach ( $this->rows as $row ) {
					if ( (string) ( $row['id'] ?? '' ) === $needle || (string) ( $row['slug'] ?? '' ) === $needle ) {
						return $row;
					}
				}

				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				return 1;
			}

			/**
			 * @param array<string, mixed> $data
			 * @param array<string, mixed> $where
			 */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated = $data;
				return 1;
			}

			/** @param array<string, mixed> $where */
			public function delete( string $table, array $where ): int {
				$this->deleted[] = $where;
				return 1;
			}
		};
	}

	/**
	 * Creates a minimal wpdb mock where the table IS found and rows returned.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_wpdb_with_rows( array $rows ): object {
		return new class( $rows ) {
			/** @var array<int, array<string, mixed>> */
			private array $rows;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public string $prefix    = 'wp_';
			public int    $insert_id = 42;

			// Returns a non-null value so table_exists() → true.
			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $q, string $output = 'OBJECT' ): ?array {
				return $this->rows[0] ?? null;
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q; // simplified.
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				$this->insert_id++;
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}
		};
	}

	/**
	 * @param array<int, array<string, string>> $rows
	 */
	private function make_wpdb_with_memory_rows( array $rows ): object {
		return new class( $rows ) {
			/** @var array<int, array<string, string>> */
			private array $rows;
			public string $prefix = 'wp_';

			/** @param array<int, array<string, string>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}

			/** @return array<int, array<string, string>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}
		};
	}

	/** @param array<string, mixed> $row */
	private function make_wpdb_with_captured_update( array $row ): object {
		return new class( $row ) {
			public string $prefix = 'wp_';
			/** @var array<string, mixed> */
			public array $updated = [];
			/** @param array<string, mixed> $row */
			public function __construct( private array $row ) {}
			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}
			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}
			/** @return array<string, mixed> */
			public function get_row( string $q, string $output = 'OBJECT' ): array {
				return $this->row;
			}
			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				return 1;
			}
			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				$this->updated = $data;
				return 1;
			}
		};
	}

	/** Creates a minimal wpdb mock where the table does NOT exist (get_var → null). */
	private function make_wpdb_no_table(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_var( string $q ): ?string {
				return null; // null → table_exists() = false.
			}

			public function prepare( string $q, mixed ...$args ): string {
				return $q;
			}
		};
	}

	/** Creates a minimal wpdb mock for table_name() only. */
	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			}
		};
	}
}
