<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Generates per-client MCP connection snippets for Stonewright.
 *
 * Transport options:
 *   A) Native stdio — @stonewright/companion npm script (recommended for local dev)
 *   B) Streamable HTTP — direct /wp-json/mcp/stonewright endpoint
 *
 * IMPORTANT: Do NOT use @automattic/mcp-wordpress-remote. That Automattic package
 * routes through its own WP REST bridge and does not speak the Stonewright WP
 * Abilities protocol. All snippets in this class use the correct transport.
 */
final class ConnectClientConfig {

	// -------------------------------------------------------------------------
	// Client catalogue
	// -------------------------------------------------------------------------

	/**
	 * Returns the full list of supported AI clients and their config metadata.
	 *
	 * @return array<int, array{slug: string, label: string, config_path: string, kind: string, notes: string}>
	 */
	public static function clients(): array {
		return [
			[
				'slug'        => 'claude-code',
				'label'       => 'Claude Code',
				'config_path' => 'Terminal command: claude mcp add',
				'kind'        => 'cli',
				'notes'       => 'Uses the claude CLI; run the generated command in any terminal. The MCP server is registered globally for your user.',
			],
			[
				'slug'        => 'claude-desktop',
				'label'       => 'Claude Desktop',
				'config_path' => '~/Library/Application Support/Claude/claude_desktop_config.json (macOS) or %APPDATA%\\Claude\\claude_desktop_config.json (Windows)',
				'kind'        => 'desktop',
				'notes'       => 'Paste the JSON block into the mcpServers object in the config file, then restart Claude Desktop.',
			],
			[
				'slug'        => 'cursor',
				'label'       => 'Cursor',
				'config_path' => '.cursor/mcp.json (project) or ~/.cursor/mcp.json (global)',
				'kind'        => 'editor',
				'notes'       => 'Create the file if it does not exist. A project-level file takes precedence over the global one.',
			],
			[
				'slug'        => 'vscode-copilot',
				'label'       => 'VS Code (Copilot)',
				'config_path' => '.vscode/mcp.json (workspace) or VS Code user settings under "mcp.servers"',
				'kind'        => 'editor',
				'notes'       => 'The workspace file is version-controlled and shared with your team. Use user settings for a personal global entry.',
			],
			[
				'slug'        => 'windsurf',
				'label'       => 'Windsurf',
				'config_path' => '~/.codeium/windsurf/mcp_config.json',
				'kind'        => 'editor',
				'notes'       => 'Merge the snippet into the existing file if one already exists; do not overwrite the whole file.',
			],
			[
				'slug'        => 'zed',
				'label'       => 'Zed',
				'config_path' => '~/.config/zed/settings.json (under the "context_servers" key)',
				'kind'        => 'editor',
				'notes'       => 'Zed uses a context_servers key rather than mcpServers; adapt the snippet accordingly when pasting.',
			],
			[
				'slug'        => 'opencode',
				'label'       => 'OpenCode',
				'config_path' => '.opencode/config.json (project) or ~/.config/opencode/config.json (global)',
				'kind'        => 'cli',
				'notes'       => 'The project-level file takes precedence. Add the snippet under the "mcp" key in the config.',
			],
			[
				'slug'        => 'openai-codex-cli',
				'label'       => 'OpenAI Codex CLI',
				'config_path' => 'MCP server config file path varies by platform; check the Codex CLI docs for your OS.',
				'kind'        => 'cli',
				'notes'       => 'Use the standard mcpServers block. Codex CLI follows the same MCP transport protocol.',
			],
			[
				'slug'        => 'cline',
				'label'       => 'Cline',
				'config_path' => 'VS Code extension settings → Cline → MCP Servers (GUI) or cline_mcp_settings.json',
				'kind'        => 'editor',
				'notes'       => 'Cline can manage MCP servers through its settings UI — paste the snippet there or edit the JSON file directly.',
			],
			[
				'slug'        => 'roo-code',
				'label'       => 'Roo Code',
				'config_path' => 'VS Code extension settings → Roo Code → MCP Servers, or the equivalent JSON settings file.',
				'kind'        => 'editor',
				'notes'       => 'Follows the same pattern as Cline; use the extension settings panel or the backing JSON file.',
			],
			[
				'slug'        => 'amazon-q',
				'label'       => 'Amazon Q',
				'config_path' => '~/.aws/amazonq/mcp.json',
				'kind'        => 'desktop',
				'notes'       => 'Amazon Q Developer supports MCP via a dedicated config file. Paste the stonewright entry under mcpServers.',
			],
			[
				'slug'        => 'kilo-code',
				'label'       => 'Kilo Code',
				'config_path' => 'VS Code extension settings → Kilo Code → MCP Servers config',
				'kind'        => 'editor',
				'notes'       => 'Uses the standard MCP protocol. Configure via the extension panel or its backing JSON settings file.',
			],
			[
				'slug'        => 'gemini-cli',
				'label'       => 'Gemini CLI',
				'config_path' => '~/.gemini/settings.json (under the "mcpServers" key)',
				'kind'        => 'cli',
				'notes'       => 'The Gemini CLI reads MCP server definitions from the settings file in your home directory.',
			],
			[
				'slug'        => 'github-copilot',
				'label'       => 'GitHub Copilot',
				'config_path' => '.vscode/mcp.json (workspace) when using Copilot Chat in VS Code with MCP support enabled.',
				'kind'        => 'editor',
				'notes'       => 'MCP support in Copilot Chat requires VS Code 1.99+ and the MCP feature flag enabled.',
			],
			[
				'slug'        => 'antigravity',
				'label'       => 'Antigravity',
				'config_path' => 'Project or global MCP config file; consult the Antigravity documentation for your version.',
				'kind'        => 'desktop',
				'notes'       => 'Uses the universal mcpServers transport block. Follow the Antigravity setup wizard to point it at this config.',
			],
		];
	}

