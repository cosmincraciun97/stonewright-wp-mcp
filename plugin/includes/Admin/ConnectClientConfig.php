<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

use Stonewright\WpMcp\Core\AbilityRegistry;
use Stonewright\WpMcp\Core\McpUsePolicy;

/**
 * Generates per-client MCP connection snippets for Stonewright.
 */
final class ConnectClientConfig {
	private const RELEASE_BASE_URL = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download';


	/**
	 * Returns supported AI clients and their config metadata.
	 *
	 * Source of truth: plugin/data/clients/*.json via ClientCatalog.
	 *
	 * @return array<int, array{slug: string, label: string, config_path: string, kind: string, notes: string}>
	 */
	public static function clients(): array {
		$clients = [];
		foreach ( ClientCatalog::all() as $client ) {
			$clients[] = [
				'slug'        => (string) $client['slug'],
				'label'       => (string) $client['label'],
				'config_path' => (string) $client['config_path'],
				'kind'        => (string) $client['kind'],
				'notes'       => (string) $client['notes'] . ' ' . McpUsePolicy::client_note_suffix(),
			];
		}

		return $clients;
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
	public static function native_stdio_snippet( string $username = '', string $app_password = '', string $tool_profile = '' ): array {
		$tool_profile = '' === trim( $tool_profile ) ? AbilityRegistry::mcp_surface() : $tool_profile;
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
			$tool_profile = self::profile_for_client( $client_slug );
			return [
				'command' => sprintf(
					'claude mcp add stonewright --env STONEWRIGHT_WP_URL=%s --env STONEWRIGHT_WP_USERNAME=%s --env STONEWRIGHT_WP_APP_PASSWORD=%s --env STONEWRIGHT_MCP_TOOL_PROFILE=%s -- npx -y --package %s stonewright-mcp',
					escapeshellarg( self::site_url() ),
					escapeshellarg( $username ),
					escapeshellarg( $app_password ?: '<your-application-password>' ),
					$tool_profile,
					escapeshellarg( self::companion_package_spec() )
				),
			];
		}

		if ( 'codex' === $client_slug ) {
			return [
				'toml' => self::codex_toml_snippet( $username, $app_password, self::profile_for_client( $client_slug ) ),
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
		$tool_profile = AbilityRegistry::mcp_surface();
		$prompt = sprintf(
			/* translators: 1: site URL, 2: MCP endpoint URL, 3: username, 4: Application Password, 5: companion package URL, 6: selected MCP tool surface. */
			__(
				"Configure Stonewright MCP for this WordPress install in the current AI client.\n\nUse these connection values:\n- WordPress URL: %1\$s\n- MCP endpoint: %2\$s\n- Username: %3\$s\n- Application Password: %4\$s\n- MCP server name: stonewright\n- Local transport: npx -y --package %5\$s stonewright-mcp\n\nConfiguration rules:\n- Store credentials as env vars only: STONEWRIGHT_WP_URL, STONEWRIGHT_WP_USERNAME, STONEWRIGHT_WP_APP_PASSWORD.\n- Set STONEWRIGHT_MCP_TOOL_PROFILE=%6\$s so new MCP sessions start with the MCP tool surface saved in Stonewright Setup.\n- Use STONEWRIGHT_MCP_TOOL_PROFILE=low-tools for Antigravity, Gemini API, or other strict tool-cap clients.\n- Use command `npx` with args `[\"-y\", \"--package\", \"%5\$s\", \"stonewright-mcp\"]`; npx downloads the versioned GitHub release tarball and runs the explicit companion bin, so no global companion install or npm registry publishing is required.\n- Do not configure generic WordPress MCP adapters such as `@automattic/mcp-wordpress-remote` as the `stonewright` server; use the Stonewright companion so setup, status, compact profiles, php-execute, and WP-CLI tools stay visible during endpoint recovery.\n- Do not use `node companion/dist/index.js` in IDE MCP configs; dist is a source build artifact and is intentionally not committed. For source development, use `npm --prefix <repo>/companion run mcp:source`.\n- Use stonewright-php-execute for direct full WordPress runtime snippets; keep WP-CLI for tokenized command workflows.\n- Restart or reload the MCP session after saving the config.\n\nAfter reload:\n- Verify the MCP tool list includes stonewright-task-start (stonewright-context-bootstrap is the compatibility fallback). If stonewright-task-start is missing, stop and tell me the Stonewright MCP server did not load.\n- First Stonewright call after connection: stonewright-task-start with the task, surface, and intent. context-bootstrap and workflow-preflight are compatibility paths only.\n- Do not start by only announcing named skills. Stonewright skills are guidance returned by MCP; they do not replace live tool calls.\n- Do not treat local agent skills as a substitute for live Stonewright MCP tools.\n- If stonewright-task-start and stonewright-context-bootstrap are both missing, stop and tell me the Stonewright MCP server did not load. Ask me to reload or fix the MCP config, or to open the Stonewright JSON snippets panel.\n- Do not inspect private AI-client config files, parse repository files, or hand-roll JSON-RPC calls to the WordPress MCP endpoint as an MCP workaround.\n- Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.\n- Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.\n- Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.\n- Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.\n- Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.\n- Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.\n- Do not run wp commands or WP-CLI eval entry points in a normal shell.\n\nBrowser testing:\n- If this client does not already have browser tools, also add Playwright MCP for browser testing, screenshots, and visual QA.\n- Playwright MCP config: command `npx`, args `[\"-y\", \"@playwright/mcp@latest\", \"--caps=testing,vision,devtools\"]`.\n- For visual WordPress or Elementor work, confirm Playwright/browser tools are visible before the first write.\n\nIf you cannot edit the client config here, ask me to open the Stonewright JSON snippets panel.",
				'stonewright'
			),
			self::site_url(),
			self::mcp_endpoint_url(),
			$username,
			$app_password,
			self::companion_package_spec(),
			$tool_profile
		);

		return $prompt
			. "\n- After every Stonewright release or skill sync, restart the MCP client, then run stonewright-setup-profile and stonewright-wordpress-mcp-status."
			. "\n- Compare companion_version, expected_companion_package, and refresh_required_tool_names with the visible MCP tool list; if required tools are missing, the client is still using an old companion process or cached tool surface."
			. "\n- For Codex, put the Stonewright entry in ~/.codex/config.toml or a trusted project .codex/config.toml, then restart Codex or reload the IDE MCP session and use /mcp to verify it is active."
			. "\n- Use fast_path.tool_profile from stonewright-task-start before making a separate stonewright-tool-profile call; call tool-profile only to switch or verify a compact profile.";
	}

	private static function codex_toml_snippet( string $username, string $app_password, string $tool_profile ): string {
		$args = array_map( [ self::class, 'toml_string' ], self::companion_mcp_args() );
		$env  = [
			'STONEWRIGHT_WP_URL'          => self::site_url(),
			'STONEWRIGHT_WP_USERNAME'     => $username ?: 'your-wp-username',
			'STONEWRIGHT_WP_APP_PASSWORD'  => $app_password ?: '<your-application-password>',
			'STONEWRIGHT_MCP_TOOL_PROFILE' => self::normalise_tool_profile( $tool_profile ),
		];

		$lines = [
			'[mcp_servers.stonewright]',
			'command = "npx"',
			'args = [' . implode( ', ', $args ) . ']',
			'',
			'[mcp_servers.stonewright.env]',
		];

		foreach ( $env as $key => $value ) {
			$lines[] = $key . ' = ' . self::toml_string( $value );
		}

		return implode( "\n", $lines );
	}

	private static function toml_string( string $value ): string {
		return '"' . str_replace(
			[ '\\', '"', "\r", "\n", "\t" ],
			[ '\\\\', '\"', '\r', '\n', '\t' ],
			$value
		) . '"';
	}

	private static function profile_for_client( string $client_slug ): string {
		return in_array( $client_slug, [ 'antigravity', 'gemini-cli' ], true )
			? 'low-tools'
			: AbilityRegistry::mcp_surface();
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
