<?php
/**
 * OAuth connection panel rendering tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\OAuthClientConfig;
use Stonewright\WpMcp\Admin\OAuthConnectPanel;

final class OAuthConnectPanelTest extends TestCase {

	public function test_renders_all_clients_and_stonewright_oauth_route(): void {
		ob_start();
		OAuthConnectPanel::render(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright-example'
		);
		$html = (string) ob_get_clean();

		foreach ( \Stonewright\WpMcp\Admin\ClientCatalog::all() as $client ) {
			self::assertStringContainsString( (string) $client['label'], $html );
		}
		self::assertStringContainsString( 'Grok Build / CLI', $html );
		self::assertStringContainsString( 'stonewright-oauth', $html );
		self::assertStringContainsString( 'Change server name (optional)', $html );
		self::assertStringContainsString( 'Manage connected apps', $html );
		self::assertStringNotContainsString( 'Novamira', $html );
		self::assertSame( 0, substr_count( $html, 'role="tablist"' ) );
		self::assertStringNotContainsString( 'data-sw-oauth-tab=', $html );
	}

	public function test_cursor_deeplink_renders_as_clickable_anchor(): void {
		$GLOBALS['stonewright_test_home_url'] = 'https://example.com/';

		ob_start();
		OAuthConnectPanel::render(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright-example'
		);
		$html = (string) ob_get_clean();

		unset( $GLOBALS['stonewright_test_home_url'] );

		self::assertMatchesRegularExpression(
			'/<a class="button button-primary" href="cursor:\\/\\/anysphere\\.cursor-deeplink\\/mcp\\/install\\?[^"]+">/',
			$html
		);
		self::assertStringContainsString( 'One-click install in Cursor', $html );
		self::assertStringContainsString( 'Copy install link', $html );
	}

	public function test_chip_list_uses_the_official_codex_entry_only(): void {
		ob_start();
		OAuthConnectPanel::render(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright-example'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Codex CLI', $html );
		self::assertStringNotContainsString( 'Codex in ChatGPT Desktop', $html );
		self::assertStringNotContainsString( 'data-sw-oauth-tab="codex"', $html );
		self::assertSame( 0, substr_count( $html, 'role="tablist"' ) );
	}

	public function test_local_host_renders_actionable_client_panels(): void {
		$GLOBALS['stonewright_test_home_url'] = 'http://site-a.local/';

		ob_start();
		OAuthConnectPanel::render(
			'http://site-a.local/wp-json/mcp/stonewright-oauth',
			'stonewright-site-a'
		);
		$html = (string) ob_get_clean();

		unset( $GLOBALS['stonewright_test_home_url'] );

		self::assertStringContainsString( 'sw-oauth-client-panel is-active', $html );
		self::assertStringContainsString( 'mcp-remote', $html );
		self::assertStringContainsString( 'official Codex configuration', $html );
		self::assertStringContainsString( 'Use Claude Desktop or Claude Code', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="claude-desktop"', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="codex-cli"', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="antigravity-cli"', $html );
		self::assertStringContainsString( 'Copy install link', $html );
	}

	public function test_name_change_script_rewrites_templates_document_wide(): void {
		ob_start();
		OAuthConnectPanel::render_script();
		$script = (string) ob_get_clean();

		self::assertStringContainsString( "document.querySelectorAll('[data-sw-oauth-template]')", $script );
		self::assertStringNotContainsString( "root.querySelectorAll('[data-sw-oauth-template]')", $script );
	}

	public function test_grok_oauth_instructions_include_native_toml_mcps_and_doctor(): void {
		ob_start();
		OAuthConnectPanel::render_client(
			'grok-build',
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright'
		);
		$html = html_entity_decode( (string) ob_get_clean(), ENT_QUOTES | ENT_HTML5 );

		self::assertStringContainsString( '[mcp_servers.stonewright]', $html );
		self::assertStringContainsString( 'url = "https://example.test/wp-json/mcp/stonewright-oauth"', $html );
		self::assertStringContainsString( 'enabled = true', $html );
		self::assertStringContainsString( 'grok mcp doctor stonewright', $html );
		self::assertStringContainsString( '/mcps', $html );
		self::assertStringContainsString( 'authenticate', strtolower( $html ) );
		self::assertStringContainsString( 'stonewright-task-start', $html );
		self::assertSame( 0, substr_count( $html, 'role="tablist"' ) );
		self::assertStringNotContainsString( 'Novamira', $html );
		self::assertStringNotContainsString( 'your-wp-username', $html );
		self::assertStringNotContainsString( '<your-application-password>', $html );
	}
}
