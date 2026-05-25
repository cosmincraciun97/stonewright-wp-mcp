<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\Memory\LearningRecord;

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

	public function test_records_correction_to_persistent_memory_and_skill(): void {
		$result = ( new LearningRecord() )->execute(
			[
				'scope'      => 'elementor',
				'topic'      => 'Elementor HTML widget',
				'correction' => 'Do not use HTML widgets for Elementor v3 design implementations.',
				'lesson'     => 'Use native Elementor widgets and configure their Content, Style, and Advanced controls.',
			]
		);

		self::assertSame( true, $result['ok'] );
		self::assertSame( 'learning-elementor-html-widget', $result['memory_key'] );
		self::assertSame( 'learned-elementor-html-widget', $result['skill_slug'] );

		$memory_insert = $GLOBALS['wpdb']->inserts[0] ?? [];
		self::assertStringEndsWith( 'stonewright_memory', (string) ( $memory_insert['table'] ?? '' ) );
		self::assertSame( 'feedback', $memory_insert['data']['type'] );
		self::assertSame( 'elementor', $memory_insert['data']['scope'] );
		self::assertSame( 'learning-elementor-html-widget', $memory_insert['data']['memory_key'] );
		self::assertStringContainsString( 'Do not use HTML widgets', $memory_insert['data']['value_json'] );

		$skill_insert = $GLOBALS['wpdb']->inserts[1] ?? [];
		self::assertStringEndsWith( 'stonewright_skills', (string) ( $skill_insert['table'] ?? '' ) );
		self::assertSame( 'learned-elementor-html-widget', $skill_insert['data']['slug'] );
		self::assertStringContainsString( 'Use when working on Elementor HTML widget', $skill_insert['data']['description'] );
		self::assertStringContainsString( 'Do not use HTML widgets', $skill_insert['data']['content'] );
		self::assertSame( 1, $skill_insert['data']['enabled'] );
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 100;

			/** @var array<int, array{table:string,data:array<string,mixed>}> */
			public array $inserts = [];

			public function get_var( string $query ): mixed {
				if ( str_contains( $query, 'SELECT id FROM' ) ) {
					return null;
				}
				return 'table_exists';
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

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

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}
		};
	}
}
