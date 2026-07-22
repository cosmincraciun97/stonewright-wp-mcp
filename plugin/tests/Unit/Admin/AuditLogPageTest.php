<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AuditLogPage;

/**
 * @covers \Stonewright\WpMcp\Admin\AuditLogPage
 */
final class AuditLogPageTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps'] = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_options']   = [];
		$_GET = [];
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options']   = [];
		$_GET = [];
	}

	public function test_render_outputs_filters_expandable_rows_and_semantic_badges(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			public function get_var( string $query = '' ): string|int|null {
				// Count queries and table existence probes.
				return 2;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [
					[
						'id'             => '12',
						'ability_name'   => 'stonewright/content-update',
						'user_id'        => '1',
						'result_status'  => 'ok',
						'sanitized_args' => '{"post_id":42}',
						'created_at'     => '2026-07-15 10:00:00',
					],
					[
						'id'             => '11',
						'ability_name'   => 'stonewright/content-delete',
						'user_id'        => '1',
						'result_status'  => 'error',
						'sanitized_args' => '{"post_id":7}',
						'created_at'     => '2026-07-14 09:00:00',
					],
				];
			}
		};

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-audit-page', $html );
		self::assertStringContainsString( 'Stonewright mutation', $html );
		self::assertStringContainsString( 'sw-audit-filters', $html );
		self::assertStringContainsString( 'value="blocked"', $html );
		self::assertStringContainsString( 'name="ability"', $html );
		self::assertStringContainsString( 'name="status"', $html );
		self::assertStringContainsString( 'name="user"', $html );
		self::assertStringContainsString( 'name="from"', $html );
		self::assertStringContainsString( 'name="to"', $html );
		self::assertStringContainsString( 'sw-badge', $html );
		self::assertStringContainsString( 'sw-badge--ok', $html );
		self::assertStringContainsString( 'sw-badge--error', $html );
		self::assertStringContainsString( 'sw-audit-row', $html );
		self::assertStringContainsString( '<details', $html );
		self::assertStringContainsString( 'post_id', $html );
		self::assertStringContainsString( 'method="get"', $html );
	}

	public function test_render_empty_state(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}

			public function get_var( string $query = '' ): string|int|null {
				return 0;
			}
		};

		ob_start();
		AuditLogPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-empty-state', $html );
		self::assertStringContainsString( 'No audit entries', $html );
	}
}
