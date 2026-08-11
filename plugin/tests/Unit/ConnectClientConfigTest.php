<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stonewright\WpMcp\Admin\ConnectClientConfig;

/**
 * @covers \Stonewright\WpMcp\Admin\ConnectClientConfig
 */
final class ConnectClientConfigTest extends TestCase {
	private const SERVER_NAME = 'stonewright-example-test';

	protected function setUp(): void {
		$GLOBALS['stonewright_test_options']['stonewright_mcp_surface'] = 'essential';
	}

	protected function tearDown(): void {
		$GLOBALS['stonewright_test_options'] = [];
		unset( $GLOBALS['stonewright_test_site_url'] );
	}

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
		$this->assertContains( 'codex', $slugs );
		$this->assertContains( 'cursor', $slugs );
		$this->assertContains( 'vscode-copilot', $slugs );
	}

	public function test_all_supported_client_notes_require_live_stonewright_mcp_without_bypasses(): void {
		foreach ( ConnectClientConfig::clients() as $client ) {
			$this->assertStringContainsString( 'Stonewright MCP must be visible', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'stonewright-context-bootstrap', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no private config inspection', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no scratch scripts', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no helper JSON argument files', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no direct companion shell launch', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no action scripts', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no source-code schema spelunking', $client['notes'], $client['slug'] );
			$this->assertStringContainsString( 'no REST runner, shell WP-CLI, or generic PHP-adapter workaround', $client['notes'], $client['slug'] );
		}
	}

	public function test_antigravity_notes_require_playwright_and_restart(): void {
		$clients = ConnectClientConfig::clients();
		$match   = array_values(
			array_filter(
				$clients,
				static fn ( array $client ): bool => 'antigravity' === $client['slug']
			)
		);

		$this->assertNotEmpty( $match );
		$this->assertStringContainsString( 'Playwright', $match[0]['notes'] );
		$this->assertStringContainsString( 'restart', $match[0]['notes'] );
	}

	public function test_endpoint_url_uses_rest_url(): void {
		$url = ConnectClientConfig::mcp_endpoint_url();
		$this->assertStringContainsString( 'mcp/stonewright', $url );
	}

	public function test_universal_snippet_structure(): void {
		$snippet = ConnectClientConfig::universal_snippet( 'admin', 'abcd 1234 efgh 5678' );
		$this->assertArrayHasKey( 'mcpServers', $snippet );
		$this->assertArrayHasKey( self::SERVER_NAME, $snippet['mcpServers'] );

		$server = $snippet['mcpServers'][ self::SERVER_NAME ];
		$this->assertSame( 'npx', $server['command'] );
		$this->assertSame(
			[
				'-y',
				'--package',
				'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v0.0.0-test/stonewright-companion-0.0.0-test.tgz',
				'stonewright-mcp',
			],
			$server['args']
		);
		$this->assertNotContains( '@automattic/mcp-wordpress-remote@latest', $server['args'] );
		$this->assertSame( 'plugin', $server['env']['STONEWRIGHT_MODE'] );
		$this->assertSame( 'admin', $server['env']['STONEWRIGHT_WP_USERNAME'] );
		$this->assertSame( 'abcd 1234 efgh 5678', $server['env']['STONEWRIGHT_WP_APP_PASSWORD'] );
		$this->assertSame( 'essential', $server['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
	}

	public function test_server_name_distinguishes_path_based_sites(): void {
		$GLOBALS['stonewright_test_site_url'] = 'https://example.test/network/site-a/';
		$snippet = ConnectClientConfig::universal_snippet( 'admin', 'pass' );

		$this->assertArrayHasKey( 'stonewright-example-test-network-site-a', $snippet['mcpServers'] );
	}

	public function test_universal_snippet_password_placeholder_when_empty(): void {
		$snippet = ConnectClientConfig::universal_snippet( 'admin', '' );
		$this->assertSame(
			'<your-application-password>',
			$snippet['mcpServers'][ self::SERVER_NAME ]['env']['STONEWRIGHT_WP_APP_PASSWORD']
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
		$this->assertStringContainsString( "claude mcp add 'stonewright-example-test'", $result['command'] );
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $result['command'] );
		$this->assertStringContainsString( '--package', $result['command'] );
		$this->assertStringContainsString( 'stonewright-mcp', $result['command'] );
		$this->assertStringContainsString( '--env STONEWRIGHT_MODE=plugin', $result['command'] );
		$this->assertStringContainsString( '--env STONEWRIGHT_MCP_TOOL_PROFILE=essential', $result['command'] );
		$this->assertStringNotContainsString( '@automattic/mcp-wordpress-remote', $result['command'] );
	}

	public function test_snippet_for_codex_returns_config_toml(): void {
		$result = ConnectClientConfig::snippet_for( 'codex', 'admin', 'pw' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'toml', $result );
		$this->assertStringContainsString( '[mcp_servers.' . self::SERVER_NAME . ']', $result['toml'] );
		$this->assertStringContainsString( 'command = "npx"', $result['toml'] );
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $result['toml'] );
		$this->assertStringContainsString( '[mcp_servers.' . self::SERVER_NAME . '.env]', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_MODE = "plugin"', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_WP_USERNAME = "admin"', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_WP_APP_PASSWORD = "pw"', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_MCP_TOOL_PROFILE = "essential"', $result['toml'] );
	}

	public function test_stdio_snippets_follow_explicit_full_surface(): void {
		update_option( 'stonewright_mcp_surface', 'full', false );

		$codex  = ConnectClientConfig::snippet_for( 'codex', 'admin', 'pw' );
		$cursor = ConnectClientConfig::snippet_for( 'cursor', 'admin', 'pw' );
		$claude = ConnectClientConfig::snippet_for( 'claude-code', 'admin', 'pw' );

		$this->assertIsArray( $codex );
		$this->assertIsArray( $cursor );
		$this->assertIsArray( $claude );
		$this->assertStringContainsString( 'STONEWRIGHT_MCP_TOOL_PROFILE = "full"', $codex['toml'] );
		$this->assertSame( 'full', $cursor['mcpServers'][ self::SERVER_NAME ]['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
		$this->assertStringContainsString( '--env STONEWRIGHT_MCP_TOOL_PROFILE=full', $claude['command'] );
	}

	public function test_known_client_follows_explicit_bootstrap_surface(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );

		$this->assertSame( 'bootstrap', ConnectClientConfig::recommended_startup_profile( 'codex' ) );
		$this->assertSame( 'bootstrap', ConnectClientConfig::recommended_startup_profile( 'cursor' ) );
	}

	public function test_unknown_client_follows_explicit_site_surface(): void {
		update_option( 'stonewright_mcp_surface', 'bootstrap', false );

		$this->assertSame( 'bootstrap', ConnectClientConfig::recommended_startup_profile() );
		$this->assertSame( 'bootstrap', ConnectClientConfig::recommended_startup_profile( 'generic' ) );
	}

	public function test_strict_cap_client_override_wins_over_saved_site_surface(): void {
		update_option( 'stonewright_mcp_surface', 'full', false );

		$gemini = ConnectClientConfig::snippet_for( 'gemini-cli', 'admin', 'pw' );

		$this->assertIsArray( $gemini );
		$this->assertSame( 'low-tools', $gemini['mcpServers'][ self::SERVER_NAME ]['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
	}

	public function test_snippet_for_known_client_returns_universal_block(): void {
		$result = ConnectClientConfig::snippet_for( 'cursor', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'mcpServers', $result );
		$this->assertArrayHasKey( self::SERVER_NAME, $result['mcpServers'] );
	}

	public function test_strict_tool_cap_clients_use_low_tools_profile(): void {
		$antigravity = ConnectClientConfig::snippet_for( 'antigravity', 'admin', 'pw' );
		$gemini      = ConnectClientConfig::snippet_for( 'gemini-cli', 'admin', 'pw' );

		$this->assertIsArray( $antigravity );
		$this->assertIsArray( $gemini );
		$this->assertSame( 'low-tools', $antigravity['mcpServers'][ self::SERVER_NAME ]['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
		$this->assertSame( 'low-tools', $gemini['mcpServers'][ self::SERVER_NAME ]['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
	}

	public function test_snippet_for_vscode_copilot_uses_servers_key(): void {
		$result = ConnectClientConfig::snippet_for( 'vscode-copilot', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'servers', $result );
		$this->assertArrayNotHasKey( 'mcpServers', $result );
		$this->assertArrayHasKey( self::SERVER_NAME, $result['servers'] );
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
		$this->assertArrayHasKey( self::SERVER_NAME, $result['context_servers'] );
	}

	public function test_paste_to_agent_prompt_is_current_and_credential_free(): void {
		$prompt = ConnectClientConfig::paste_to_agent_prompt( 'admin', 'pw1234' );
		$this->assertStringNotContainsString( 'admin', $prompt );
		$this->assertStringNotContainsString( 'pw1234', $prompt );
		$this->assertStringNotContainsString( 'https://example.test', $prompt );
		$this->assertStringContainsString( '<your-wordpress-url>', $prompt );
		$this->assertStringContainsString( '<your-wordpress-username>', $prompt );
		$this->assertStringContainsString( 'mcp/stonewright', $prompt );
		$this->assertStringContainsString( 'mcp/stonewright-oauth', $prompt );
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $prompt );
		$this->assertStringContainsString( 'stonewright connect add', $prompt );
		$this->assertStringContainsString( '--mode plugin-only', $prompt );
		$this->assertStringContainsString( 'stonewright connect repair <alias> --client <client> --mode plugin-only', $prompt );
		$this->assertStringContainsString( 'Choose exactly one transport', $prompt );
		$this->assertStringNotContainsString( 'Build a private config', $prompt );
		$this->assertStringNotContainsString( 'MCP server name: stonewright', $prompt );
		$this->assertStringContainsString( 'communicates through standard input/output', $prompt );
		$this->assertStringContainsString( 'does not run a local companion', $prompt );
		$this->assertStringContainsString( 'Show me approval_url, exact path, byte counts, and a short summary, then stop', $prompt );
		$this->assertStringContainsString( 'Never open the approval page', $prompt );
		$this->assertStringContainsString( 'hidden prompt', $prompt );
		$this->assertStringContainsString( '--password-env', $prompt );
		$this->assertStringContainsString( '~/.stonewright/sites.json may contain credential_ref values, never plaintext credentials', $prompt );
		$this->assertStringContainsString( 'stonewright connect verify <alias> --client <client>', $prompt );
		$this->assertStringContainsString( 'fully restart the MCP client', $prompt );
		$this->assertStringContainsString( 'configured_mode=plugin-only', $prompt );
		$this->assertStringContainsString( 'active_mode=plugin', $prompt );
		$this->assertStringContainsString( 'wrong named server is active', $prompt );
		$this->assertStringContainsString( 'stonewright-task-start', $prompt );
		$this->assertLessThan(
			strpos( $prompt, 'stonewright-context-bootstrap' ) ?: PHP_INT_MAX,
			strpos( $prompt, 'stonewright-task-start' ) ?: PHP_INT_MAX,
			'task-start should be mentioned before context-bootstrap as first-call guidance'
		);
		$this->assertStringContainsString( 'stonewright-tool-profile', $prompt );
		$this->assertStringContainsString( 'Read fast_path.tool_profile', $prompt );
		$this->assertStringContainsString( 'stonewright-setup-profile', $prompt );
		$this->assertStringContainsString( 'stonewright-wordpress-mcp-status', $prompt );
		$this->assertStringContainsString( 'companion_version', $prompt );
		$this->assertStringContainsString( 'expected_companion_package', $prompt );
		$this->assertStringContainsString( 'refresh_required_tool_names', $prompt );
		$this->assertStringContainsString( 'never bypass Stonewright', $prompt );
		$this->assertStringContainsString( 'user-approved browser provider', $prompt );
		$this->assertStringContainsString( 'Ask me once whether this site/client should use Playwright', $prompt );
		$this->assertStringContainsString( 'Do not scan my private config or client tool surface without permission', $prompt );
		$this->assertStringContainsString( 'Never install or reconfigure a browser provider silently', $prompt );
		$this->assertStringContainsString( 'never bypasses custom-code dry-run, approval, backup, permission, or confirmation gates', $prompt );
	}

	public function test_playwright_mcp_snippet_is_separate_server(): void {
		$snippet = ConnectClientConfig::playwright_mcp_snippet();

		$this->assertSame( 'npx', $snippet['mcpServers']['playwright']['command'] );
		$this->assertSame( [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ], $snippet['mcpServers']['playwright']['args'] );
	}
}
