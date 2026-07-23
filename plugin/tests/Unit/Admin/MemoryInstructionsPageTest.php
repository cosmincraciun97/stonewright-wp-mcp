<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;

/**
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 */
final class MemoryInstructionsPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$GLOBALS['stonewright_test_options'] = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options'] = [];
	}

	public function test_render_includes_memory_edit_controls_and_bundle_import_export(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows(
			[
				[
					'id'          => '9',
					'type'        => 'feedback',
					'scope'       => 'nzeb-frontend',
					'memory_key'  => 'no-html-widgets',
					'name'        => 'No Elementor HTML widgets by default',
					'value_json'  => wp_json_encode( 'Use native Elementor widgets first.' ),
					'confidence'  => '1.0000',
					'created_at'  => '2026-05-24 00:00:00',
					'updated_at'  => '2026-05-24 00:00:00',
				],
			],
			true
		);

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-memory-edit-9', $html );
		self::assertStringContainsString( 'stonewright_memory_update', $html );
		self::assertStringContainsString( 'Export JSON', $html );
		self::assertStringContainsString( 'Import JSON', $html );
		self::assertStringContainsString( 'Use native Elementor widgets first.', $html );
		self::assertStringContainsString( 'Verified Repairs', $html );
		self::assertStringContainsString( 'Unresolved Incidents', $html );
		self::assertStringContainsString( 'Audit Feedback', $html );
		self::assertStringContainsString( 'plugin-site', $html );
		self::assertStringContainsString( 'Last retrieved:', $html );
		self::assertStringContainsString( 'Direct-local receipts', $html );
		self::assertStringContainsString( 'stonewright_memory_migrate_feedback', $html );
		self::assertStringNotContainsString( 'memory table is missing or outdated', $html );
		self::assertMatchesRegularExpression(
			'/<button\b(?=[^>]*\btype="submit")(?=[^>]*\bdata-confirm="Delete this memory\?")/i',
			$html
		);
	}

	public function test_render_surfaces_schema_health_notice_when_table_broken(): void {
		$GLOBALS['wpdb'] = $this->make_wpdb_with_rows( [], false );

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'notice notice-error', $html );
		self::assertStringContainsString( 'memory table is missing or outdated', $html );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function make_wpdb_with_rows( array $rows, bool $schema_ok ): object {
		return new class( $rows, $schema_ok ) {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			private array $rows;
			private bool $schema_ok;

			/** @param array<int, array<string, mixed>> $rows */
			public function __construct( array $rows, bool $schema_ok ) {
				$this->rows      = $rows;
				$this->schema_ok = $schema_ok;
			}

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return $this->rows;
			}

			/** @return array<int, string> */
			public function get_col( string $query, int $x = 0 ): array {
				if ( ! $this->schema_ok ) {
					return [];
				}
				return [
					'id',
					'scope',
					'type',
					'name',
					'memory_key',
					'value_json',
					'confidence',
					'topic',
					'version_fingerprint',
					'expires_at',
					'status',
					'precedence',
					'created_by',
					'created_at',
					'updated_at',
					'last_retrieved_at',
				];
			}
		};
	}
}
