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
		$this->assertArrayHasKey( 'stonewright', $snippet['mcpServers'] );

		$server = $snippet['mcpServers']['stonewright'];
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
		$this->assertSame( 'admin', $server['env']['STONEWRIGHT_WP_USERNAME'] );
		$this->assertSame( 'abcd 1234 efgh 5678', $server['env']['STONEWRIGHT_WP_APP_PASSWORD'] );
		$this->assertSame( 'essential', $server['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
	}

	public function test_universal_snippet_password_placeholder_when_empty(): void {
		$snippet = ConnectClientConfig::universal_snippet( 'admin', '' );
		$this->assertSame(
			'<your-application-password>',
			$snippet['mcpServers']['stonewright']['env']['STONEWRIGHT_WP_APP_PASSWORD']
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
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $result['command'] );
		$this->assertStringContainsString( '--package', $result['command'] );
		$this->assertStringContainsString( 'stonewright-mcp', $result['command'] );
		$this->assertStringContainsString( '--env STONEWRIGHT_MCP_TOOL_PROFILE=essential', $result['command'] );
		$this->assertStringNotContainsString( '@automattic/mcp-wordpress-remote', $result['command'] );
	}

	public function test_snippet_for_codex_returns_config_toml(): void {
		$result = ConnectClientConfig::snippet_for( 'codex', 'admin', 'pw' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'toml', $result );
		$this->assertStringContainsString( '[mcp_servers.stonewright]', $result['toml'] );
		$this->assertStringContainsString( 'command = "npx"', $result['toml'] );
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $result['toml'] );
		$this->assertStringContainsString( '[mcp_servers.stonewright.env]', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_WP_USERNAME = "admin"', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_WP_APP_PASSWORD = "pw"', $result['toml'] );
		$this->assertStringContainsString( 'STONEWRIGHT_MCP_TOOL_PROFILE = "essential"', $result['toml'] );
	}

	public function test_snippet_for_known_client_returns_universal_block(): void {
		$result = ConnectClientConfig::snippet_for( 'cursor', 'admin', 'pw' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'mcpServers', $result );
		$this->assertArrayHasKey( 'stonewright', $result['mcpServers'] );
	}

	public function test_strict_tool_cap_clients_use_low_tools_profile(): void {
		$antigravity = ConnectClientConfig::snippet_for( 'antigravity', 'admin', 'pw' );
		$gemini      = ConnectClientConfig::snippet_for( 'gemini-cli', 'admin', 'pw' );

		$this->assertIsArray( $antigravity );
		$this->assertIsArray( $gemini );
		$this->assertSame( 'low-tools', $antigravity['mcpServers']['stonewright']['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
		$this->assertSame( 'low-tools', $gemini['mcpServers']['stonewright']['env']['STONEWRIGHT_MCP_TOOL_PROFILE'] );
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
		$this->assertStringContainsString( 'stonewright-companion-0.0.0-test.tgz', $prompt );
		$this->assertStringContainsString( '["-y", "--package"', $prompt );
		$this->assertStringContainsString( 'stonewright-mcp', $prompt );
		$this->assertStringContainsString( 'STONEWRIGHT_WP_APP_PASSWORD', $prompt );
		$this->assertStringContainsString( 'STONEWRIGHT_MCP_TOOL_PROFILE', $prompt );
		$this->assertStringContainsString( 'Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients', $prompt );
		$this->assertStringContainsString( 'versioned GitHub release tarball', $prompt );
		$this->assertStringContainsString( 'Do not configure generic WordPress MCP adapters such as `@automattic/mcp-wordpress-remote`', $prompt );
		$this->assertStringContainsString( 'Do not use `node companion/dist/index.js` in IDE MCP configs', $prompt );
		$this->assertStringContainsString( 'stonewright-php-execute', $prompt );
		$this->assertStringContainsString( 'direct full WordPress runtime snippets', $prompt );
		$this->assertStringContainsString( 'Playwright MCP', $prompt );
		$this->assertStringContainsString( '@playwright/mcp@latest', $prompt );
		$this->assertStringContainsString( 'browser testing, screenshots, and visual QA', $prompt );
		$this->assertStringContainsString( 'Restart or reload the MCP session', $prompt );
		$this->assertStringContainsString( 'stonewright-task-start', $prompt );
		$this->assertStringContainsString( 'Verify the MCP tool list includes stonewright-task-start', $prompt );
		$this->assertLessThan(
			strpos( $prompt, 'stonewright-context-bootstrap' ) ?: PHP_INT_MAX,
			strpos( $prompt, 'stonewright-task-start' ) ?: PHP_INT_MAX,
			'task-start should be mentioned before context-bootstrap as first-call guidance'
		);
		$this->assertStringContainsString( 'stonewright-tool-profile', $prompt );
		$this->assertStringContainsString( 'Use fast_path.tool_profile from stonewright-task-start before making a separate stonewright-tool-profile call', $prompt );
		$this->assertStringContainsString( 'stonewright-setup-profile', $prompt );
		$this->assertStringContainsString( 'stonewright-wordpress-mcp-status', $prompt );
		$this->assertStringContainsString( 'companion_version', $prompt );
		$this->assertStringContainsString( 'expected_companion_package', $prompt );
		$this->assertStringContainsString( 'refresh_required_tool_names', $prompt );
		$this->assertStringContainsString( 'Do not start by only announcing named skills', $prompt );
		$this->assertStringContainsString( 'Do not treat local agent skills as a substitute for live Stonewright MCP tools', $prompt );
		$this->assertStringNotContainsString( 'First Stonewright calls after connection: stonewright-context-bootstrap, then stonewright-workflow-preflight', $prompt );
		$this->assertStringContainsString( 'Do not inspect private AI-client config files, parse repository files, or hand-roll JSON-RPC calls', $prompt );
		$this->assertStringContainsString( 'Do not create scratch scripts such as query-mcp.js or run-ability.js', $prompt );
		$this->assertStringContainsString( 'Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json', $prompt );
		$this->assertStringContainsString( 'Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js', $prompt );
		$this->assertStringContainsString( 'Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js', $prompt );
		$this->assertStringContainsString( 'Do not inspect plugin or companion source code to reverse-engineer tool schemas', $prompt );
		$this->assertStringContainsString( 'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround', $prompt );
		$this->assertStringContainsString( 'Do not run wp commands or WP-CLI eval entry points in a normal shell', $prompt );
	}

	public function test_playwright_mcp_snippet_is_separate_server(): void {
		$snippet = ConnectClientConfig::playwright_mcp_snippet();

		$this->assertSame( 'npx', $snippet['mcpServers']['playwright']['command'] );
		$this->assertSame( [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ], $snippet['mcpServers']['playwright']['args'] );
	}
}