	// -------------------------------------------------------------------------
	// Endpoint URLs
	// -------------------------------------------------------------------------

	/**
	 * Returns the site URL, with a fallback for unit-test contexts where WP functions are not loaded.
	 */
	private static function site_url(): string {
		return function_exists( 'get_site_url' ) ? (string) get_site_url() : '';
	}

	/**
	 * Returns the Stonewright native MCP endpoint URL (Streamable HTTP transport).
	 */
	public static function mcp_endpoint_url(): string {
		return rest_url( 'mcp/stonewright' );
	}

	/**
	 * Returns the WP Abilities REST base for direct tool invocation.
	 */
	public static function abilities_base_url(): string {
		return rest_url( 'wp-abilities/v1/abilities' );
	}

	// -------------------------------------------------------------------------
	// Snippet generators
	// -------------------------------------------------------------------------

	/**
	 * Returns the native stdio snippet using @stonewright/companion.
	 *
	 * This is the RECOMMENDED transport for local development. The companion
	 * package speaks the Stonewright WP Abilities protocol natively.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>
	 */
	public static function native_stdio_snippet( string $username = '', string $app_password = '' ): array {
		return [
			'mcpServers' => [
				'stonewright' => [
					'command' => 'npx',
					'args'    => [ '-y', '@stonewright/companion@latest', 'mcp' ],
					'env'     => [
						'STONEWRIGHT_SITE_URL' => self::site_url(),
						'WP_API_USERNAME'     => $username ?: 'your-wp-username',
						'WP_API_PASSWORD'     => $app_password ?: '<your-application-password>',
						'STONEWRIGHT_MCP_URL' => self::mcp_endpoint_url(),
					],
				],
			],
		];
	}

	/**
	 * Returns the Streamable HTTP snippet using the native Stonewright endpoint.
	 *
	 * Use when your AI client supports HTTP MCP transport (no Node.js required).
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>
	 */
	public static function http_snippet( string $username = '', string $app_password = '' ): array {
		$credentials = base64_encode( $username . ':' . ( $app_password ?: '<your-application-password>' ) );
		return [
			'mcpServers' => [
				'stonewright' => [
					'url'     => self::mcp_endpoint_url(),
					'headers' => [
						'Authorization' => 'Basic ' . $credentials,
					],
				],
			],
		];
	}

