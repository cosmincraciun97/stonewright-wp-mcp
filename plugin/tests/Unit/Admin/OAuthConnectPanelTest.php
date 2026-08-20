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

		foreach ( OAuthClientConfig::client_labels() as $label ) {
			self::assertStringContainsString( '>' . $label . '</button>', $html );
		}
		self::assertStringContainsString( 'stonewright-oauth', $html );
		self::assertStringContainsString( 'Change server name (optional)', $html );
		self::assertStringContainsString( 'Manage connected apps', $html );
		self::assertStringNotContainsString( 'Novamira', $html );
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

	public function test_chip_list_includes_codex_variants_without_bare_codex(): void {
		ob_start();
		OAuthConnectPanel::render(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'stonewright-example'
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( '>Codex in ChatGPT Desktop</button>', $html );
		self::assertStringContainsString( '>Codex CLI</button>', $html );
		self::assertDoesNotMatchRegularExpression( '/>Codex<\/button>/', $html );
		self::assertStringNotContainsString( 'data-sw-oauth-tab="codex"', $html );
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
		self::assertStringContainsString( 'ChatGPT Desktop can use this local', $html );
		self::assertStringContainsString( 'Use Claude Desktop or Claude Code', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="claude-desktop"', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="codex-cli"', $html );
		self::assertStringContainsString( 'data-sw-oauth-panel="antigravity-cli"', $html );
		self::assertStringContainsString( 'Copy install link', $html );
	}
}
