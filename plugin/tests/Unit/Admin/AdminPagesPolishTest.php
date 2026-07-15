<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\MemoryInstructionsPage;
use Stonewright\WpMcp\Admin\SandboxPage;
use Stonewright\WpMcp\Admin\SkillsPage;
use Stonewright\WpMcp\Admin\Pages\StatusPage;

/**
 * @covers \Stonewright\WpMcp\Admin\SkillsPage
 * @covers \Stonewright\WpMcp\Admin\SandboxPage
 * @covers \Stonewright\WpMcp\Admin\MemoryInstructionsPage
 * @covers \Stonewright\WpMcp\Admin\Pages\StatusPage
 */
final class AdminPagesPolishTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['stonewright_test_user_caps']['manage_options'] = true;
		$GLOBALS['stonewright_test_options'] = [
			'stonewright_mode' => 'development',
		];
		$GLOBALS['stonewright_test_transients'] = [];
		$this->empty_sandbox_test_dirs();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		$GLOBALS['stonewright_test_user_caps'] = [];
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
		$_GET = [];
		$this->empty_sandbox_test_dirs();
	}

	private function empty_sandbox_test_dirs(): void {
		$content_dir = realpath( WP_CONTENT_DIR );

		if ( false === $content_dir ) {
			return;
		}

		foreach ( [ 'stonewright-sandbox', 'mu-plugins' ] as $relative_dir ) {
			$dir = realpath( WP_CONTENT_DIR . '/' . $relative_dir );

			if ( false === $dir || ! str_starts_with( $dir, $content_dir . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			foreach ( glob( $dir . '/*.php' ) ?: [] as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		}
	}

	public function test_skills_page_uses_shared_shell_and_external_admin_controls(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function get_var( string $query = '' ): ?string {
				return 'wp_stonewright_skills';
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [
					[
						'id'             => 7,
						'slug'           => 'elementor-native',
						'title'          => 'Elementor Native',
						'description'    => 'Use native Elementor widgets before custom code.',
						'content'        => '# Elementor Native',
						'enabled'        => 1,
						'enable_agentic' => 1,
						'enable_prompt'  => 1,
						'source'         => 'user',
					],
				];
			}
		};

		ob_start();
		SkillsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'sw-skills-grid', $html );
		self::assertStringContainsString( 'sw-skill-card', $html );
		self::assertStringContainsString( 'sw-badge', $html );
		self::assertStringContainsString( 'sw-badge--agentic', $html );
		self::assertStringContainsString( 'sw-actions', $html );
		self::assertStringContainsString( 'Auto-match from task descriptions', $html );
		self::assertStringContainsString( 'Show as a prompt or command', $html );
		self::assertStringContainsString( 'data-stonewright-skill-toggle', $html );
		self::assertStringContainsString( 'data-confirm="Delete this skill?"', $html );
		self::assertStringContainsString( 'name="title"', $html );
		self::assertStringContainsString( 'name="slug"', $html );
		self::assertStringContainsString( 'name="content"', $html );
		self::assertStringContainsString( 'name="enabled"', $html );
		self::assertStringContainsString( 'name="enable_agentic"', $html );
		self::assertStringContainsString( 'name="enable_prompt"', $html );
		self::assertStringNotContainsString( '<style>', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( 'ð', $html );
	}

	public function test_sandbox_page_uses_shared_shell_and_sw_tabs(): void {
		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'stonewright-sandbox-page', $html );
		self::assertStringContainsString( 'sw-tabs', $html );
		self::assertStringContainsString( 'sw-tabs__link', $html );
		self::assertStringContainsString( 'sw-tabs__link is-active', $html );
		self::assertStringContainsString( 'stonewright-empty-state', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-new-file-form"', $html );
	}

	public function test_memory_page_uses_callout_cards_and_actions_layout(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				return [];
			}
		};

		ob_start();
		MemoryInstructionsPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'sw-memory-page', $html );
		self::assertStringContainsString( 'sw-callout', $html );
		self::assertStringContainsString( 'sw-card', $html );
		self::assertStringContainsString( 'sw-actions', $html );
		self::assertStringContainsString( 'stonewright_custom_instructions', $html );
		self::assertStringContainsString( 'stonewright_custom_instructions_enabled', $html );
		self::assertStringContainsString( 'stonewright_memory_enabled', $html );
		self::assertStringContainsString( 'stonewright-empty-state', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-new-memory"', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-knowledge-import"', $html );
	}

	public function test_status_page_becomes_dashboard_with_stat_cards_and_feed(): void {
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';

			public function prepare( string $query, mixed ...$args ): string {
				return $query;
			}

			/** @return array<int, array<string, mixed>> */
			public function get_results( string $query, string $output = 'OBJECT' ): array {
				if ( str_contains( $query, 'memory' ) || str_contains( $query, 'skills' ) ) {
					return [];
				}
				if ( str_contains( $query, 'GROUP BY' ) || str_contains( $query, 'DATE(' ) ) {
					return [
						[ 'day' => '2026-07-14', 'total' => '3' ],
						[ 'day' => '2026-07-15', 'total' => '5' ],
					];
				}

				return [
					[
						'id'            => '3',
						'ability_name'  => 'stonewright/ping',
						'user_id'       => '1',
						'result_status' => 'ok',
						'created_at'    => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
					],
				];
			}

			public function get_var( string $query = '' ): string|int|null {
				if ( str_contains( $query, 'skills' ) || str_contains( $query, 'memory' ) ) {
					return null;
				}
				return '1';
			}
		};

		ob_start();
		StatusPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'sw-dashboard-page', $html );
		self::assertStringContainsString( 'sw-stat-grid', $html );
		self::assertStringContainsString( 'sw-stat-card', $html );
		self::assertStringContainsString( 'sw-audit-feed', $html );
		self::assertStringContainsString( 'sw-sparkline', $html );
		self::assertStringContainsString( 'Dashboard', $html );
	}
}
