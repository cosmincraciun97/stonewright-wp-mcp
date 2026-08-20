<?php
/**
 * SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Derived from includes/connect-methods.php
 * Source SHA-256: f3c46c9cfaf027a864c1671063d10b0b0c945c0458a9fa952bb86c668feb55db
 *
 * @package Stonewright\WpMcp
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Build OAuth onboarding payloads for supported MCP clients.
 */
final class OAuthClientConfig {

	/**
	 * @return array<string, string>
	 */
	public static function client_labels(): array {
		return [
			'chatgpt-desktop' => 'Codex in ChatGPT Desktop',
			'chatgpt'         => 'ChatGPT',
			'claude-ai'       => 'Claude.ai',
			'claude-desktop'  => 'Claude Desktop',
			'claude-code'     => 'Claude Code',
			'windsurf'        => 'Windsurf',
			'codex-cli'       => 'Codex CLI',
			'antigravity'     => 'Antigravity',
			'antigravity-cli' => 'Antigravity CLI',
			'cursor'          => 'Cursor',
			'vscode'          => 'VS Code',
			'vscode-copilot'  => 'VS Code (Copilot)',
			'github-copilot'  => 'GitHub Copilot',
			'cline'           => 'Cline',
			'gemini-cli'      => 'Gemini CLI',
			'roo-code'        => 'Roo Code',
			'amazon-q'        => 'Amazon Q',
			'zed'             => 'Zed',
			'kilo-code'       => 'Kilo Code',
			'opencode'        => 'OpenCode',
			'generic-mcp'     => 'Generic MCP',
		];
	}

	/**
	 * Hidden chip aliases kept for saved client selections.
	 *
	 * @return array<string, string> alias slug => canonical slug
	 */
	public static function client_slug_aliases(): array {
		return [
			'codex' => 'codex-cli',
		];
	}

	public static function resolve_client_slug( string $slug ): string {
		return self::client_slug_aliases()[ $slug ] ?? $slug;
	}

	/**
	 * Cursor one-click install URL.
	 *
	 * Encodes the inner server object (not an mcpServers wrapper) as base64url
	 * so query parsers cannot turn `+` into a space or reject `/` and `=`.
	 *
	 * @param array<string, mixed> $server Server config.
	 */
	public static function cursor_deeplink( string $mcp_name, array $server ): string {
		$json   = (string) wp_json_encode( $server, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$config = self::base64url_encode( $json );
		return 'cursor://anysphere.cursor-deeplink/mcp/install?name=' . rawurlencode( $mcp_name )
			. '&config=' . $config;
	}

	public static function default_server_name(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '/^www\./', '', strtolower( $host ) ) ?? '';
		foreach ( [ '.local', '.test', '.localhost', '.ddev.site', '.lndo.site' ] as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				$host = substr( $host, 0, -strlen( $suffix ) );
				break;
			}
		}
		$slug  = preg_replace( '/[^a-z0-9-]+/', '-', $host ) ?? '';
		$slug  = trim( $slug, '-' );
		$parts = array_values( array_filter( explode( '-', $slug ) ) );
		while ( count( $parts ) >= 2 && $parts[ count( $parts ) - 1 ] === $parts[ count( $parts ) - 2 ] ) {
			array_pop( $parts );
		}
		$slug = implode( '-', $parts );
		$slug = rtrim( substr( $slug, 0, 16 ), '-' );
		return 'stonewright-' . ( '' !== $slug ? $slug : 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- machine identifier.
	}

