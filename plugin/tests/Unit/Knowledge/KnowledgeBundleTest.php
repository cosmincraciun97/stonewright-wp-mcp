<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Knowledge\KnowledgeBundle;
use Stonewright\WpMcp\Tests\Unit\SkillLibrary\Site\SkillTablesDouble;

/**
 * @covers \Stonewright\WpMcp\Knowledge\KnowledgeBundle
 */
final class KnowledgeBundleTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_export_contains_instructions_memory_and_skills(): void {
		update_option( 'stonewright_custom_instructions', 'Use Elementor native widgets first.' );
		update_option( 'stonewright_custom_instructions_enabled', true );
		update_option( 'stonewright_memory_enabled', true );
		$GLOBALS['wpdb'] = $this->make_wpdb(
			[
				[
					'id'          => '3',
					'type'        => 'feedback',
					'scope'       => 'site-a-frontend',
					'memory_key'  => 'no-html-widgets',
					'name'        => 'No Elementor HTML widgets by default',
					'value_json'  => wp_json_encode( 'Use native Elementor widgets unless explicitly instructed.' ),
					'confidence'  => '1.0000',
					'created_at'  => '2026-05-24 00:00:00',
					'updated_at'  => '2026-05-24 00:00:00',
				],
			],
			[
				[
					'id'             => '4',
					'slug'           => 'elementor-native-first',
					'title'          => 'Elementor native widgets first',
					'description'    => 'Use for Design reference to Elementor builds.',
					'content'        => '# Rule',
					'enabled'        => '1',
					'enable_agentic' => '1',
					'enable_prompt'  => '0',
					'source'         => 'user',
				],
			]
		);

		$bundle = KnowledgeBundle::export();

