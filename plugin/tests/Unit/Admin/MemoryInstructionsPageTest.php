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
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [
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
				];
			}
		};

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-memory-edit-9', $html );
		self::assertStringContainsString( 'stonewright_memory_update', $html );
		self::assertStringContainsString( 'Export JSON', $html );
		self::assertStringContainsString( 'Import JSON', $html );
		self::assertStringContainsString( 'Use native Elementor widgets first.', $html );
		self::assertMatchesRegularExpression(
			'/<button\b(?=[^>]*\btype="submit")(?=[^>]*\bdata-confirm="Delete this memory\?")/i',
			$html
		);
	}
}
