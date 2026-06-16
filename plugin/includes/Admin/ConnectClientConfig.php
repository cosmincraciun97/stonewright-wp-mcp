<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

/**
 * Generates per-client MCP connection snippets for Stonewright.
 */
final class ConnectClientConfig {
	private const RELEASE_BASE_URL = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download';


	/**
	 * Returns supported AI clients and their config metadata.
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
				'notes'       => 'Uses the claude CLI; run the generated command in any terminal.',
			],
			[
				'slug'        => 'claude-desktop',
				'label'       => 'Claude Desktop',
				'config_path' => '~/Library/Application Support/Claude/claude_desktop_config.json (macOS) or %APPDATA%\\Claude\\claude_desktop_config.json (Windows)',
				'kind'        => 'desktop',
				'notes'       => 'Paste the JSON block into the mcpServers object, then restart Claude Desktop.',
			],
			[
				'slug'        => 'cursor',
				'label'       => 'Cursor',
				'config_path' => '.cursor/mcp.json (project) or ~/.cursor/mcp.json (global)',
				'kind'        => 'editor',
				'notes'       => 'Create the file if it does not exist. Project config wins over global config.',
			],
			[
				'slug'        => 'vscode-copilot',
				'label'       => 'VS Code (Copilot)',
				'config_path' => '.vscode/mcp.json (workspace) or VS Code user settings under "mcp.servers"',
				'kind'        => 'editor',
				'notes'       => 'Workspace config can be shared with a team. Use user settings for private credentials.',
			],
			[
				'slug'        => 'windsurf',
				'label'       => 'Windsurf',
				'config_path' => '~/.codeium/windsurf/mcp_config.json',
				'kind'        => 'editor',
				'notes'       => 'Merge the snippet into the existing file if one already exists.',
			],
			[
				'slug'        => 'zed',
				'label'       => 'Zed',
				'config_path' => '~/.config/zed/settings.json (under the "context_servers" key)',
				'kind'        => 'editor',
				'notes'       => 'Zed uses context_servers rather than mcpServers.',
			],
			[
				'slug'        => 'opencode',
				'label'       => 'OpenCode',
				'config_path' => '.opencode/config.json (project) or ~/.config/opencode/config.json (global)',
				'kind'        => 'cli',
				'notes'       => 'Add the snippet under the mcp key in the config file.',
			],
			[
				'slug'        => 'cline',
				'label'       => 'Cline',
				'config_path' => 'VS Code extension settings > Cline > MCP Servers',
				'kind'        => 'editor',
				'notes'       => 'Paste the snippet in the settings UI or the backing JSON file.',
			],
			[
				'slug'        => 'roo-code',
				'label'       => 'Roo Code',
				'config_path' => 'VS Code extension settings > Roo Code > MCP Servers',
				'kind'        => 'editor',
				'notes'       => 'Uses the same MCP server shape as other VS Code agent extensions.',
			],
			[
				'slug'        => 'amazon-q',
				'label'       => 'Amazon Q',
				'config_path' => '~/.aws/amazonq/mcp.json',
				'kind'        => 'desktop',
				'notes'       => 'Paste the stonewright entry under mcpServers.',
			],
			[
				'slug'        => 'kilo-code',
				'label'       => 'Kilo Code',
				'config_path' => 'VS Code extension settings > Kilo Code > MCP Servers config',
				'kind'        => 'editor',
				'notes'       => 'Configure through the extension panel or its backing JSON settings file.',
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
				'config_path' => '.vscode/mcp.json (workspace) when MCP support is enabled.',
				'kind'        => 'editor',
				'notes'       => 'Requires VS Code with MCP support enabled.',
			],
			[
				'slug'        => 'antigravity',
				'label'       => 'Antigravity',
				'config_path' => 'Project or global MCP config file.',
				'kind'        => 'desktop',
				'notes'       => 'Add Stonewright and Playwright entries, then restart or reload Antigravity before visual work.',
			],
		];
	}

	private static function site_url(): string {
		return function_exists( 'get_site_url' ) ? (string) get_site_url() : '';
	}

	public static function mcp_endpoint_url(): string {
		return rest_url( 'mcp/stonewright' );
	}

	public static function abilities_base_url(): string {
		return rest_url( 'wp-abilities/v1/abilities' );
	}

	public static function companion_package_spec(): string {
		$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';

		return self::RELEASE_BASE_URL . '/v' . rawurlencode( $version ) . '/stonewright-companion-' . rawurlencode( $version ) . '.tgz';
	}

	/**
	 * Returns the recommended local stdio snippet using npx.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @param string $tool_profile Compact companion tool profile.
	 * @return array<string, mixed>
	 */
	public static function native_stdio_snippet( string $username = '', string $app_password = '', string $tool_profile = 'essential' ): array {
		return [
			'mcpServers' => [
				'stonewright' => [
					'command' => 'npx',
					'args'    => self::companion_mcp_args(),
					'env'     => [
						'STONEWRIGHT_WP_URL'          => self::site_url(),
						'STONEWRIGHT_WP_USERNAME'     => $username ?: 'your-wp-username',
						'STONEWRIGHT_WP_APP_PASSWORD'  => $app_password ?: '<your-application-password>',
						'STONEWRIGHT_MCP_TOOL_PROFILE' => self::normalise_tool_profile( $tool_profile ),
					],
				],
			],
		];
	}

	/**
	 * Returns the direct Streamable HTTP snippet.
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
	 * Returns the separate browser MCP snippet to configure alongside Stonewright.
	 *
	 * @return array<string, mixed>
	 */
	public static function playwright_mcp_snippet(): array {
		return [
			'mcpServers' => [
				'playwright' => [
					'command' => 'npx',
					'args'    => [ '-y', '@playwright/mcp@latest', '--caps=testing,vision,devtools' ],
				],
			],
		];
	}

