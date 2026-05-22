<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Generates per-client MCP connection snippets for Stonewright.
 *
 * All snippets use the universal @automattic/mcp-wordpress-remote transport.
 * Each client entry describes where the user should paste the resulting JSON
 * or CLI command.
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
	// Endpoint URL
	// -------------------------------------------------------------------------

	/**
	 * Returns the Stonewright MCP endpoint URL.
	 */
	public static function mcp_endpoint_url(): string {
		return rest_url( 'mcp/stonewright' );
	}

	// -------------------------------------------------------------------------
	// Snippet generators
	// -------------------------------------------------------------------------

	/**
	 * Returns the universal mcpServers config block as a PHP array.
	 *
	 * @param string $username     WordPress username for authentication.
	 * @param string $app_password Application Password (shown once after generation).
	 * @return array<string, mixed>
	 */
	public static function universal_snippet( string $username = '', string $app_password = '' ): array {
		return [
			'mcpServers' => [
				'stonewright' => [
					'command' => 'npx',
					'args'    => [ '-y', '@automattic/mcp-wordpress-remote@latest' ],
					'env'     => [
						'WP_API_URL'      => self::mcp_endpoint_url(),
						'WP_API_USERNAME' => $username,
						'WP_API_PASSWORD' => $app_password ?: '<your-application-password>',
					],
				],
			],
		];
	}

	/**
	 * Returns the connection snippet for a specific client.
	 *
	 * Most clients receive the universal mcpServers block.
	 * Claude Code receives a CLI command string instead.
	 *
	 * @param string $client_slug  One of the slugs returned by clients().
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function snippet_for( string $client_slug, string $username = '', string $app_password = '' ): array|\WP_Error {
		// Validate slug against the catalogue.
		$known_slugs = array_column( self::clients(), 'slug' );
		if ( ! in_array( $client_slug, $known_slugs, true ) ) {
			return new \WP_Error(
				'stonewright_unknown_client',
				sprintf(
					/* translators: %s: unknown client slug */
					__( 'Unknown client slug: %s', 'stonewright' ),
					$client_slug
				)
			);
		}

		// Claude Code uses the CLI add command rather than a JSON file.
		if ( 'claude-code' === $client_slug ) {
			$url      = self::mcp_endpoint_url();
			$password = $app_password ?: '<your-application-password>';

			return [
				'command' => sprintf(
					'claude mcp add stonewright -- npx -y @automattic/mcp-wordpress-remote@latest --env WP_API_URL=%s --env WP_API_USERNAME=%s --env WP_API_PASSWORD=%s',
					escapeshellarg( $url ),
					escapeshellarg( $username ),
					escapeshellarg( $password )
				),
			];
		}

		// VS Code Copilot + GitHub Copilot use a "servers" top-level key per the VS Code MCP schema.
		if ( in_array( $client_slug, [ 'vscode-copilot', 'github-copilot' ], true ) ) {
			$universal = self::universal_snippet( $username, $app_password );
			return [ 'servers' => $universal['mcpServers'] ];
		}

		// Zed uses context_servers per its settings schema.
		if ( 'zed' === $client_slug ) {
			$universal = self::universal_snippet( $username, $app_password );
			return [ 'context_servers' => $universal['mcpServers'] ];
		}

		// All other clients: universal JSON snippet with mcpServers.
		return self::universal_snippet( $username, $app_password );
	}

	// -------------------------------------------------------------------------
	// Paste-to-agent prompt
	// -------------------------------------------------------------------------

	/**
	 * Returns a natural-language prompt the user can paste directly into an AI
	 * chat to have the agent configure itself.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 */
	public static function paste_to_agent_prompt( string $username, string $app_password ): string {
		$snippet_json = wp_json_encode(
			self::universal_snippet( $username, $app_password ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			/* translators: 1: MCP endpoint URL, 2: username, 3: app password, 4: JSON config snippet */
			__(
				"Configure Stonewright as an MCP server. Endpoint: %1\$s. Auth: WordPress Application Password. Username: %2\$s. Password: %3\$s. Use the universal template:\n\n```json\n%4\$s\n```",
				'stonewright'
			),
			self::mcp_endpoint_url(),
			$username,
			$app_password,
			(string) $snippet_json
		);
	}
}
