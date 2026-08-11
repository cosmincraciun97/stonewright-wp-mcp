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

	public static function companion_package_spec( string $version = '' ): string {
		if ( '' === $version ) {
			$version = defined( 'STONEWRIGHT_VERSION' ) ? (string) constant( 'STONEWRIGHT_VERSION' ) : '0.0.0';
		}

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
	 * Client-aware companion startup profile.
	 * The explicit site surface is the source of truth. Strict-cap clients keep
	 * their bounded override because they cannot safely accept the full catalog.
	 */
	public static function recommended_startup_profile( string $client_slug = '' ): string {
		$client_slug = sanitize_key( $client_slug );
		if ( in_array( $client_slug, [ 'antigravity', 'gemini-cli' ], true ) ) {
			return 'essential-static';
		}
		return AbilityRegistry::mcp_surface();
	}

	/**
	 * Returns a credential-free prompt for the user to configure the agent.
	 *
	 * Username and Application Password remain parameters for backwards
	 * compatibility, but are deliberately never interpolated into agent chat.
	 *
	 * @param string $username     WordPress username (never included).
	 * @param string $app_password Application Password (never included).
	 * @param string $client_slug  Optional client for recommended profile.
	 */
	public static function paste_to_agent_prompt( string $username, string $app_password, string $client_slug = '' ): string {
		unset( $username, $app_password );
		$tool_profile = self::recommended_startup_profile( $client_slug );
		$installer_intro = __(
			"For local stdio, prefer the versioned `stonewright connect add` installer. Ask for a unique alias, environment, Direct/Plugin policy, WordPress mode, MCP surface, Elementor V4 choice, and client. Use a hidden password prompt or --password-env, never a password on argv. Persist these choices per site/client and generate a collision-safe named server entry. Keep secrets in the private client config, OS credential store, or an explicit env reference; ~/.stonewright/sites.json may contain a credential_ref but never plaintext. After saving, fully restart the MCP client and run stonewright connect verify <alias> --client <client>. Require the spawned companion version, active alias, task-start, status, and required tools; a parseable config file is not runtime proof.",
			'stonewright'
		);
		$prompt = $installer_intro . "\n\n" . sprintf(
			/* translators: 1: companion package URL, 2: selected MCP tool surface. */
			__(
				"Configure Stonewright MCP in the current AI client. Do not ask me to paste, reveal, print, or commit a site URL, username, Application Password, token, or private client config.\n\nBuild a private config with placeholders that I will replace locally:\n- MCP server name: stonewright\n- WordPress URL env: STONEWRIGHT_WP_URL=<your-wordpress-url>\n- Username env: STONEWRIGHT_WP_USERNAME=<your-wordpress-username>\n- Application Password env: STONEWRIGHT_WP_APP_PASSWORD=<your-application-password>\n- Tool surface env: STONEWRIGHT_MCP_TOOL_PROFILE=%2\$s\n- Local stdio command: npx\n- Local stdio args: [\"-y\", \"--package\", \"%1\$s\", \"stonewright-mcp\"]\n- Remote HTTP endpoint shape: <your-wordpress-url>/wp-json/mcp/stonewright\n\nConnection meaning:\n- Local stdio means this AI client starts the companion on this computer and communicates with it through standard input/output. The companion is required for local stdio, Direct mode, and local WP-CLI.\n- Remote HTTP connects directly to the WordPress plugin over HTTPS. It does not run or require a local companion.\n\nRules:\n- Prefer OAuth when this client supports Streamable HTTP; it keeps the Application Password out of copied config.\n- Keep credentials only in the private client config or ~/.stonewright/sites.json. Never put them in chat, repository files, memory, skills, audit examples, or commands saved in shell history.\n- For any custom PHP/CSS/JS/HTML write, run the approval-gated typed tool with dry_run first. Show me approval_url, exact path, byte counts, and a short summary, then stop. Never open the approval page, issue or retrieve a grant, or apply with custom_code_grant unless I explicitly ask you to perform that approval step.\n- Do not substitute a generic WordPress MCP adapter, inspect private client config, hand-roll JSON-RPC/REST runners, create scratch scripts, or run shell WP-CLI as a workaround.\n- Save the config and fully restart or reload the MCP session.\n\nVerify after reload:\n1. Confirm stonewright-task-start is visible. stonewright-context-bootstrap is compatibility fallback only.\n2. Call stonewright-task-start first with task, surface, and intent.\n3. Read fast_path.tool_profile; use stonewright-tool-profile only to switch or verify a profile.\n4. Run stonewright-setup-profile and stonewright-wordpress-mcp-status. Confirm companion_version matches expected_companion_package and refresh_required_tool_names is empty.\n5. If required tools are missing, stop. Reload the client or update the pinned companion package; do not bypass Stonewright.\n\nFor visual WordPress work, also confirm the user-approved browser provider is available before the first write.",
				'stonewright'
			),
			self::companion_package_spec(),
			$tool_profile
		);

		return $prompt . "\n\n" . __(
			"Browser automation consent:\n- Ask me once whether this site/client should use Playwright (recommended default), another already-connected browser, or no browser automation.\n- Do not scan my private config or client tool surface without permission. If the selected provider is not visible, ask once for scan permission, then ask separately before installing or configuring anything. Never install or reconfigure a browser provider silently.\n- Browser automation is for verification or explicitly approved WordPress dashboard interaction. It never bypasses custom-code dry-run, approval, backup, permission, or confirmation gates.",
			'stonewright'
		);
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
		if ( in_array( $client_slug, [ 'antigravity', 'gemini-cli' ], true ) ) {
			return 'low-tools';
		}
		// Follow the explicit site surface unless the client has a strict cap.
		return self::recommended_startup_profile( $client_slug );
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
