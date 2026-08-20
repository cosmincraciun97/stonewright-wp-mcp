<?php
/**
 * OAuth client configuration contract tests.
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\OAuthClientConfig;

final class OAuthClientConfigTest extends TestCase {

	public function test_exposes_exact_client_order(): void {
		self::assertSame(
			[
				'chatgpt-desktop',
				'chatgpt',
				'claude-ai',
				'claude-desktop',
				'claude-code',
				'windsurf',
				'codex-cli',
				'codex',
				'antigravity',
				'antigravity-cli',
				'cursor',
				'vscode',
				'vscode-copilot',
				'github-copilot',
				'cline',
				'gemini-cli',
				'roo-code',
				'amazon-q',
				'zed',
				'kilo-code',
				'opencode',
				'generic-mcp',
			],
			array_keys( OAuthClientConfig::client_labels() )
		);
		self::assertSame( 'Codex in ChatGPT Desktop', OAuthClientConfig::client_labels()['chatgpt-desktop'] );
		self::assertSame( 'Windsurf', OAuthClientConfig::client_labels()['windsurf'] );
		self::assertSame( 'Codex CLI', OAuthClientConfig::client_labels()['codex-cli'] );
	}

	public function test_public_configs_match_native_and_bridge_contracts(): void {
		$url     = 'https://example.test/wp-json/mcp/stonewright-oauth';
		$configs = OAuthClientConfig::public_configs( $url, 'stonewright-example' );

		self::assertSame(
			'claude mcp add stonewright-example --transport http ' . $url,
			$configs['claude-code']['code']
		);
		self::assertStringContainsString( '[mcp_servers.stonewright-example]', $configs['codex']['code'] );
		self::assertStringContainsString( 'url = "' . $url . '"', $configs['codex']['code'] );
		self::assertSame( $configs['codex-cli']['code'], $configs['codex']['code'] );
		self::assertSame( $url, $configs['chatgpt']['steps'][2]['copy'] );
		self::assertStringStartsWith( 'cursor://anysphere.cursor-deeplink/', $configs['cursor']['deeplink'] );
		self::assertStringContainsString( '"type": "http"', $configs['vscode']['code'] );
		self::assertSame( $configs['vscode']['code'], $configs['vscode-copilot']['code'] );
		self::assertStringContainsString( 'mcp-remote', $configs['antigravity']['code'] );
		self::assertStringContainsString( '"url": "' . $url . '"', $configs['chatgpt-desktop']['code'] );
		self::assertStringContainsString( '"serverUrl": "' . $url . '"', $configs['windsurf']['code'] );
		self::assertStringContainsString( '"httpUrl": "' . $url . '"', $configs['gemini-cli']['code'] );
	}

	/**
	 * Cursor install links encode the inner server object (not an mcpServers wrapper)
	 * as base64url: alphabet A-Za-z0-9-_, no + / = padding.
	 *
	 * Working format (manual Cursor install is out of scope for this suite):
	 * cursor://anysphere.cursor-deeplink/mcp/install?name=NAME&config=BASE64URL_JSON
	 *
	 * JSON matches JSON.stringify: compact, unescaped slashes. Standard base64 of
	 * many WordPress MCP URLs emits + / =; those must be mapped to - _ and stripped.
	 */
	public function test_cursor_deeplink_uses_exact_base64url_contract(): void {
		$url     = 'https://example.test/wp-json/mcp/stonewright-oauth';
		$configs = OAuthClientConfig::public_configs( $url, 'stonewright-example' );

		$expected = 'cursor://anysphere.cursor-deeplink/mcp/install?name=stonewright-example&config=eyJ1cmwiOiJodHRwczovL2V4YW1wbGUudGVzdC93cC1qc29uL21jcC9zdG9uZXdyaWdodC1vYXV0aCJ9';

		self::assertSame( $expected, $configs['cursor']['deeplink'] );
		$query = parse_url( $configs['cursor']['deeplink'], PHP_URL_QUERY );
		self::assertIsString( $query );
		parse_str( $query, $params );
		self::assertSame( 'stonewright-example', $params['name'] ?? '' );
		self::assertDoesNotMatchRegularExpression( '/[+\\/=]/', (string) ( $params['config'] ?? '' ) );
	}

	public function test_connector_link_contains_only_name_and_url(): void {
		$link = OAuthClientConfig::connector_install_link(
			'https://example.test/wp-json/mcp/stonewright-oauth',
			'Stonewright - Example'
		);
		self::assertStringContainsString( 'connectorName=Stonewright%20-%20Example', $link );
		self::assertStringContainsString( 'connectorUrl=https%3A%2F%2Fexample.test', $link );
		self::assertStringNotContainsString( 'secret', strtolower( $link ) );
	}
}
