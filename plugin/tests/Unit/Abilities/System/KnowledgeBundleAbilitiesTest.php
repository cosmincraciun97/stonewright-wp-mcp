<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Abilities\System;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Abilities\System\KnowledgeExport;
use Stonewright\WpMcp\Abilities\System\KnowledgeImport;

/**
 * @covers \Stonewright\WpMcp\Abilities\System\KnowledgeExport
 * @covers \Stonewright\WpMcp\Abilities\System\KnowledgeImport
 */
final class KnowledgeBundleAbilitiesTest extends TestCase {

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

	public function test_export_ability_returns_bundle(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();

		$result = ( new KnowledgeExport() )->execute( [] );

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'stonewright-knowledge-bundle', $result['bundle']['format'] );
	}

	public function test_import_ability_applies_bundle(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb();

		$result = ( new KnowledgeImport() )->execute(
			[
				'bundle' => [
					'format'       => 'stonewright-knowledge-bundle',
					'version'      => 1,
					'instructions' => [
						'enabled' => true,
						'text'    => 'Never use Elementor HTML widgets by default.',
					],
				],
			]
		);

		self::assertIsArray( $result );
		self::assertTrue( $result['ok'] );
		self::assertSame( 'Never use Elementor HTML widgets by default.', get_option( 'stonewright_custom_instructions', '' ) );
	}

	private function make_wpdb(): object {
		return new class() {
			public string $prefix = 'wp_';
			public int $insert_id = 50;

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
				return [];
			}

			public function get_row( string $query, string $output = 'OBJECT' ): ?array {
				return null;
			}

			/** @param array<string, mixed> $data */
			public function insert( string $table, array $data, array $format = [] ): int {
				++$this->insert_id;
				return 1;
			}

			/** @param array<string, mixed> $data @param array<string, mixed> $where */
			public function update( string $table, array $data, array $where, array $format = [], array $where_format = [] ): int {
				return 1;
			}
		};
	}
}