	/**
	 * Backwards-compatible alias — returns the native stdio snippet.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>
	 */
	public static function universal_snippet( string $username = '', string $app_password = '' ): array {
		return self::native_stdio_snippet( $username, $app_password );
	}

	/**
	 * Returns the standard Stdio mcpServers config block (alias for native_stdio_snippet).
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>
	 */
	public static function stdio_snippet( string $username = '', string $app_password = '' ): array {
		return self::native_stdio_snippet( $username, $app_password );
	}

	/**
	 * Returns the connection snippet for a specific client.
	 *
	 * @param string $client_slug  One of the slugs returned by clients().
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @param string $transport    'stdio' (default) or 'http'.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function snippet_for(
		string $client_slug,
		string $username = '',
		string $app_password = '',
		string $transport = 'stdio'
	): array|\WP_Error {
		$known_slugs = array_column( self::clients(), 'slug' );
		if ( ! in_array( $client_slug, $known_slugs, true ) ) {
			return new \WP_Error(
				'stonewright_unknown_client',
				sprintf( __( 'Unknown client slug: %s', 'stonewright' ), $client_slug )
			);
		}

		if ( 'claude-code' === $client_slug ) {
			if ( 'http' === $transport ) {
				$credentials = base64_encode( $username . ':' . ( $app_password ?: '<your-application-password>' ) );
				return [
					'command' => sprintf(
						'claude mcp add stonewright --transport http --url %s --header "Authorization: Basic %s"',
						escapeshellarg( self::mcp_endpoint_url() ),
						$credentials
					),
				];
			}
			return [
				'command' => sprintf(
					'claude mcp add stonewright -- npx -y @stonewright/companion@latest mcp --env STONEWRIGHT_SITE_URL=%s --env WP_API_USERNAME=%s --env WP_API_PASSWORD=%s --env STONEWRIGHT_MCP_URL=%s',
					escapeshellarg( self::site_url() ),
					escapeshellarg( $username ),
					escapeshellarg( $app_password ?: '<your-application-password>' ),
					escapeshellarg( self::mcp_endpoint_url() )
				),
			];
		}

		$snippet = 'http' === $transport
			? self::http_snippet( $username, $app_password )
			: self::native_stdio_snippet( $username, $app_password );

		if ( in_array( $client_slug, [ 'vscode-copilot', 'github-copilot' ], true ) ) {
			return [ 'servers' => $snippet['mcpServers'] ];
		}

		if ( 'zed' === $client_slug ) {
			return [ 'context_servers' => $snippet['mcpServers'] ];
		}

		return $snippet;
	}

	// -------------------------------------------------------------------------
	// Paste-to-agent prompt
	// -------------------------------------------------------------------------

	/**
	 * Returns a natural-language prompt for the user to configure the agent.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 */
	public static function paste_to_agent_prompt( string $username, string $app_password ): string {
		$stdio_json = wp_json_encode(
			self::native_stdio_snippet( $username, $app_password ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		$http_json  = wp_json_encode(
			self::http_snippet( $username, $app_password ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$endpoint = self::mcp_endpoint_url();

		return sprintf(
			/* translators: 1: MCP endpoint URL, 2: stdio JSON snippet, 3: HTTP JSON snippet */
			__(
				"Configure Stonewright as an MCP server.\nEndpoint: %1\$s\n\nOption A — Native stdio (recommended, Node.js required):\n```json\n%2\$s\n```\n\nOption B — Streamable HTTP (no Node.js required):\n```json\n%3\$s\n```\n\nDo NOT use @automattic/mcp-wordpress-remote — it does not speak the Stonewright WP Abilities protocol.",
				'stonewright'
			),
			$endpoint,
			(string) $stdio_json,
			(string) $http_json
		);
	}
}
