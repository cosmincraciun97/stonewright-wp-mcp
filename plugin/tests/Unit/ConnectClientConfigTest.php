<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig
 */
final class ConnectClientConfigTest extends TestCase {

	public function test_clients_catalogue_has_required_shape(): void {
		$clients = ConnectClientConfig::clients();
		$this->assertIsArray( $clients );
		$this->assertGreaterThanOrEqual( 10, count( $clients ) );

		foreach ( $clients as $client ) {
			$this->assertArrayHasKey( 'slug', $client );
			$this->assertArrayHasKey( 'label', $client );
			$this->assertArrayHasKey( 'config_path', $client );
			$this->assertArrayHasKey( 'kind', $client );
			$this->assertArrayHasKey( 'notes', $client );
		}
	}

	public function test_catalogue_includes_known_clients(): void {
		$slugs = array_column( ConnectClientConfig::clients(), 'slug' );
		$this->assertContains( 'claude-code', $slugs );
		$this->assertContains( 'claude-desktop', $slugs );
		$this->assertContains( 'cursor', $slugs );
		$this->assertContains( 'vscode-copilot', $slugs );
	}

	public function test_endpoint_url_uses_rest_url(): void {
		$url = ConnectClientConfig::mcp_endpoint_url();
		$this->assertStringContainsString( 'mcp/stonewright', $url );
	}

	public function test_universal_snippet_structure(): void {
		$snippet = ConnectClientConfig::universal_snippet( 'admin', 'abcd 1234 efgh 5678' );
		$this->assertArrayHasKey( 'mcpServers', $snippet );
		$this->assertArrayHasKey( 'stonewright', $snippet['mcpServers'] );

		$server = $snippet['mcpServers']['stonewright'];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertContains( '-y', $server['args'] );
		// Must use @stonewright/companion, NOT @automattic/mcp-wordpress-remote
		$this->assertContains( '@stonewright/companion@latest', $server['args'] );
		$this->assertNotContains( '@automattic/mcp-wordpress-remote@latest', $server['args'] );
		$this->assertSame( 'admin', $server['env']['WP_API_USERNAME'] );
		$this->assertSame( 'abcd 1234 efgh 5678', $server['env']['WP_API_PASSWORD'] );
	}

	public function test_universal_snippet_password_placeholder_when_empty(): void {
		$snippet = ConnectClientConfig::universal_snippet( 'admin', '' );
		$this->assertSame(
			'<your-application-password>',
			$snippet['mcpServers']['stonewright']['env']['WP_API_PASSWORD']
		);
	}

	public function test_snippet_for_unknown_client_returns_wp_error(): void {
		$result = ConnectClientConfig::snippet_for( 'not-a-real-client', 'admin', 'pw' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'stonewright_unknown_client', $result->get_error_code() );
	}

	public function test_snippet_for_claude_code_returns_cli_command(): void {
		$result = ConnectClientConfig::snippet_for( 'claude-code', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'command', $result );
		$this->assertStringContainsString( 'claude mcp add stonewright', $result['command'] );
		// Must use @stonewright/companion, NOT @automattic/mcp-wordpress-remote
		$this->assertStringContainsString( '@stonewright/companion', $result['command'] );
		$this->assertStringNotContainsString( '@automattic/mcp-wordpress-remote', $result['command'] );
	}

	public function test_snippet_for_known_client_returns_universal_block(): void {
		$result = ConnectClientConfig::snippet_for( 'cursor', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'mcpServers', $result );
		$this->assertArrayHasKey( 'stonewright', $result['mcpServers'] );
	}

	public function test_snippet_for_vscode_copilot_uses_servers_key(): void {
		$result = ConnectClientConfig::snippet_for( 'vscode-copilot', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'servers', $result );
		$this->assertArrayNotHasKey( 'mcpServers', $result );
		$this->assertArrayHasKey( 'stonewright', $result['servers'] );
	}

	public function test_snippet_for_github_copilot_uses_servers_key(): void {
		$result = ConnectClientConfig::snippet_for( 'github-copilot', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'servers', $result );
	}

	public function test_snippet_for_zed_uses_context_servers_key(): void {
		$result = ConnectClientConfig::snippet_for( 'zed', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'context_servers', $result );
		$this->assertArrayHasKey( 'stonewright', $result['context_servers'] );
	}

	public function test_paste_to_agent_prompt_includes_endpoint_and_credentials(): void {
		$prompt = ConnectClientConfig::paste_to_agent_prompt( 'admin', 'pw1234' );
		$this->assertStringContainsString( 'admin', $prompt );
		$this->assertStringContainsString( 'pw1234', $prompt );
		$this->assertStringContainsString( 'mcp/stonewright', $prompt );
		$this->assertStringContainsString( 'mcpServers', $prompt );
		$this->assertStringContainsString( '@playwright/mcp@latest', $prompt );
		$this->assertStringContainsString( 'stonewright-context-bootstrap', $prompt );
	}

	public function test_playwright_mcp_snippet_is_separate_server(): void {
		$snippet = ConnectClientConfig::playwright_mcp_snippet();

		$this->assertSame( 'npx', $snippet['mcpServers']['playwright']['command'] );
		$this->assertSame( [ '@playwright/mcp@latest' ], $snippet['mcpServers']['playwright']['args'] );
	}
}
