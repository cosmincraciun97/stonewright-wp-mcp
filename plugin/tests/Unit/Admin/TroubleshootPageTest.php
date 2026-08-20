<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\AdminShell;
use Stonewright\WpMcp\Admin\Pages\TroubleshootPage;

/**
 * @covers \Stonewright\WpMcp\Admin\Pages\TroubleshootPage
 * @covers \Stonewright\WpMcp\Admin\DiagnosticsPanel
 */
final class TroubleshootPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['stonewright_test_user_caps']       = [ 'manage_options' => true ];
		$GLOBALS['stonewright_test_current_user_id'] = 7;
		$GLOBALS['stonewright_test_options']         = [
			'stonewright_mode'          => 'development',
			'stonewright_enabled'       => true,
			'stonewright_companion_url' => 'http://127.0.0.1:8765',
		];
		$GLOBALS['stonewright_test_submenu_pages'] = [];
		$_GET  = [];
		$_POST = [];
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_user_caps']       = [];
		$GLOBALS['stonewright_test_current_user_id'] = 0;
		$GLOBALS['stonewright_test_options']         = [];
		$GLOBALS['stonewright_test_submenu_pages']   = [];
		$_GET  = [];
		$_POST = [];
	}

	public function test_slug_lives_in_connect_group(): void {
		self::assertSame( 'stonewright-troubleshoot', TroubleshootPage::SLUG );
		self::assertSame( 'manage_options', TroubleshootPage::CAPABILITY );
		self::assertContains( TroubleshootPage::SLUG, array_keys( AdminShell::pages() ) );

		$connect = [];
		foreach ( AdminShell::menu_groups() as $group ) {
			if ( 'connect' === $group['id'] ) {
				$connect = $group['pages'];
			}
		}

		self::assertSame( 'Setup', $connect['stonewright'] ?? null );
		self::assertSame( 'Troubleshoot', $connect[ TroubleshootPage::SLUG ] ?? null );
		self::assertCount( 2, $connect );
	}

	public function test_register_adds_submenu_under_stonewright(): void {
		TroubleshootPage::register();
		TroubleshootPage::add_submenu();

		$registered = $GLOBALS['stonewright_test_submenu_pages'][ TroubleshootPage::SLUG ] ?? null;
		self::assertIsArray( $registered );
		self::assertSame( 'stonewright', $registered['parent'] );
		self::assertSame( 'manage_options', $registered['capability'] );
	}

	public function test_admin_bootstrap_registers_and_enqueues_the_page(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 3 ) . '/includes/Admin/AdminBootstrap.php' );

		self::assertStringContainsString( 'TroubleshootPage::register()', $bootstrap );
		self::assertStringContainsString( "'stonewright-troubleshoot' => 'setup.css'", $bootstrap );
		self::assertStringContainsString( "'stonewright-troubleshoot' === \$page", $bootstrap );
	}

	public function test_render_refuses_users_without_manage_options(): void {
		$GLOBALS['stonewright_test_user_caps'] = [];

		$this->expectException( \RuntimeException::class );
		TroubleshootPage::render();
	}

	public function test_render_shows_shared_diagnostics_panel(): void {
		ob_start();
		TroubleshootPage::render();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'sw-troubleshoot-page', $html );
		self::assertStringContainsString( 'stonewright-admin-shell', $html );
		self::assertStringContainsString( 'Troubleshoot', $html );
		self::assertStringContainsString( 'Connection checks', $html );
		self::assertStringContainsString( 'Run these checks when an AI client cannot connect. They probe this site the way a client does and point at what to fix.', $html );
		self::assertStringContainsString( 'How do you connect?', $html );
		self::assertStringContainsString( 'Not sure (check both)', $html );
		self::assertStringContainsString( 'Remote Streamable HTTP / OAuth', $html );
		self::assertStringContainsString( 'Local companion (stdio)', $html );
		self::assertStringContainsString( 'value="both"', $html );
		self::assertStringContainsString( 'value="http"', $html );
		self::assertStringContainsString( 'value="stdio"', $html );
		self::assertStringContainsString( 'What do you see in your AI client?', $html );
		self::assertStringContainsString( 'sw-diag-card', $html );
		self::assertStringContainsString( 'Copy report for support', $html );
		self::assertStringContainsString( 'data-stonewright-copy="stonewright-diagnostics-copy"', $html );
		self::assertStringContainsString( 'data-stonewright-run-diagnostics', $html );
		self::assertStringContainsString( 'value="stonewright_run_diagnostics"', $html );
		self::assertStringContainsString( 'admin-post.php', $html );
		self::assertStringContainsString( 'name="stonewright_diagnostics_return" value="stonewright-troubleshoot"', $html );
		self::assertStringContainsString( 'Run diagnostics', $html );
		self::assertStringContainsString( 'Not run yet — click Run diagnostics', $html );
		self::assertStringNotContainsString( 'Novamira', $html );
	}

	public function test_last_report_renders_bot_filter_ticket_copy_control(): void {
		$_GET['stonewright_diagnostics'] = '1';
		$GLOBALS['stonewright_test_options']['stonewright_diagnostics_last'] = [
			'ready'    => true,
			'mode'     => 'http',
			'versions' => [
				'plugin'             => '0.0.0-test',
				'companion_contract' => '1.0.0',
			],
			'checks'   => [
				[
					'id'     => 'bot_filter',
					'status' => 'warn',
					'label'  => 'Bot / WAF user-agent filter',
					'detail' => 'User-Agent python-httpx was blocked with HTTP 403.',
					'ticket' => "Please allow AI HTTP clients to reach https://example.test/wp-json/mcp/stonewright\nUser-Agent python-httpx",
				],
			],
		];

		ob_start();
		TroubleshootPage::render();
		$html = (string) ob_get_clean();

		self::assertStringNotContainsString( 'Not run yet — click Run diagnostics', $html );
		self::assertStringContainsString( 'Copy ticket', $html );
		self::assertStringContainsString( 'example.test', $html );
		self::assertStringContainsString( 'data-stonewright-copy="stonewright-diag-ticket-bot_filter"', $html );
		self::assertStringContainsString( 'Press Ctrl/Cmd+C', $html );
		self::assertStringContainsString( 'data-stonewright-copy-modal', $html );
		self::assertStringContainsString( '1 Warnings', $html );
	}
}