		self::assertSame( 'stonewright-knowledge-bundle', $bundle['format'] );
		self::assertSame( 1, $bundle['version'] );
		self::assertSame( 'Use Elementor native widgets first.', $bundle['instructions']['text'] );
		self::assertSame( 'no-html-widgets', $bundle['memory']['entries'][0]['memory_key'] );
		self::assertSame( 'Use native Elementor widgets unless explicitly instructed.', $bundle['memory']['entries'][0]['value'] );
		self::assertSame( 'elementor-native-first', $bundle['skills']['entries'][0]['slug'] );
		self::assertSame( '1', $bundle['skills']['entries'][0]['enable_agentic'] );
		self::assertSame( '0', $bundle['skills']['entries'][0]['enable_prompt'] );
	}

	public function test_import_updates_instructions_memory_and_skills(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb( [], [] );

		$result = KnowledgeBundle::import(
			[
				'format'       => 'stonewright-knowledge-bundle',
				'version'      => 1,
				'instructions' => [
					'enabled' => true,
					'text'    => 'Custom CSS requires explicit user approval.',
				],
				'memory'       => [
					'enabled' => true,
					'entries' => [
						[
							'type'        => 'feedback',
							'scope'       => 'site-a-frontend',
							'memory_key'  => 'custom-css-approval',
							'name'        => 'Custom CSS requires approval',
							'value'       => 'Ask before writing style.css.',
							'confidence'  => 1,
						],
					],
				],
				'skills'       => [
					'entries' => [
						[
							'slug'           => 'design-elementor-quality',
							'title'          => 'Design reference Elementor quality',
							'description'    => 'Use for page rebuilds.',
							'content'        => '# Steps',
							'enabled'        => true,
							'enable_agentic' => false,
							'enable_prompt'  => true,
						],
					],
				],
			]
		);

		self::assertSame( 'Custom CSS requires explicit user approval.', get_option( 'stonewright_custom_instructions', '' ) );
		self::assertTrue( (bool) get_option( 'stonewright_memory_enabled', false ) );
		self::assertSame( 1, $result['memory_imported'] );
		self::assertSame( 1, $result['skills_imported'] );
		self::assertSame( [], $result['skills_skipped'] );
		self::assertNotEmpty( $GLOBALS['wpdb']->memory_writes );
		self::assertNotEmpty( $GLOBALS['wpdb']->skill_writes );
		// The entry asks for an enabled skill; a bundle only ever adds disabled drafts.
		$skill_write = $GLOBALS['wpdb']->skill_writes[0];
		self::assertSame( [ 'draft', 0, 0, 0 ], [ $skill_write['status'], $skill_write['enabled'], $skill_write['enable_agentic'], $skill_write['enable_prompt'] ] );
	}

	public function test_a_new_skill_arrives_as_a_disabled_draft_whatever_the_entry_says(): void {
		$tables = $this->use_skill_tables();

		$result = KnowledgeBundle::import(
			$this->skills_bundle(
				[
					[
						'slug'           => 'Imported Note',
						'title'          => 'Imported note',
						'description'    => 'Use when testing the bundle.',
						'content'        => '# Imported',
						'enabled'        => true,
						'enable_agentic' => true,
						'enable_prompt'  => true,
						'status'         => 'active',
						'source'         => 'builtin',
					],
				]
			)
		);

		$row = $tables->skill_by_slug( 'imported-note' );
		self::assertIsArray( $row );
		self::assertSame( [ 'draft', '0', '0', '0', 'user', '1' ], [ $row['status'], $row['enabled'], $row['enable_agentic'], $row['enable_prompt'], $row['source'], $row['revision'] ] );
		self::assertSame( '# Imported', $row['content'] );
		self::assertSame( 1, $result['skills_imported'] );
		self::assertSame( [], $result['skills_skipped'] );
	}

	public function test_an_existing_local_skill_is_skipped_and_left_untouched(): void {
		$tables = $this->use_skill_tables();
		$tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'description' => 'Use when noting.', 'content' => '# Mine', 'revision' => 3 ] );
		$before = $tables->skills;

		$result = KnowledgeBundle::import( $this->skills_bundle( [ [ 'slug' => 'site-note', 'title' => 'Bundle title', 'content' => '# Bundle', 'enabled' => false ] ] ) );

		self::assertSame( $before, $tables->skills, 'Content, exposure, status, and revision all stay as they were.' );
		self::assertSame( [], $tables->versions );
		self::assertSame( 0, $result['skills_imported'] );
		self::assertSame( [ 'site-note' ], $result['skills_skipped'] );
	}

	public function test_a_trashed_skill_keeps_its_identity_and_is_skipped(): void {
		$tables = $this->use_skill_tables();
		$tables->seed_skill( [ 'slug' => 'binned-note', 'title' => 'Binned', 'content' => '# Binned', 'status' => 'trashed', 'enabled' => 0, 'enable_agentic' => 0, 'enable_prompt' => 0, 'trashed_at' => '2026-10-06 08:00:00' ] );
		$before = $tables->skills;

		$result = KnowledgeBundle::import( $this->skills_bundle( [ [ 'slug' => 'binned-note', 'title' => 'Bundle title', 'content' => '# Bundle', 'enabled' => true ] ] ) );

		self::assertSame( $before, $tables->skills );
		self::assertSame( 0, $result['skills_imported'] );
		self::assertSame( [ 'binned-note' ], $result['skills_skipped'] );
	}

	public function test_built_in_identities_are_skipped_whether_or_not_the_pack_was_seeded(): void {
		$tables = $this->use_skill_tables();
		$tables->seed_skill( [ 'slug' => 'stonewright-elementor-v3-builder', 'title' => 'Builder', 'content' => '# Shipped', 'source' => 'builtin' ] );
		$before = $tables->skills;

		$result = KnowledgeBundle::import(
			$this->skills_bundle(
				[
					[ 'slug' => 'stonewright-elementor-v3-builder', 'title' => 'Mine', 'content' => '# Mine' ],
					[ 'slug' => 'playbook-mega-menu', 'title' => 'Mine', 'content' => '# Mine' ],
				]
			)
		);

		self::assertSame( $before, $tables->skills );
		self::assertNull( $tables->skill_by_slug( 'playbook-mega-menu' ), 'A reserved identity stays free for the pack even before it is seeded.' );
		self::assertSame( 0, $result['skills_imported'] );
		self::assertSame( [ 'stonewright-elementor-v3-builder', 'playbook-mega-menu' ], $result['skills_skipped'] );
	}

	public function test_a_mixed_bundle_adds_the_new_skills_and_names_the_skipped_ones(): void {
		$tables = $this->use_skill_tables();
		$tables->seed_skill( [ 'slug' => 'site-note', 'title' => 'Site note', 'content' => '# Mine' ] );

		$result = KnowledgeBundle::import(
			$this->skills_bundle(
				[
					[ 'slug' => 'first-new', 'title' => 'First', 'content' => '# First' ],
					[ 'slug' => 'Site Note', 'title' => 'Site note again', 'content' => '# Bundle' ],
					[ 'slug' => '', 'title' => 'No identity', 'content' => '# Nothing' ],
					[ 'slug' => 'empty-body', 'title' => 'Empty body', 'content' => '' ],
					'not an entry',
					[ 'slug' => 'second-new', 'title' => 'Second', 'content' => '# Second' ],
					[ 'slug' => 'too-long-title', 'title' => str_repeat( 't', 300 ), 'content' => '# Refused by storage' ],
				]
			)
		);

		self::assertSame( [ 'site-note', 'first-new', 'second-new' ], array_column( $tables->skills, 'slug' ) );
		self::assertSame( '# Mine', $tables->skill_by_slug( 'site-note' )['content'] );
		self::assertSame( 2, $result['skills_imported'] );
		self::assertSame( [ 'site-note', 'too-long-title' ], $result['skills_skipped'], 'Entries the library refuses are reported with the ones that already exist.' );
	}

	public function test_at_most_fifty_skipped_identities_are_reported(): void {
		$tables  = $this->use_skill_tables();
		$entries = [];
		foreach ( range( 1, 55 ) as $number ) {
			$tables->seed_skill( [ 'slug' => 'site-note-' . $number, 'title' => 'Note ' . $number, 'content' => '# Mine' ] );
			$entries[] = [ 'slug' => 'site-note-' . $number, 'title' => 'Bundle ' . $number, 'content' => '# Bundle' ];
		}

		$before = $tables->skills;

		$result = KnowledgeBundle::import( $this->skills_bundle( $entries ) );

		self::assertSame( $before, $tables->skills );
		self::assertSame( 0, $result['skills_imported'] );
		self::assertCount( 50, $result['skills_skipped'] );
		self::assertSame( 'site-note-1', $result['skills_skipped'][0] );
		self::assertSame( 'site-note-50', $result['skills_skipped'][49] );
	}

	/**
	 * @param array<int, mixed> $entries
	 * @return array<string, mixed>
	 */
	private function skills_bundle( array $entries ): array {
		return [
			'format'  => 'stonewright-knowledge-bundle',
			'version' => 1,
			'skills'  => [ 'entries' => $entries ],
		];
	}

	private function use_skill_tables(): SkillTablesDouble {
		$tables          = new SkillTablesDouble();
		$GLOBALS['wpdb'] = $tables;
		return $tables;
	}

	/**
	 * @param array<int, array<string, mixed>> $memory_rows
	 * @param array<int, array<string, mixed>> $skill_rows
	 */
	private function make_wpdb( array $memory_rows, array $skill_rows ): object {
		return new class( $memory_rows, $skill_rows ) {
			public $prefix = 'wp_';
			public $insert_id = 100;
			/** @var array<int, array<string, mixed>> */
			public array $memory_writes = [];
			/** @var array<int, array<string, mixed>> */
			public array $skill_writes = [];

			/**
			 * @param array<int, array<string, mixed>> $memory_rows
			 * @param array<int, array<string, mixed>> $skill_rows
			 */
			public function __construct(
				private array $memory_rows,
				private array $skill_rows
			) {}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query ): ?string {
				if ( '' === $query || str_contains( $query, 'stonewright_skills' ) ) {
					return 'wp_stonewright_skills';
				}
				return null;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'stonewright_skills' ) ) {
					return $this->skill_rows;
				}
				return $this->memory_rows;
			}

			/** @return array<string, mixed>|null */
			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				if ( str_contains( $table, 'stonewright_skills' ) ) {
					$this->skill_writes[] = $data;
				} else {
					$this->memory_writes[] = $data;
				}
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				if ( str_contains( $table, 'stonewright_skills' ) ) {
					$this->skill_writes[] = $data;
				} else {
					$this->memory_writes[] = $data;
				}
				return 1;
			}
		};
	}
}
