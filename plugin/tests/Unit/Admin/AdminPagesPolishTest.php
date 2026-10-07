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
			public $prefix = 'wp_';

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
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Skills</h1>', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ), 'The shell prints the one h1.' );
		self::assertStringNotContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'sw-skills-tabs', $html );
		self::assertStringContainsString( 'sw-skills-panel', $html );
		self::assertStringContainsString( 'sw-actions', $html );

		// The catalog, import review, and trash are the script's job now, so the
		// page ships a shell it can fill instead of a card and a form per skill.
		self::assertStringNotContainsString( 'sw-skill-card', $html );
		self::assertStringNotContainsString( 'data-stonewright-skill-toggle', $html );
		self::assertStringNotContainsString( 'data-confirm', $html );

		// The editor keeps a write path that works without JavaScript.
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

	public function test_sandbox_page_uses_shared_shell_and_the_custom_code_tab_bar(): void {
		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Custom code</h1>', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringNotContainsString( 'stonewright-page-header', $html );
		self::assertStringContainsString( 'class="sw-code"', $html );
		// One tab bar: the hub's. The page no longer prints a second row of tabs.
		self::assertStringContainsString( '<nav aria-label="Custom code sections">', $html );
		self::assertStringNotContainsString( 'sw-tabs', $html );
		self::assertMatchesRegularExpression( '/<a class="sw-ui-hubnav__link" href="[^"]*page=stonewright-sandbox&tab=drafts" aria-current="page">Drafts<\/a>/', $html );
		foreach ( [ 'tab=library', 'tab=mu-plugins', 'tab=crash-recovery', 'page=stonewright-custom-code-approval' ] as $link ) {
			self::assertStringContainsString( $link, $html, $link );
		}
		self::assertStringContainsString( 'sw-ui-empty--first-run', $html );
		self::assertStringContainsString( 'new=1', $html );
		self::assertStringNotContainsString( 'tab=audit', $html );
	}

	public function test_legacy_sandbox_audit_link_points_to_the_dedicated_readable_page(): void {
		$_GET['tab'] = 'audit';

		ob_start();
		SandboxPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Audit Log moved', $html );
		self::assertStringContainsString( 'page=stonewright-audit-log', $html );
		self::assertStringNotContainsString( 'sw-audit-table', $html );
	}

	public function test_memory_page_uses_callout_cards_and_actions_layout(): void {
		$GLOBALS['wpdb'] = new class() {
			public $prefix = 'wp_';

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
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Memory &amp; instructions</h1>', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringNotContainsString( 'stonewright-page-header', $html );
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

	/** A database double that serves the Dashboard one audit row, a two day sparkline and no skills or memory. */
	private static function dashboard_wpdb(): object {
		return new class() {
			public $prefix = 'wp_';

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
	}

	/** The Overview stat that carries this label, as plain text. */
	private static function stat( string $html, string $label ): string {
		self::assertSame( 1, preg_match( '#<div class="sw-ui-stat"><span class="sw-ui-stat__label">' . preg_quote( $label, '#' ) . '</span>.*?</div>#s', $html, $stat ), 'The Overview has a ' . $label . ' stat.' );

		return (string) preg_replace( '/\s+/', ' ', trim( strip_tags( $stat[0] ) ) );
	}

	/** @dataProvider companion_option_states */
	public function test_the_companion_stat_shows_a_state_not_the_raw_option( mixed $option, string $expected_state, string $expected_detail ): void {
		$GLOBALS['wpdb'] = self::dashboard_wpdb();
		if ( null === $option ) {
			unset( $GLOBALS['stonewright_test_options']['stonewright_companion_url'] );
		} else {
			$GLOBALS['stonewright_test_options']['stonewright_companion_url'] = $option;
		}

		ob_start();
		StatusPage::render();
		$html = (string) ob_get_clean();

		$tile = self::stat( $html, 'Companion' );
		self::assertStringContainsString( $expected_state, $tile );
		self::assertStringContainsString( $expected_detail, $tile );
		self::assertStringNotContainsString( '<code></code>', $html, 'An empty option must not leave an empty value chip.' );
		self::assertStringNotContainsString( 'secret', $html, 'Credentials in a configured URL are never shown.' );
		self::assertStringNotContainsString( '/private/path', $html, 'Only the host and port of a configured URL are shown.' );
	}

	/** @return array<string, array{0: mixed, 1: string, 2: string}> */
	public static function companion_option_states(): array {
		return [
			'empty option'           => [ '', 'Not used', 'No bridge URL set' ],
			'blank option'           => [ '   ', 'Not used', 'No bridge URL set' ],
			'configured bridge'      => [ 'http://127.0.0.1:8765', 'Configured', '127.0.0.1:8765' ],
			'configured with extras' => [ 'https://user:secret@bridge.example.test:9443/private/path?token=x', 'Configured', 'bridge.example.test:9443' ],
		];
	}

	public function test_status_page_is_the_overview_inside_the_shell(): void {
		$GLOBALS['wpdb'] = self::dashboard_wpdb();

		ob_start();
		StatusPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( '<h1 class="sw-ui-page-title">Overview</h1>', $html );
		self::assertSame( 1, substr_count( $html, '<h1' ) );
		self::assertStringContainsString( 'class="sw-ui-stats"', $html );
		self::assertStringContainsString( 'Needs attention', $html );
		self::assertStringContainsString( 'Recent activity', $html );
		self::assertStringContainsString( 'stonewright/ping', $html );
		self::assertStringContainsString( 'role="img" aria-label="Changes over the last 14 days:', $html );
		self::assertStringNotContainsString( 'sw-dashboard-page', $html );
		self::assertStringNotContainsString( 'sw-stat-card', $html );
		self::assertStringNotContainsString( 'Dashboard', $html );
	}
}
