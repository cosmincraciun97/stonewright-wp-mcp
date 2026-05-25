<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Knowledge;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Knowledge\KnowledgeBundle;

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
					'scope'       => 'nzeb-frontend',
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
					'id'          => '4',
					'slug'        => 'elementor-native-first',
					'title'       => 'Elementor native widgets first',
					'description' => 'Use for Design reference to Elementor builds.',
					'content'     => '# Rule',
					'enabled'     => '1',
					'source'      => 'user',
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
							'scope'       => 'nzeb-frontend',
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
							'slug'        => 'design-elementor-quality',
							'title'       => 'Design reference Elementor quality',
							'description' => 'Use for page rebuilds.',
							'content'     => '# Steps',
							'enabled'     => true,
						],
					],
				],
			]
		);

		self::assertSame( 'Custom CSS requires explicit user approval.', get_option( 'stonewright_custom_instructions', '' ) );
		self::assertTrue( (bool) get_option( 'stonewright_memory_enabled', false ) );
		self::assertSame( 1, $result['memory_imported'] );
		self::assertSame( 1, $result['skills_imported'] );
		self::assertNotEmpty( $GLOBALS['wpdb']->memory_writes );
		self::assertNotEmpty( $GLOBALS['wpdb']->skill_writes );
	}

	/**
	 * @param array<int, array<string, mixed>> $memory_rows
	 * @param array<int, array<string, mixed>> $skill_rows
	 */
	private function make_wpdb( array $memory_rows, array $skill_rows ): object {
		return new class( $memory_rows, $skill_rows ) {
			public string $prefix = 'wp_';
			public int $insert_id = 100;
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
