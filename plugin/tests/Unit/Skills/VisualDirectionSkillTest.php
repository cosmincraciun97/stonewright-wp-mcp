<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Skills;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Context\ContextBuilder;
use Stonewright\WpMcp\Skills\SkillsSeeder;

/**
 * Contract for the visual-direction skill pack.
 *
 * The pack is instructions, not code, so the contract worth pinning is what it
 * tells an agent to do: keep the Stonewright safety chain, keep imported prose
 * untrusted, and hand detail to references instead of growing into a wall of
 * text nobody reads.
 *
 * @coversNothing
 */
final class VisualDirectionSkillTest extends TestCase {

	private const SLUG = 'stonewright-visual-direction';

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_user_caps']       = [ 'read' => true, 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_transients']      = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options']    = [];
		$GLOBALS['stonewright_test_transients'] = [];
	}

	private function pack_dir(): string {
		return dirname( __DIR__, 3 ) . '/../skills/visual-direction';
	}

	private function skill_body(): string {
		return (string) file_get_contents( $this->pack_dir() . '/SKILL.md' );
	}

	private function reference_body( string $name ): string {
		return (string) file_get_contents( $this->pack_dir() . '/references/' . $name );
	}

	private function builder_body(): string {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/../skills/elementor-v3-builder/SKILL.md' );
	}

	public function test_the_pack_ships_one_skill_and_three_references(): void {
		self::assertFileExists( $this->pack_dir() . '/SKILL.md' );
		self::assertFileExists( $this->pack_dir() . '/references/direction-contract.md' );
		self::assertFileExists( $this->pack_dir() . '/references/composition-checklist.md' );
		self::assertFileExists( $this->pack_dir() . '/references/rendered-quality-loop.md' );
	}

	public function test_front_matter_declares_the_name_trigger_topic_and_constraints(): void {
		$body = $this->skill_body();

		self::assertStringStartsWith( '---', ltrim( $body ) );
		self::assertStringContainsString( 'name: visual-direction', $body );

		// The lint rule that keeps skills matchable wants a stated trigger.
		self::assertMatchesRegularExpression( '/description:\s*>/', $body );
		self::assertStringContainsString( 'Use when', $body );

		// Topic mentions Elementor, so the lint rule also demands constraints.
		self::assertMatchesRegularExpression( '/^topic:\s*\S*elementor\S*/mi', $body );
		self::assertMatchesRegularExpression( '/^version_constraints:\s*(\{.+\})$/m', $body, 'Constraints must be a single-line JSON object the seeder can decode.' );

		preg_match( '/^version_constraints:\s*(\{.+\})$/m', $body, $match );
		$decoded = json_decode( (string) ( $match[1] ?? '' ), true );
		self::assertIsArray( $decoded );
		self::assertArrayHasKey( 'elementor', $decoded );
	}

	public function test_the_skill_keeps_the_stonewright_safety_and_evidence_chain(): void {
		$body = $this->skill_body();

		self::assertStringContainsString( 'stonewright-task-start', $body );
		self::assertStringContainsString( 'stonewright-design-direction-get', $body );
		self::assertStringContainsString( 'DesignEvidence', $body );
		self::assertStringContainsString( 'stonewright-design-native-plan', $body );
		self::assertStringContainsString( 'stonewright-design-checkpoint-record', $body );
		self::assertStringContainsString( 'stonewright-design-quality-check', $body );
		self::assertStringContainsString( 'stonewright-design-direction-sync-plan', $body );
	}

	public function test_the_skill_demands_rendered_evidence_and_protects_other_breakpoints(): void {
		$body = strtolower( $this->skill_body() );

		self::assertStringContainsString( 'rendered', $body );
		self::assertStringContainsString( 'breakpoint', $body );
		self::assertStringContainsString( 'first section', $body );
		self::assertStringContainsString( 'non-target breakpoints', $body );
	}

	public function test_the_skill_forbids_raw_tree_writes_and_trusts_no_imported_prose(): void {
		$body = $this->skill_body();

		self::assertStringContainsString( '_elementor_data', $body );
		self::assertStringContainsString( 'stonewright-elementor-v3-batch-mutate', $body );
		self::assertMatchesRegularExpression( '/never|do not/i', $body );
		self::assertStringContainsString( 'untrusted', strtolower( $body ) );
	}

	public function test_the_skill_stays_compact_and_routes_detail_to_the_references(): void {
		$body  = $this->skill_body();
		$words = str_word_count( wp_strip_all_tags( $body ) );

		self::assertLessThan( 900, $words, 'SKILL.md is the index; detail belongs in the references.' );
		self::assertStringContainsString( 'references/direction-contract.md', $body );
		self::assertStringContainsString( 'references/composition-checklist.md', $body );
		self::assertStringContainsString( 'references/rendered-quality-loop.md', $body );
	}

	public function test_the_composition_checklist_advises_without_inventing_universal_blockers(): void {
		$body = $this->reference_body( 'composition-checklist.md' );
		$low  = strtolower( $body );

		foreach ( [ 'contrast', 'hierarchy', 'rhythm', 'variation', 'density', 'motion' ] as $topic ) {
			self::assertStringContainsString( $topic, $low, sprintf( 'The checklist should cover %s.', $topic ) );
		}

		// Taste is advice. Only the evidence gates block a build.
		self::assertMatchesRegularExpression( '/recommendation|preference|not a blocker/i', $body );
		self::assertStringNotContainsString( 'always reject', $low );
		self::assertStringNotContainsString( 'must never use', $low );
	}

	public function test_the_direction_contract_and_quality_loop_name_their_gates(): void {
		$contract = $this->reference_body( 'direction-contract.md' );
		$loop     = $this->reference_body( 'rendered-quality-loop.md' );

		self::assertStringContainsString( 'stonewright-design-direction-capture', $contract );
		self::assertStringContainsString( 'stonewright-design-direction-activate', $contract );
		self::assertStringContainsString( 'stonewright-design-direction-restore', $contract );
		self::assertStringContainsString( 'stonewright-design-direction-sync-apply', $contract );

		self::assertStringContainsString( 'stonewright-design-quality-check', $loop );
		self::assertStringContainsString( 'stonewright-design-checkpoint-record', $loop );
		self::assertStringContainsString( 'evidence', strtolower( $loop ) );
	}

	public function test_the_elementor_builder_loads_the_pack_only_for_direction_work(): void {
		$body = $this->builder_body();

		self::assertStringContainsString( 'visual-direction', $body );
		self::assertMatchesRegularExpression( '/new or changed visual direction/i', $body );

		// The builder keeps owning surgical edits; the pack does not replace it.
		self::assertStringContainsString( 'stonewright/elementor-v3-batch-mutate', $body );
		self::assertStringContainsString( 'Every container, section, column, and widget node must have a', $body );
	}

	public function test_the_seeder_carries_topic_and_version_constraints_into_the_row(): void {
		$row = $this->seeded_row( self::SLUG );

		self::assertIsArray( $row, 'The seeder should insert the visual-direction pack.' );
		self::assertSame( 'builtin', $row['source'] );
		self::assertStringContainsString( 'elementor', (string) $row['topic'] );

		$constraints = json_decode( (string) $row['version_constraints_json'], true );
		self::assertIsArray( $constraints );
		self::assertArrayHasKey( 'elementor', $constraints );
	}

	public function test_a_rebrand_task_matches_the_pack_while_a_copy_edit_does_not(): void {
		$row = $this->seeded_row( self::SLUG );
		self::assertIsArray( $row );

		$GLOBALS['wpdb'] = $this->make_matching_wpdb( $row );

		$rebrand = ContextBuilder::build(
			'Rebrand the site with a new visual direction and refreshed typography',
			'unknown',
			'write'
		);
		$slugs   = array_column( $rebrand['matched_skills'], 'slug' );

		self::assertContains( self::SLUG, $slugs );
		self::assertSame( self::SLUG, $slugs[0], 'A direction change should outrank unrelated packs.' );

		$copy_edit = ContextBuilder::build(
			'Fix a spelling mistake in a heading',
			'unknown',
			'write'
		);

		self::assertNotContains( self::SLUG, array_column( $copy_edit['matched_skills'], 'slug' ) );
	}

	/**
	 * Run the real seeder against a capture stub and return one inserted row.
	 *
	 * Reading the row the seeder actually writes keeps this test honest: it
	 * proves the front matter reaches the database, not that two parsers agree.
	 *
	 * @return array<string, mixed>|null
	 */
	private function seeded_row( string $slug ): ?array {
		$capture = new class() {
			public string $prefix = 'wp_';

			public int $insert_id = 0;

			/** @var array<string, array<string, mixed>> */
			public array $rows = [];

			public function get_var( string $query = '' ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}

			/**
			 * @param array<string, mixed> $data Row values.
			 */
			public function insert( string $table, array $data ): int {
				++$this->insert_id;
				$this->rows[ (string) ( $data['slug'] ?? '' ) ] = $data;

				return 1;
			}

			/**
			 * @param array<string, mixed> $data  Row values.
			 * @param array<string, mixed> $where Row selector.
			 */
			public function update( string $table, array $data, array $where ): int {
				$this->rows[ (string) ( $where['slug'] ?? '' ) ] = $data;

				return 1;
			}
		};

		$GLOBALS['wpdb'] = $capture;
		SkillsSeeder::seed();

		return $capture->rows[ $slug ] ?? null;
	}

	/**
	 * Two agentic skills: the seeded pack and an unrelated control.
	 *
	 * @param array<string, mixed> $row Seeded visual-direction row.
	 */
	private function make_matching_wpdb( array $row ): object {
		return new class( $row ) {
			public string $prefix = 'wp_';

			/** @param array<string, mixed> $row Seeded visual-direction row. */
			public function __construct( private array $row ) {}

			public function get_var( string $query = '' ): string {
				return 'wp_stonewright_skills';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( ! str_contains( $query, 'stonewright_skills' ) ) {
					return [];
				}

				return [
					[
						'id'             => '1',
						'slug'           => 'stonewright-woocommerce-catalog',
						'title'          => 'Woocommerce Catalog',
						'description'    => 'Use when a task manages WooCommerce products, variations, or orders.',
						'content'        => '# WooCommerce Catalog',
						'enabled'        => '1',
						'enable_agentic' => '1',
						'enable_prompt'  => '1',
						'source'         => 'builtin',
						'status'         => 'active',
					],
					[
						'id'             => '2',
						'slug'           => (string) $this->row['slug'],
						'title'          => (string) $this->row['title'],
						'description'    => (string) $this->row['description'],
						'content'        => (string) $this->row['content'],
						'enabled'        => '1',
						'enable_agentic' => '1',
						'enable_prompt'  => '1',
						'source'         => 'builtin',
						'status'         => 'active',
					],
				];
			}
		};
	}
}
