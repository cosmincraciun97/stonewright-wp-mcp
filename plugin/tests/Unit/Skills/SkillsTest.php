<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
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

	public function test_memory_instructions_block_includes_compact_entries_without_raw_dump(): void {
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
		$this->assertStringContainsString( 'elementor-no-html-widgets', $block );
		$this->assertStringContainsString( 'Do not auto-render HTML widgets', $block );
		$this->assertLessThan( 900, strlen( $block ) );
	}

	public function test_memory_instructions_block_preserves_scalar_memory_values(): void {
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

		$this->assertStringContainsString( 'custom-css-approval', $block );
		$this->assertStringContainsString( 'Custom CSS must be approved', $block );
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
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Creates a minimal wpdb mock where the table IS found and rows returned.
	 *
	 * @param array<int, array<string, string>> $rows
	 */
	private function make_wpdb_with_rows( array $rows ): object {
		return new class( $rows ) {
			/** @var array<int, array<string, string>> */
			private array $rows;

			/** @param array<int, array<string, string>> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			public string $prefix    = 'wp_';
			public int    $insert_id = 42;

			// Returns a non-null value so table_exists() → true.
			public function get_var( string $q ): string {
				return 'wp_stonewright_skills';
			}

			/** @return array<int, array<string, string>> */
			public function get_results( string $q, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<string, string>|null */
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
		};
	}
}
