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

	/**
	 * Ordered client chooser shared with the OAuth panel.
	 *
	 * @return array<int, array{slug: string, label: string, config_path: string, kind: string, notes: string, snippet_kind: string}>
	 */
	public static function chooser_clients(): array {
		$clients = [];
		foreach ( OAuthClientConfig::client_labels() as $slug => $label ) {
			$meta    = ClientCatalog::get( $slug );
			$notes   = (string) ( $meta['notes'] ?? '' );
			$clients[] = [
				'slug'         => $slug,
				'label'        => $label,
				'config_path'  => (string) ( $meta['config_path'] ?? '' ),
				'kind'         => (string) ( $meta['kind'] ?? 'editor' ),
				'notes'        => $notes . ' ' . McpUsePolicy::client_note_suffix(),
				'snippet_kind' => (string) ( $meta['snippet_kind'] ?? 'json' ),
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
		$server_name  = self::mcp_server_name();
		return [
			'mcpServers' => [
				$server_name => [
					'command' => 'npx',
					'args'    => self::companion_mcp_args(),
					'env'     => [
						'STONEWRIGHT_MODE'            => 'plugin',
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
		$server_name = self::mcp_server_name();
		return [
			'mcpServers' => [
				$server_name => [
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
		$known_slugs = array_values(
			array_unique(
				array_merge(
					array_column( self::clients(), 'slug' ),
					array_keys( OAuthClientConfig::client_labels() )
				)
			)
		);
		if ( ! in_array( $client_slug, $known_slugs, true ) ) {
			return new \WP_Error(
				'stonewright_unknown_client',
				sprintf( __( 'Unknown client slug: %s', 'stonewright' ), $client_slug )
			);
		}

		$resolved = self::resolve_client_slug( $client_slug );

		if ( 'claude-code' === $resolved ) {
			$server_name = self::mcp_server_name();
			if ( 'http' === $transport ) {
				$credentials = base64_encode( $username . ':' . ( $app_password ?: '<your-application-password>' ) );
				return [
					'command' => sprintf(
						'claude mcp add %s --transport http --url %s --header "Authorization: Basic %s"',
						escapeshellarg( $server_name ),
						escapeshellarg( self::mcp_endpoint_url() ),
						$credentials
					),
				];
			}
			$tool_profile = self::profile_for_client( $resolved );
			return [
				'command' => sprintf(
					'claude mcp add %s --env STONEWRIGHT_MODE=plugin --env STONEWRIGHT_WP_URL=%s --env STONEWRIGHT_WP_USERNAME=%s --env STONEWRIGHT_WP_APP_PASSWORD=%s --env STONEWRIGHT_MCP_TOOL_PROFILE=%s -- npx -y --package %s stonewright-mcp',
					escapeshellarg( $server_name ),
					escapeshellarg( self::site_url() ),
					escapeshellarg( $username ),
					escapeshellarg( $app_password ?: '<your-application-password>' ),
					$tool_profile,
					escapeshellarg( self::companion_package_spec() )
				),
			];
		}

		if ( in_array( $resolved, [ 'codex', 'codex-cli' ], true ) ) {
			return [
				'toml' => self::codex_toml_snippet( $username, $app_password, self::profile_for_client( $resolved ) ),
			];
		}

		$snippet = 'http' === $transport
			? self::http_snippet_for( $resolved, $username, $app_password )
			: self::native_stdio_snippet( $username, $app_password, self::profile_for_client( $resolved ) );

		if ( in_array( $resolved, [ 'vscode', 'vscode-copilot', 'github-copilot' ], true ) ) {
			if ( isset( $snippet['mcpServers'] ) ) {
				$snippet = [ 'servers' => $snippet['mcpServers'] ];
			}
		}

		if ( 'zed' === $resolved && isset( $snippet['mcpServers'] ) ) {
			return [ 'context_servers' => $snippet['mcpServers'] ];
		}

		if ( 'cursor' === $resolved ) {
			$servers = $snippet['mcpServers'] ?? $snippet['servers'] ?? [];
			if ( is_array( $servers ) && [] !== $servers ) {
				$name  = (string) array_key_first( $servers );
				$inner = $servers[ $name ] ?? null;
				if ( '' !== $name && is_array( $inner ) ) {
					$snippet['deeplink'] = OAuthClientConfig::cursor_deeplink( $name, $inner );
				}
			}
		}

		return $snippet;
	}

	/**
	 * Client-aware companion startup profile.
	 * The explicit site surface is the source of truth. Strict-cap clients keep
	 * their bounded override because they cannot safely accept the full catalog.
	 */
	public static function recommended_startup_profile( string $client_slug = '' ): string {
		$client_slug = self::resolve_client_slug( sanitize_key( $client_slug ) );
		if ( in_array( $client_slug, [ 'antigravity', 'antigravity-cli', 'gemini-cli' ], true ) ) {
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
		$prompt       = sprintf(
			/* translators: 1: companion package URL, 2: selected MCP tool surface. */
			__(
				"Configure this installed Stonewright plugin in the current AI client. Choose exactly one transport for this site; do not combine a local stdio entry and an OAuth HTTP entry under the same server name. Do not ask me to paste, reveal, print, or commit a site URL, username, Application Password, token, or private client config.\n\nLOCAL STDIO WITH COMPANION (recommended when local WP-CLI or Direct fallback is needed)\nUse the versioned installer, a unique site alias, a collision-safe named server, and plugin-only mode:\n\nnpx -y --package %1\$s stonewright connect add --alias <unique-alias> --url <your-wordpress-url> --username <your-wordpress-username> --env <environment> --mode plugin-only --client <client> --profile %2\$s --plugin-enabled yes --wp-mode <development|staging|production-safe> --wp-surface %2\$s --elementor-v4 <yes|no>\n\nI will replace private placeholders locally. Let the installer request the Application Password through its hidden prompt, or use --password-env with a temporary environment variable. Never put a password on argv. Do not create or overwrite a generic server named `stonewright`; the installer must create or reuse the alias-specific named entry. ~/.stonewright/sites.json may contain credential_ref values, never plaintext credentials.\n\nIf the alias already exists and the saved credential must be reused, do not add another alias and do not ask for the password again. Run:\n\nnpx -y --package %1\$s stonewright connect repair <alias> --client <client> --mode plugin-only\n\nThen fully restart the MCP client and run:\n\nnpx -y --package %1\$s stonewright connect verify <alias> --client <client>\n\nREMOTE OAUTH HTTP (alternative, no companion)\nWhen the client supports Streamable HTTP OAuth, create a separately named remote connection to <your-wordpress-url>/wp-json/mcp/stonewright-oauth. Do not also install a local companion entry for that same connection. The Application Password route is <your-wordpress-url>/wp-json/mcp/stonewright and is a separate non-OAuth transport.\n\nConnection rules:\n- Local stdio means this AI client starts the companion and communicates through standard input/output. The alias is the routing authority; legacy STONEWRIGHT_WP_* values must never override it.\n- Remote HTTP connects directly to the WordPress plugin over HTTPS and does not run a local companion.\n- Keep credentials only in the OS credential store, an explicit environment reference, or the private client configuration. Never put them in chat, repository files, memory, skills, audit examples, or shell history.\n- For custom PHP/CSS/JS/HTML writes, run the approval-gated typed tool with dry_run first. Show me approval_url, exact path, byte counts, and a short summary, then stop. Never open the approval page, issue or retrieve a grant, or apply with custom_code_grant unless I explicitly ask you to perform that approval step.\n- Do not substitute a generic WordPress MCP adapter, inspect private client config, hand-roll JSON-RPC/REST runners, create scratch scripts, or run shell WP-CLI as a workaround.\n\nVerify after restart:\n1. The verifier must report the requested alias, configured_mode=plugin-only, active_mode=plugin, and the expected companion version. If it reports another site or Direct mode, stop: the wrong named server is active.\n2. Confirm stonewright-task-start is visible, then call it first with a non-empty task, surface, and intent. stonewright-context-bootstrap is compatibility fallback only.\n3. Read fast_path.tool_profile; use stonewright-tool-profile only to switch or verify a profile.\n4. Run stonewright-setup-profile and stonewright-wordpress-mcp-status. Confirm the target site, active alias, companion_version, expected_companion_package, and that refresh_required_tool_names is empty.\n5. If any required tool is missing or the target differs, fully reload the client; never bypass Stonewright.\n\nFor visual WordPress work, also confirm the user-approved browser provider is available before the first write.",
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
		$server_name = self::mcp_server_name();
		$env  = [
			'STONEWRIGHT_MODE'            => 'plugin',
			'STONEWRIGHT_WP_URL'          => self::site_url(),
			'STONEWRIGHT_WP_USERNAME'     => $username ?: 'your-wp-username',
			'STONEWRIGHT_WP_APP_PASSWORD'  => $app_password ?: '<your-application-password>',
			'STONEWRIGHT_MCP_TOOL_PROFILE' => self::normalise_tool_profile( $tool_profile ),
		];

		$lines = [
			'[mcp_servers.' . $server_name . ']',
			'command = "npx"',
			'args = [' . implode( ', ', $args ) . ']',
			'',
			'[mcp_servers.' . $server_name . '.env]',
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
		$client_slug = self::resolve_client_slug( $client_slug );
		if ( in_array( $client_slug, [ 'antigravity', 'antigravity-cli', 'gemini-cli' ], true ) ) {
			return 'low-tools';
		}
		// Follow the explicit site surface unless the client has a strict cap.
		return self::recommended_startup_profile( $client_slug );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function http_snippet_for( string $client_slug, string $username, string $app_password ): array {
		$base        = self::http_snippet( $username, $app_password );
		$server_name = self::mcp_server_name();
		$entry       = $base['mcpServers'][ $server_name ];
		$url         = (string) ( $entry['url'] ?? self::mcp_endpoint_url() );

		if ( 'windsurf' === $client_slug ) {
			unset( $entry['url'] );
			$entry['serverUrl'] = $url;
			$base['mcpServers'][ $server_name ] = $entry;
			return $base;
		}

		if ( 'gemini-cli' === $client_slug ) {
			unset( $entry['url'] );
			$entry['httpUrl'] = $url;
			$base['mcpServers'][ $server_name ] = $entry;
			return $base;
		}

		if ( in_array( $client_slug, [ 'vscode', 'vscode-copilot', 'github-copilot' ], true ) ) {
			$entry['type'] = 'http';
			return [ 'servers' => [ $server_name => $entry ] ];
		}

		return $base;
	}

	private static function resolve_client_slug( string $client_slug ): string {
		$map = [
			'vscode'    => 'vscode-copilot',
			'vs-code'   => 'vscode-copilot',
			'codex-cli' => 'codex',
			'claude'    => 'claude-desktop',
		];
		$client_slug = sanitize_key( $client_slug );
		return $map[ $client_slug ] ?? $client_slug;
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

	private static function mcp_server_name(): string {
		$url      = self::site_url();
		$host     = (string) parse_url( $url, PHP_URL_HOST );
		$path     = trim( (string) parse_url( $url, PHP_URL_PATH ), '/' );
		$identity = '' === $path ? $host : $host . '-' . $path;
		$slug     = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $identity ) );
		$slug = trim( $slug, '-' );
		return '' === $slug ? 'stonewright-site' : 'stonewright-' . $slug;
	}
}