	/**
	 * Backwards-compatible alias for the recommended stdio snippet.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 * @return array<string, mixed>
	 */
	public static function universal_snippet( string $username = '', string $app_password = '' ): array {
		return self::native_stdio_snippet( $username, $app_password );
	}

	/**
	 * Alias for the recommended stdio snippet.
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
					'claude mcp add stonewright --env STONEWRIGHT_WP_URL=%s --env STONEWRIGHT_WP_USERNAME=%s --env STONEWRIGHT_WP_APP_PASSWORD=%s --env STONEWRIGHT_MCP_TOOL_PROFILE=essential -- npx -y --package %s stonewright-mcp',
					escapeshellarg( self::site_url() ),
					escapeshellarg( $username ),
					escapeshellarg( $app_password ?: '<your-application-password>' ),
					escapeshellarg( self::companion_package_spec() )
				),
			];
		}

		$snippet = 'http' === $transport
			? self::http_snippet( $username, $app_password )
			: self::native_stdio_snippet( $username, $app_password, self::profile_for_client( $client_slug ) );

		if ( in_array( $client_slug, [ 'vscode-copilot', 'github-copilot' ], true ) ) {
			return [ 'servers' => $snippet['mcpServers'] ];
		}

		if ( 'zed' === $client_slug ) {
			return [ 'context_servers' => $snippet['mcpServers'] ];
		}

		return $snippet;
	}

	/**
	 * Returns a natural-language prompt for the user to configure the agent.
	 *
	 * @param string $username     WordPress username.
	 * @param string $app_password Application Password.
	 */
	public static function paste_to_agent_prompt( string $username, string $app_password ): string {
		$prompt = sprintf(
			/* translators: 1: site URL, 2: MCP endpoint URL, 3: username, 4: Application Password, 5: companion package URL. */
			__(
				"Configure Stonewright MCP for this WordPress install in the current AI client.\n\nUse these connection values:\n- WordPress URL: %1\$s\n- MCP endpoint: %2\$s\n- Username: %3\$s\n- Application Password: %4\$s\n- MCP server name: stonewright\n- Local transport: npx -y --package %5\$s stonewright-mcp\n\nConfiguration rules:\n- Store credentials as env vars only: STONEWRIGHT_WP_URL, STONEWRIGHT_WP_USERNAME, STONEWRIGHT_WP_APP_PASSWORD.\n- Set STONEWRIGHT_MCP_TOOL_PROFILE=essential so new MCP sessions start with the compact Stonewright fast-path tool surface.\n- Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients.\n- Use command `npx` with args `[\"-y\", \"--package\", \"%5\$s\", \"stonewright-mcp\"]`; npx downloads the versioned GitHub release tarball and runs the explicit companion bin, so no global companion install or npm registry publishing is required.\n- Do not configure generic WordPress MCP adapters such as `@automattic/mcp-wordpress-remote` as the `stonewright` server; use the Stonewright companion so setup, status, compact profiles, and guarded WP-CLI tools stay visible during endpoint recovery.\n- Do not use `node companion/dist/index.js` in IDE MCP configs; dist is a source build artifact and is intentionally not committed. For source development, use `npm --prefix <repo>/companion run mcp:source`.\n- Do not use arbitrary PHP execution, wp eval, wp shell, --exec, or --require.\n- Restart or reload the MCP session after saving the config.\n\nAfter reload:\n- Verify the MCP tool list includes stonewright-context-bootstrap before starting WordPress work.\n- First Stonewright calls after connection: stonewright-context-bootstrap, then stonewright-workflow-preflight.\n- Do not start by only announcing named skills. Stonewright skills are guidance returned by MCP; they do not replace live tool calls.\n- Do not treat local agent skills as a substitute for live Stonewright MCP tools.\n- If stonewright-context-bootstrap is missing, stop and tell me the Stonewright MCP server did not load. Ask me to reload or fix the MCP config, or to open the Stonewright JSON snippets panel.\n- Do not inspect private AI-client config files, parse repository files, or hand-roll JSON-RPC calls to the WordPress MCP endpoint as a workaround for a missing MCP server.\n- Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.\n\nBrowser testing:\n- If this client does not already have browser tools, also add Playwright MCP for browser testing, screenshots, and visual QA.\n- Playwright MCP config: command `npx`, args `[\"-y\", \"@playwright/mcp@latest\", \"--caps=testing,vision,devtools\"]`.\n- For visual WordPress or Elementor work, confirm Playwright/browser tools are visible before the first write.\n\nIf you cannot edit the client config here, ask me to open the Stonewright JSON snippets panel.",
				'stonewright'
			),
			self::site_url(),
			self::mcp_endpoint_url(),
			$username,
			$app_password,
			self::companion_package_spec()
		);

		return $prompt . "\n- Use fast_path.tool_profile from stonewright-workflow-preflight before making a separate stonewright-tool-profile call; call tool-profile only to switch or verify a compact profile.";
	}

	private static function profile_for_client( string $client_slug ): string {
		return in_array( $client_slug, [ 'antigravity', 'gemini-cli' ], true ) ? 'low-tools' : 'essential';
	}

	/**
	 * Returns explicit npx args for the companion stdio MCP server.
	 *
	 * @return array<int, string>
	 */
	private static function companion_mcp_args(): array {
		return [ '-y', '--package', self::companion_package_spec(), 'stonewright-mcp' ];
	}

	private static function normalise_tool_profile( string $tool_profile ): string {
		$tool_profile = strtolower( trim( $tool_profile ) );
		return '' === $tool_profile ? 'essential' : $tool_profile;
	}
}