	public static function connector_install_link( string $mcp_url, string $connector_name ): string {
		return 'https://claude.ai/customize/connectors?modal=add-custom-connector'
			. '&connectorName=' . rawurlencode( $connector_name )
			. '&connectorUrl=' . rawurlencode( $mcp_url );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function configs( string $mcp_url, string $mcp_name ): array {
		if ( self::host_unreachable_from_cloud() ) {
			$configs = self::bridge_configs( $mcp_url, $mcp_name, self::local_bridge_environment() );
			$chatgpt_desktop = is_array( $configs['chatgpt-desktop'] ?? null ) ? $configs['chatgpt-desktop'] : [];
			$claude_desktop  = is_array( $configs['claude-desktop'] ?? null ) ? $configs['claude-desktop'] : [];
			$configs['chatgpt'] = array_merge(
				$chatgpt_desktop,
				[
					'message' => 'ChatGPT on the web cannot reach a site that exists only on this machine. ChatGPT Desktop can use this local mcp-remote bridge.',
				]
			);
			$configs['claude-ai'] = array_merge(
				$claude_desktop,
				[
					'message' => 'Claude.ai cannot reach a site that exists only on this machine. Use Claude Desktop or Claude Code with the local mcp-remote bridge below.',
				]
			);
			return self::order( $configs );
		}

		return self::public_configs( $mcp_url, $mcp_name );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function public_configs( string $mcp_url, string $mcp_name ): array {
		$bridge = self::bridge_configs( $mcp_url, $mcp_name, [] );
		$native = self::native_configs( $mcp_url, $mcp_name );
		foreach ( [ 'antigravity', 'antigravity-cli', 'cline', 'roo-code', 'amazon-q', 'zed', 'kilo-code', 'opencode' ] as $slug ) {
			if ( isset( $bridge[ $slug ] ) ) {
				$native[ $slug ] = $bridge[ $slug ];
			}
		}
		return self::order( $native );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function native_configs( string $mcp_url, string $mcp_name ): array {
		$connector_name = 'Stonewright - ' . trim( get_bloginfo( 'name' ) );
		$connector      = self::connector_install_link( $mcp_url, rtrim( $connector_name, ' -' ) );
		$cursor_server  = [ 'url' => $mcp_url ];
		$cursor_link    = self::cursor_deeplink( $mcp_name, $cursor_server );
		$codex_cli      = self::entry(
			"[mcp_servers.{$mcp_name}]\nurl = " . self::toml_quote( $mcp_url ),
			'Add to config.toml, then run codex mcp login ' . $mcp_name . '.',
			[
				'macOS / Linux' => '~/.codex/config.toml',
				'Windows'       => '%USERPROFILE%\\.codex\\config.toml',
			],
			false,
			'codex mcp add ' . $mcp_name . ' --url ' . $mcp_url . "\n"
				. 'codex mcp login ' . $mcp_name . ' --scopes mcp,offline_access'
		);
		$vscode         = self::entry(
			self::json( 'servers', $mcp_name, [ 'type' => 'http', 'url' => $mcp_url ] ),
			'Add to mcp.json.',
			[ 'Workspace' => '.vscode/mcp.json', 'User' => 'Run: MCP: Open User Configuration' ]
		);

		return [
			'claude-code' => self::entry(
				'claude mcp add ' . $mcp_name . ' --transport http ' . $mcp_url,
				'Run in your terminal, then sign in when your browser opens.',
				[],
				true
			),
			'claude-desktop' => [
				'kind'  => 'code',
				'code'  => '',
				'hint'  => '',
				'paths' => [],
				'steps' => self::connector_steps( 'Claude Desktop', $mcp_name, $mcp_url ),
			],
			'claude-ai' => [
				'kind'      => 'connector',
				'code'      => '',
				'hint'      => 'Add it as a custom connector, then sign in.',
				'paths'     => [],
				'connector' => $connector,
				'steps'     => self::connector_steps( 'Claude.ai', $mcp_name, $mcp_url ),
			],
			'chatgpt' => [
				'kind'  => 'code',
				'code'  => '',
				'hint'  => '',
				'paths' => [],
				'steps' => self::chatgpt_steps( $mcp_name, $mcp_url ),
			],
			'chatgpt-desktop' => self::entry(
				self::json( 'mcpServers', $mcp_name, [ 'url' => $mcp_url ] ),
				'Add to ChatGPT Desktop mcp_config.json, then restart the app.',
				[
					'macOS'   => '~/Library/Application Support/ChatGPT/mcp_config.json',
					'Windows' => '%APPDATA%\\ChatGPT\\mcp_config.json',
				]
			),
			'codex-cli' => $codex_cli,
			'codex'     => $codex_cli,
			'cursor'    => self::entry(
				self::json( 'mcpServers', $mcp_name, $cursor_server ),
				'Use the one-click button, or add to mcp.json.',
				[ 'Global' => '~/.cursor/mcp.json', 'Project' => '.cursor/mcp.json' ],
				false,
				'',
				[ 'deeplink' => $cursor_link ]
			),
			'vscode'          => $vscode,
			'vscode-copilot'  => $vscode,
			'generic-mcp'     => self::entry(
				self::json( 'mcpServers', $mcp_name, [ 'url' => $mcp_url ] ),
				'Add to your client MCP config.',
				[ 'Client-specific' => 'Client-specific MCP config' ]
			),
			'github-copilot' => self::entry(
				self::json( 'servers', $mcp_name, [ 'type' => 'http', 'url' => $mcp_url ] ),
				'Add to mcp.json.',
				[ 'Project' => '.github/copilot/mcp.json' ]
			),
			'windsurf' => self::entry(
				self::json( 'mcpServers', $mcp_name, [ 'serverUrl' => $mcp_url ] ),
				'Add to mcp_config.json.',
				[
					'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json',
					'Windows'       => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json',
				]
			),
			'gemini-cli' => self::entry(
				self::json( 'mcpServers', $mcp_name, [ 'httpUrl' => $mcp_url ] ),
				'Add to settings.json.',
				[ 'Global' => '~/.gemini/settings.json', 'Project' => '.gemini/settings.json' ]
			),
		];
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function bridge_configs( string $mcp_url, string $mcp_name, array $environment ): array {
		$server = [
			'command' => 'npx',
			'args'    => [ '-y', 'mcp-remote', $mcp_url ],
		];
		if ( [] !== $environment ) {
			$server['env'] = $environment;
		}
		$mcp_servers = self::json( 'mcpServers', $mcp_name, $server );
		$servers     = self::json( 'servers', $mcp_name, $server );

		$configs = [
			'claude-code' => self::entry(
				'claude mcp add ' . $mcp_name . ' -- npx -y mcp-remote ' . $mcp_url,
				'Run in your terminal, then sign in when your browser opens.',
				[],
				true
			),
			'codex' => self::entry(
				"[mcp_servers.{$mcp_name}]\ncommand = \"npx\"\nargs = [\"-y\", \"mcp-remote\", "
					. self::toml_quote( $mcp_url ) . ']',
				'Add to config.toml.',
				[
					'macOS / Linux' => '~/.codex/config.toml',
					'Windows'       => '%USERPROFILE%\\.codex\\config.toml',
				]
			),
			'zed' => self::entry(
				(string) wp_json_encode(
					[
						'context_servers' => [
							$mcp_name => array_merge( [ 'source' => 'custom', 'enabled' => true ], $server ),
						],
					],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
				'Add to settings.json.',
				[ 'macOS / Linux' => '~/.config/zed/settings.json' ]
			),
			'opencode' => self::entry(
				(string) wp_json_encode(
					[
						'mcp' => [
							$mcp_name => [
								'type'    => 'local',
								'command' => [ 'npx', '-y', 'mcp-remote', $mcp_url ],
							],
						],
					],
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				),
				'Add to opencode.json.',
				[ 'Project' => 'opencode.json', 'Global' => '~/.config/opencode/opencode.json' ]
			),
		];

		$standard = [
			'claude-desktop'   => [ $mcp_servers, 'claude_desktop_config.json', [ 'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json', 'Windows' => '%APPDATA%\\Claude\\claude_desktop_config.json' ] ],
			'chatgpt-desktop'  => [ $mcp_servers, 'mcp_config.json', [ 'macOS' => '~/Library/Application Support/ChatGPT/mcp_config.json', 'Windows' => '%APPDATA%\\ChatGPT\\mcp_config.json' ] ],
			'antigravity'      => [ $mcp_servers, 'mcp_config.json', [ 'macOS / Linux' => '~/.gemini/config/mcp_config.json', 'Windows' => '%USERPROFILE%\\.gemini\\config\\mcp_config.json' ] ],
			'vscode'           => [ $servers, 'mcp.json', [ 'Workspace' => '.vscode/mcp.json', 'User' => 'Run: MCP: Open User Configuration' ] ],
			'github-copilot'   => [ $servers, 'mcp.json', [ 'Project' => '.github/copilot/mcp.json' ] ],
			'windsurf'         => [ $mcp_servers, 'mcp_config.json', [ 'macOS / Linux' => '~/.codeium/windsurf/mcp_config.json', 'Windows' => '%USERPROFILE%\\.codeium\\windsurf\\mcp_config.json' ] ],
			'cline'            => [ $mcp_servers, 'cline_mcp_settings.json', [ 'Via UI' => 'Cline sidebar → MCP Servers → Configure MCP Servers' ] ],
			'gemini-cli'       => [ $mcp_servers, 'settings.json', [ 'Global' => '~/.gemini/settings.json', 'Project' => '.gemini/settings.json' ] ],
			'roo-code'         => [ $mcp_servers, 'mcp.json', [ 'Project' => '.roo/mcp.json', 'Via UI' => 'Roo Code sidebar → MCP Servers → Configure MCP Servers' ] ],
			'amazon-q'         => [ $mcp_servers, 'mcp.json', [ 'Global' => '~/.aws/amazonq/mcp.json', 'Project' => '.amazonq/mcp.json' ] ],
			'kilo-code'        => [ $mcp_servers, 'mcp.json', [ 'Project' => '.kilocode/mcp.json', 'Via UI' => 'Kilo Code sidebar → MCP Servers → Configure MCP Servers' ] ],
			'generic-mcp'      => [ $mcp_servers, 'MCP config', [ 'Client-specific' => 'Client-specific MCP config' ] ],
		];
		foreach ( $standard as $slug => [ $code, $file, $paths ] ) {
			$configs[ $slug ] = self::entry( $code, 'Add to ' . $file . '.', $paths );
		}

		$configs['cursor'] = self::entry(
			$mcp_servers,
			'Use the one-click button, or add to mcp.json.',
			[ 'Global' => '~/.cursor/mcp.json', 'Project' => '.cursor/mcp.json' ],
			false,
			'',
			[ 'deeplink' => self::cursor_deeplink( $mcp_name, $server ) ]
		);
		$configs['codex-cli']        = $configs['codex'];
		$configs['vscode-copilot']   = $configs['vscode'];
		$configs['antigravity-cli']  = $configs['antigravity'];

		return $configs;
	}

	/**
	 * @return list<array<string, string>>
	 */
	private static function connector_steps( string $app_label, string $mcp_name, string $mcp_url ): array {
		return [
			[ 'title' => 'Open Connectors', 'body' => 'In ' . $app_label . ', open Settings and go to Connectors.' ],
			[
				'title' => 'Add a custom connector',
				'body'  => 'Click "Add custom connector" and give it this name:',
				'copy'  => $mcp_name,
			],
			[
				'title' => 'Enter the server URL',
				'body'  => 'Paste the URL below. Leave OAuth Client ID and Secret empty, then sign in.',
				'copy'  => $mcp_url,
			],
		];
	}

	/**
	 * @return list<array<string, string>>
	 */
	private static function chatgpt_steps( string $mcp_name, string $mcp_url ): array {
		return [
			[
				'title' => 'Enable developer mode',
				'body'  => 'In ChatGPT Settings, open Security and login, then turn on Developer mode.',
			],
			[
				'title' => 'Create a plugin',
				'body'  => 'Open Plugins, create a new plugin, and use this name:',
				'copy'  => $mcp_name,
			],
			[
				'title' => 'Enter the server URL',
				'body'  => 'Use OAuth authentication, paste this Server URL, create the plugin, then sign in.',
				'copy'  => $mcp_url,
			],
		];
	}

	/**
	 * @param array<string, string> $paths Paths.
	 * @param array<string, mixed>  $extra Extra fields.
	 * @return array<string, mixed>
	 */
	private static function entry(
		string $code,
		string $hint,
		array $paths,
		bool $is_shell = false,
		string $note = '',
		array $extra = []
	): array {
		return array_merge(
			[
				'kind'    => 'code',
				'code'    => $code,
				'hint'    => $hint,
				'paths'   => $paths,
				'isShell' => $is_shell,
				'note'    => $note,
			],
			$extra
		);
	}

	/**
	 * @param array<string, mixed> $server Server config.
	 */
	private static function json( string $wrapper, string $mcp_name, array $server ): string {
		return (string) wp_json_encode(
			[ $wrapper => [ $mcp_name => $server ] ],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}

	private static function toml_quote( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}

	private static function host_unreachable_from_cloud(): bool {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$host = trim( $host, '[]' );
		if ( ! str_contains( $host, '.' ) ) {
			return true;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		foreach ( [ '.local', '.test', '.localhost', '.ddev.site', '.lndo.site' ] as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string, string>
	 */
	private static function local_bridge_environment(): array {
		return str_starts_with( strtolower( home_url() ), 'https://' )
			? [ 'NODE_TLS_REJECT_UNAUTHORIZED' => '0' ]
			: [];
	}

	/**
	 * @param array<string, array<string, mixed>> $configs Configs.
	 * @return array<string, array<string, mixed>>
	 */
	private static function order( array $configs ): array {
		$ordered = [];
		foreach ( self::client_labels() as $slug => $label ) {
			unset( $label );
			if ( isset( $configs[ $slug ] ) ) {
				$ordered[ $slug ] = $configs[ $slug ];
			}
		}
		foreach ( self::client_slug_aliases() as $alias => $canonical ) {
			unset( $canonical );
			if ( isset( $configs[ $alias ] ) && ! isset( $ordered[ $alias ] ) ) {
				$ordered[ $alias ] = $configs[ $alias ];
			}
		}
		return $ordered;
	}

	private static function base64url_encode( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
