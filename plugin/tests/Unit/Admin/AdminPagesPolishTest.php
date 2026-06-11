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
		$GLOBALS['stonewright_test_options'] = [];
		$GLOBALS['stonewright_test_transients'] = [];
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
		self::assertStringContainsString( 'stonewright-skills-grid', $html );
		self::assertStringContainsString( 'stonewright-skill-card', $html );
		self::assertStringContainsString( 'stonewright-guidance-grid', $html );
		self::assertStringContainsString( 'How skills reach agents', $html );
		self::assertStringContainsString( 'sw-badge--agentic', $html );
		self::assertStringContainsString( 'Auto-match from task descriptions', $html );
		self::assertStringContainsString( 'Show as a prompt or command', $html );
		self::assertStringContainsString( 'data-stonewright-skill-toggle', $html );
		self::assertStringContainsString( 'data-confirm="Delete this skill?"', $html );
		self::assertStringNotContainsString( '<style>', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringNotContainsString( 'ð', $html );
	}

	public function test_sandbox_page_uses_shared_shell_and_empty_state(): void {
		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'stonewright-sandbox-page', $html );
		self::assertStringContainsString( 'stonewright-empty-state', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-new-file-form"', $html );
	}

	public function test_memory_page_uses_shared_shell_and_managed_panels(): void {
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
		self::assertStringContainsString( 'stonewright-memory-page', $html );
		self::assertStringContainsString( 'stonewright-guidance-grid', $html );
		self::assertStringContainsString( 'What belongs here', $html );
		self::assertStringContainsString( 'stonewright-memory-note', $html );
		self::assertStringContainsString( 'stonewright-empty-state', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-new-memory"', $html );
		self::assertStringContainsString( 'data-stonewright-toggle-target="stonewright-knowledge-import"', $html );
	}

	public function test_status_page_uses_shared_shell_and_audit_empty_state(): void {
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
		StatusPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'stonewright-status-page', $html );
		self::assertStringContainsString( 'stonewright-status-grid', $html );
		self::assertStringContainsString( 'stonewright-empty-state', $html );
	}
}
