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
				'claude-code',
				'claude-desktop',
				'claude-ai',
				'chatgpt',
				'codex',
				'antigravity',
				'cursor',
				'vscode',
				'github-copilot',
				'windsurf',
				'cline',
				'gemini-cli',
				'roo-code',
				'amazon-q',
				'zed',
				'kilo-code',
				'opencode',
			],
			array_keys( OAuthClientConfig::client_labels() )
		);
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
		self::assertSame( $url, $configs['chatgpt']['steps'][2]['copy'] );
		self::assertStringStartsWith( 'cursor://anysphere.cursor-deeplink/', $configs['cursor']['deeplink'] );
		self::assertStringContainsString( '"type": "http"', $configs['vscode']['code'] );
		self::assertStringContainsString( 'mcp-remote', $configs['antigravity']['code'] );
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
