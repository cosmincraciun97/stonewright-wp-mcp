<?php
/**
 * Per-client OAuth setup for the admin connect panel.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @package Stonewright
 */

declare( strict_types=1 );

namespace Stonewright\WpMcp\Admin\Connect;

use Stonewright\WpMcp\Admin\ClientCatalog;

/**
 * How each AI client adds this site as a remote MCP server and signs in with OAuth,
 * following each client's published setup: commands, the configuration block with the
 * file it belongs in, install links where a client defines them, how sign-in starts
 * and the limits users hit. Data only; SignInPanel escapes and renders it.
 *
 * Every catalog client gets a guide. Clients without published browser sign-in steps
 * get the generic steps and a pointer to the Application Password route. Commands quote
 * a value only when a shell would reinterpret it, so the common case can be pasted
 * unchanged into bash, zsh, PowerShell or cmd.
 *
 * @phpstan-type Snippet array{key: string, title: string, format: string, location: string, code: string}
 * @phpstan-type Link array{label: string, url: string, schemes: list<string>}
 * @phpstan-type Guide array{slug: string, label: string, documented: bool, reach: string, tag: string, steps: list<string>, snippets: list<Snippet>, links: list<Link>, sign_in: string, limits: list<string>}
 */
final class ClientInstructions {

	/** The client runs on the user's computer and can reach a local site. */
	public const REACH_LOCAL = 'local';

	/** The client connects from its provider's cloud and needs a site on the public internet. */
	public const REACH_HOSTED = 'hosted';

	/** The client runs locally but signs in only to HTTPS sites on a public address. */
	public const REACH_PUBLIC = 'public';

	private const CURSOR_INSTALL    = 'cursor://anysphere.cursor-deeplink/mcp/install';
	private const CLAUDE_CONNECTORS = 'https://claude.ai/customize/connectors';
	private const CHATGPT_PLUGINS   = 'https://chatgpt.com/plugins';

	/**
	 * Labels of the clients with published OAuth setup, keyed by catalog slug.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			'antigravity'     => __( 'Antigravity', 'stonewright' ),
			'antigravity-cli' => __( 'Antigravity CLI', 'stonewright' ),
			'chatgpt'         => __( 'ChatGPT on the web', 'stonewright' ),
			'claude-ai'       => __( 'Claude.ai (web and mobile)', 'stonewright' ),
			'claude-code'     => __( 'Claude Code', 'stonewright' ),
			'claude-desktop'  => __( 'Claude Desktop', 'stonewright' ),
			'codex'           => __( 'Codex and the ChatGPT desktop app', 'stonewright' ),
			'codex-cli'       => __( 'Codex CLI', 'stonewright' ),
			'cursor'          => __( 'Cursor', 'stonewright' ),
			'gemini-cli'      => __( 'Gemini CLI', 'stonewright' ),
			'generic-mcp'     => __( 'Other MCP clients', 'stonewright' ),
			'github-copilot'  => __( 'GitHub Copilot in VS Code', 'stonewright' ),
			'grok-build'      => __( 'Grok Build', 'stonewright' ),
			'vscode-copilot'  => __( 'VS Code with GitHub Copilot', 'stonewright' ),
			'windsurf'        => __( 'Windsurf (Devin Desktop)', 'stonewright' ),
			'zed'             => __( 'Zed', 'stonewright' ),
		];
	}

	/** Whether the client publishes OAuth setup steps this panel follows. */
	public static function documented( string $slug ): bool {
		return isset( self::labels()[ ClientCatalog::resolve_slug( $slug ) ] );
	}

	/**
	 * One guide per catalog client, sorted by the label shown, with the catch-all guide
	 * for other MCP clients last.
	 *
	 * @return list<Guide>
	 */
	public static function all( string $mcp_url, string $server_name ): array {
		$guides = [];
		foreach ( ClientCatalog::all() as $client ) {
			$guides[] = self::for_client( (string) $client['slug'], $mcp_url, $server_name );
		}
		usort(
			$guides,
			static fn ( array $a, array $b ): int => [ 'generic-mcp' === $a['slug'], strtolower( $a['label'] ) ] <=> [ 'generic-mcp' === $b['slug'], strtolower( $b['label'] ) ]
		);
		return $guides;
	}

	/**
	 * The OAuth setup of one client for this site's MCP URL and server name.
	 *
	 * @return Guide
	 */
	public static function for_client( string $slug, string $mcp_url, string $server_name ): array {
		$slug = ClientCatalog::resolve_slug( $slug );
		$name = self::server_name( $server_name );
		$guide = match ( $slug ) {
			'claude-ai', 'claude-desktop'      => self::claude_connector( $slug, $mcp_url, $name ),
			'claude-code'                      => self::claude_code( $mcp_url, $name ),
			'chatgpt'                          => self::chatgpt( $mcp_url, $name ),
			'codex', 'codex-cli'               => self::codex( $slug, $mcp_url, $name ),
			'cursor'                           => self::cursor( $mcp_url, $name ),
			'vscode-copilot', 'github-copilot' => self::vscode( $mcp_url, $name ),
			'windsurf'                         => self::devin( $mcp_url, $name ),
			'zed'                              => self::zed( $mcp_url, $name ),
			'gemini-cli'                       => self::gemini( $mcp_url, $name ),
			'antigravity', 'antigravity-cli'   => self::antigravity( $slug, $mcp_url, $name ),
			'grok-build'                       => self::grok( $mcp_url, $name ),
			'generic-mcp'                      => self::generic( $mcp_url ),
			default                            => self::undocumented( $slug, $mcp_url ),
		};
		$labels = self::labels();
		return [
			'slug'       => $slug,
			'label'      => $labels[ $slug ] ?? self::catalog_label( $slug ),
			'documented' => isset( $labels[ $slug ] ),
			'reach'      => $guide['reach'] ?? self::REACH_LOCAL,
			'tag'        => $guide['tag'],
			'steps'      => $guide['steps'],
			'snippets'   => $guide['snippets'] ?? [],
			'links'      => $guide['links'] ?? [],
			'sign_in'    => $guide['sign_in'] ?? '',
			'limits'     => $guide['limits'] ?? [],
		];
	}

	/**
	 * Cursor install link: the server object as base64 JSON, the name as a query value.
	 *
	 * @param array<string, mixed> $server Server object without the mcpServers wrapper.
	 */
	public static function cursor_install_link( string $name, array $server ): string {
		return self::CURSOR_INSTALL . '?name=' . rawurlencode( $name ) . '&config=' . base64_encode( (string) wp_json_encode( $server, JSON_UNESCAPED_SLASHES ) );
	}

	/** VS Code install link: the server object, name included, URI-encoded after the scheme. */
	public static function vscode_install_link( string $name, string $mcp_url, string $scheme = 'vscode' ): string {
		return $scheme . ':mcp/install?' . rawurlencode( self::vscode_server( $name, $mcp_url ) );
	}

	/** A server name every client accepts: lowercase letters, digits and single hyphens. */
	public static function server_name( string $candidate ): string {
		$name = trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $candidate ) ), '-' );
		return '' === $name ? 'stonewright' : $name;
	}

	/** @return array<string, mixed> */
	private static function claude_connector( string $slug, string $url, string $name ): array {
		$steps = [
			__( 'In Claude, open Customize > Connectors and choose Add custom connector.', 'stonewright' ),
			/* translators: 1: suggested connector name, 2: MCP server URL. */
			sprintf( __( 'Enter a name such as %1$s and the server URL %2$s', 'stonewright' ), $name, $url ),
			__( "Under Authentication choose Sign in now. Under OAuth client choose Use Claude's published identity, or Register automatically.", 'stonewright' ),
			__( 'Click Add, then Connect, and approve the request on this site.', 'stonewright' ),
			__( 'Team and Enterprise plans: an Owner adds the URL under Organization settings > Connectors > Add > Custom > Web, then members click Connect on the Custom entry in Customize > Connectors.', 'stonewright' ),
		];
		$limits = [
			__( 'Claude connects from its own cloud, not from your computer, so this site must be reachable from the public internet. A site that only runs on your computer or local network cannot connect.', 'stonewright' ),
			__( 'Authentication settings cannot be changed after the connector is added; remove the connector and add it again.', 'stonewright' ),
		];
		if ( 'claude-desktop' === $slug ) {
			array_unshift( $steps, __( 'Claude Desktop adds remote servers as connectors, in the same place as claude.ai. claude_desktop_config.json only holds local servers.', 'stonewright' ) );
		} else {
			$limits[] = __( 'Connectors added on claude.ai also appear in Claude Code when it is signed in with the same account.', 'stonewright' );
		}
		return [
			'reach'   => self::REACH_HOSTED,
			'tag'     => 'claude-desktop' === $slug ? __( 'Hosted connector', 'stonewright' ) : __( 'Hosted app', 'stonewright' ),
			'steps'   => $steps,
			'links'   => [ self::link( __( 'Open Claude connector settings', 'stonewright' ), self::CLAUDE_CONNECTORS, 'https' ) ],
			'sign_in' => __( 'Connect opens this site in your browser. Approve the request and Claude finishes the sign-in.', 'stonewright' ),
			'limits'  => $limits,
		];
	}

	/** @return array<string, mixed> */
	private static function claude_code( string $url, string $name ): array {
		return [
			'tag'      => __( 'Command line', 'stonewright' ),
			'steps'    => [
				__( 'Run the add command. It saves the server for the current project; put --scope user before the name to use it in every project.', 'stonewright' ),
				__( 'Sign in with claude mcp login (Claude Code 2.1.186 or later), or run /mcp in a session and follow the browser steps.', 'stonewright' ),
				/* translators: %s: MCP server name. */
				sprintf( __( 'Over SSH, run claude mcp login %s --no-browser and paste the redirect URL back.', 'stonewright' ), $name ),
				__( 'To share the server with a project, commit the .mcp.json entry instead; Claude Code asks for approval before it uses a project server.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'add', __( 'Add the server', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'claude mcp add --transport http ' . $name . ' ' . self::shell_word( $url ) ),
				self::snippet( 'login', __( 'Sign in', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'claude mcp login ' . $name ),
				self::snippet(
					'project',
					__( 'Share with a project', 'stonewright' ),
					'json',
					'.mcp.json',
					self::json(
						[
							'mcpServers' => [
								$name => [
									'type' => 'http',
									'url'  => $url,
								],
							],
						]
					)
				),
			],
			'sign_in'  => __( 'When this site answers 401, Claude Code marks the server as needing sign-in. Run /mcp or claude mcp login; if the browser redirect fails, paste the callback URL back into Claude Code.', 'stonewright' ),
			'limits'   => [
				__( 'Claude Code sends OAuth credentials only to HTTPS addresses or to localhost, 127.0.0.1 and ::1, so a plain HTTP site on any other host cannot sign in.', 'stonewright' ),
				__( 'Non-interactive runs (claude -p) cannot complete the browser sign-in.', 'stonewright' ),
				__( 'Server names may contain only letters, digits, - and _. The type field is required in .mcp.json.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function chatgpt( string $url, string $name ): array {
		return [
			'reach'   => self::REACH_HOSTED,
			'tag'     => __( 'Hosted app', 'stonewright' ),
			'steps'   => [
				__( 'Open chatgpt.com/plugins, select +, then Add custom MCP server.', 'stonewright' ),
				/* translators: %s: suggested server name. */
				sprintf( __( 'Enter a name such as %s and a short description.', 'stonewright' ), $name ),
				/* translators: %s: MCP server URL. */
				sprintf( __( 'Under Connection enter the server URL %s', 'stonewright' ), $url ),
				__( 'Configure authentication, accept the warning, then choose Create as a plugin.', 'stonewright' ),
				__( "When this site's sign-in settings change, open the plugin and click Refresh.", 'stonewright' ),
			],
			'links'   => [ self::link( __( 'Open ChatGPT plugins', 'stonewright' ), self::CHATGPT_PLUGINS, 'https' ) ],
			'sign_in' => __( 'ChatGPT starts the sign-in the first time it calls a tool. Approve the request on this site.', 'stonewright' ),
			'limits'  => [
				__( 'ChatGPT connects from its own servers, so it needs a public HTTPS URL, or the tunnel option in the same dialog. A site that only runs on your computer or local network cannot connect directly.', 'stonewright' ),
				__( 'ChatGPT on the web does not read Codex or other local configuration files.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function codex( string $slug, string $url, string $name ): array {
		$cli   = __( 'Codex CLI: run the add command, then sign in with codex mcp login. /mcp shows the server status.', 'stonewright' );
		$share = __( 'The desktop app, the CLI and the IDE extension share ~/.codex/config.toml, so adding the server once covers all three.', 'stonewright' );
		$steps = 'codex-cli' === $slug
			? [ $cli, $share ]
			: [
				$cli,
				__( 'ChatGPT desktop app: Settings > MCP servers > Add server > Streamable HTTP, enter the server URL, Save, then Restart. Click Authenticate when asked.', 'stonewright' ),
				__( 'IDE extension: gear menu > MCP servers > Add server, Save, then Restart extension and click Authenticate.', 'stonewright' ),
				$share,
			];
		return [
			'tag'      => 'codex-cli' === $slug ? __( 'Command line', 'stonewright' ) : __( 'Desktop app, IDE and CLI', 'stonewright' ),
			'steps'    => $steps,
			'snippets' => [
				self::snippet( 'add', __( 'Add the server', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'codex mcp add ' . $name . ' --url ' . self::shell_word( $url ) ),
				self::snippet( 'login', __( 'Sign in', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'codex mcp login ' . $name ),
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'toml', __( '~/.codex/config.toml (Windows: %USERPROFILE%\.codex\config.toml), or .codex/config.toml in a trusted project', 'stonewright' ), '[mcp_servers.' . $name . "]\nurl = " . self::toml_string( $url ) ),
			],
			'sign_in'  => __( 'Sign-in is a separate step: run codex mcp login, or click Authenticate in the app or the extension.', 'stonewright' ),
			'limits'   => [
				__( 'The desktop app and the IDE extension need a restart after a server is added.', 'stonewright' ),
				__( 'ChatGPT on the web does not read this configuration.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function cursor( string $url, string $name ): array {
		return [
			'tag'      => __( 'Editor', 'stonewright' ),
			'steps'    => [
				__( 'Click Add to Cursor, or add the entry to mcp.json and restart Cursor.', 'stonewright' ),
				__( 'The Cursor CLI (agent) reads the same mcp.json.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'json', __( '~/.cursor/mcp.json (all projects) or .cursor/mcp.json (one project)', 'stonewright' ), self::json( [ 'mcpServers' => [ $name => [ 'url' => $url ] ] ] ) ),
				self::snippet( 'login', __( 'Sign in from the Cursor CLI', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'agent mcp login ' . $name ),
			],
			'links'    => [ self::link( __( 'Add to Cursor', 'stonewright' ), self::cursor_install_link( $name, [ 'url' => $url ] ), 'cursor' ) ],
			'sign_in'  => __( 'Cursor runs the OAuth sign-in for servers that require it. From the CLI, run agent mcp login.', 'stonewright' ),
			'limits'   => [
				__( 'Restart Cursor after changing a custom server.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function vscode( string $url, string $name ): array {
		$server = [
			'type' => 'http',
			'url'  => $url,
		];
		return [
			'tag'      => __( 'Editor', 'stonewright' ),
			'steps'    => [
				__( 'Use an install link or code --add-mcp, or add the entry to ~/.copilot/mcp-config.json (preferred) or to .mcp.json in the workspace.', 'stonewright' ),
				__( 'VS Code still reads .vscode/mcp.json and the profile mcp.json, but treats them as older locations.', 'stonewright' ),
				__( 'VS Code asks you to trust a server defined outside the workspace. MCP: List Servers starts, stops and restarts it.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'json', __( '~/.copilot/mcp-config.json for your user ($COPILOT_HOME if set), or .mcp.json at the workspace root', 'stonewright' ), self::json( [ 'mcpServers' => [ $name => $server ] ] ) ),
				self::snippet( 'legacy', __( 'Older VS Code format', 'stonewright' ), 'json', __( '.vscode/mcp.json, or the profile mcp.json (MCP: Open User Configuration)', 'stonewright' ), self::json( [ 'servers' => [ $name => $server ] ] ) ),
				self::snippet( 'cli', __( 'Add from the command line', 'stonewright' ), 'shell', __( 'Terminal; writes to your user profile', 'stonewright' ), 'code --add-mcp "' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], self::vscode_server( $name, $url ) ) . '"' ),
			],
			'links'    => [
				self::link( __( 'Install in VS Code', 'stonewright' ), self::vscode_install_link( $name, $url ), 'vscode' ),
				self::link( __( 'Install in VS Code Insiders', 'stonewright' ), self::vscode_install_link( $name, $url, 'vscode-insiders' ), 'vscode-insiders' ),
			],
			'sign_in'  => __( 'VS Code opens your browser to authorize on the first connection. Manage the account from the Accounts menu.', 'stonewright' ),
			'limits'   => [
				__( 'The type field is required; use http.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function devin( string $url, string $name ): array {
		return [
			'tag'      => __( 'Desktop app and CLI', 'stonewright' ),
			'steps'    => [
				__( 'Windsurf is now Devin Desktop. Its Devin Local agent uses the Devin CLI MCP configuration.', 'stonewright' ),
				__( 'Run the add command (-s user keeps the server for every project; without it the server belongs to the current project), or add the entry to mcp_config.json.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'add', __( 'Add the server', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'devin mcp add -s user ' . $name . ' ' . self::shell_word( $url ) ),
				self::snippet( 'login', __( 'Sign in', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'devin mcp login ' . $name ),
				self::snippet(
					'config',
					__( 'Server entry', 'stonewright' ),
					'json',
					__( '~/.config/devin/mcp_config.json (Windows: %APPDATA%\devin\mcp_config.json)', 'stonewright' ),
					self::json(
						[
							'mcpServers' => [
								$name => [
									'url'       => $url,
									'transport' => 'http',
								],
							],
						]
					)
				),
			],
			'sign_in'  => __( 'Run devin mcp login, accept the prompt the first time the server is used, or click Authenticate in the Devin Local MCP list.', 'stonewright' ),
			'limits'   => [
				__( 'The older Cascade agent and its serverUrl key were removed in Devin Desktop 3.9.19.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function zed( string $url, string $name ): array {
		return [
			'tag'      => __( 'Editor', 'stonewright' ),
			'steps'    => [
				__( 'Open Settings > AI > MCP Servers > Add Server > Add Remote Server, or add the entry to settings.json.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'json', __( 'settings.json (open it with zed: open settings file)', 'stonewright' ), self::json( [ 'context_servers' => [ $name => [ 'url' => $url ] ] ] ) ),
			],
			'sign_in'  => __( 'Zed asks you to authenticate; the server shows an Authenticate button and a Log Out item.', 'stonewright' ),
			'limits'   => [
				__( 'Remote OAuth needs Zed 0.230.0 or later.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function gemini( string $url, string $name ): array {
		return [
			'reach'    => self::REACH_PUBLIC,
			'tag'      => __( 'Command line', 'stonewright' ),
			'steps'    => [
				__( 'Run the add command. Without -s user the server is saved for the current project only.', 'stonewright' ),
				__( 'In settings.json, httpUrl selects Streamable HTTP; url would select the older SSE transport.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'add', __( 'Add the server', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'gemini mcp add --transport http -s user ' . $name . ' ' . self::shell_word( $url ) ),
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'json', __( '~/.gemini/settings.json, or .gemini/settings.json for one project', 'stonewright' ), self::json( [ 'mcpServers' => [ $name => [ 'httpUrl' => $url ] ] ] ) ),
			],
			/* translators: %s: MCP server name. */
			'sign_in'  => sprintf( __( 'Sign-in starts on its own after the first 401 from this site. Inside the CLI, /mcp auth %s signs in again.', 'stonewright' ), $name ),
			'limits'   => [
				__( 'Gemini CLI signs in only to HTTPS servers on a public address. It refuses plain HTTP and private, local and network addresses.', 'stonewright' ),
				__( 'It needs a browser on the same machine, so sign-in does not work over SSH without a display or inside containers.', 'stonewright' ),
				__( 'Server names must not contain _.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function antigravity( string $slug, string $url, string $name ): array {
		$key   = __( 'Remote servers must use serverUrl; url and httpUrl are not read.', 'stonewright' );
		$steps = 'antigravity-cli' === $slug
			? [
				__( 'In Antigravity CLI, /mcp opens the MCP manager. No add command is documented, so add the entry to mcp_config.json.', 'stonewright' ),
				$key,
			]
			: [
				__( 'Antigravity 2.0: Settings > Customizations > Installed MCP Servers.', 'stonewright' ),
				__( 'Antigravity IDE: the ... menu in the agent panel > MCP Servers > Manage MCP Servers > View raw config.', 'stonewright' ),
				$key,
			];
		return [
			'tag'      => 'antigravity-cli' === $slug ? __( 'Command line', 'stonewright' ) : __( 'Editor', 'stonewright' ),
			'steps'    => $steps,
			'snippets' => [
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'json', __( '~/.gemini/config/mcp_config.json (all workspaces) or .agents/mcp_config.json (one workspace)', 'stonewright' ), self::json( [ 'mcpServers' => [ $name => [ 'serverUrl' => $url ] ] ] ) ),
			],
			'sign_in'  => __( 'Open Agent settings (Ctrl/Cmd + ,), go to Customizations and click Authenticate next to the server. Finish in the browser, copy the authorization code, paste it back and click Submit.', 'stonewright' ),
			'limits'   => [
				__( 'Servers moved over from Gemini CLI go from settings.json to mcp_config.json, with url or httpUrl renamed to serverUrl.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function grok( string $url, string $name ): array {
		return [
			'tag'      => __( 'Command line', 'stonewright' ),
			'steps'    => [
				__( 'Run the add command (add --scope project to save it in .grok/config.toml), or add the entry to config.toml.', 'stonewright' ),
				__( 'Check the connection with grok mcp doctor.', 'stonewright' ),
			],
			'snippets' => [
				self::snippet( 'add', __( 'Add the server', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'grok mcp add --transport http ' . $name . ' ' . self::shell_word( $url ) ),
				self::snippet( 'doctor', __( 'Check the connection', 'stonewright' ), 'shell', __( 'Terminal', 'stonewright' ), 'grok mcp doctor ' . $name ),
				self::snippet( 'config', __( 'Server entry', 'stonewright' ), 'toml', __( '~/.grok/config.toml (Windows: %USERPROFILE%\.grok\config.toml)', 'stonewright' ), '[mcp_servers.' . $name . "]\nurl = " . self::toml_string( $url ) . "\nenabled = true" ),
			],
			'sign_in'  => __( 'Sign-in opens in the browser the first time the server is used. In the TUI, /mcps lists the servers, i authenticates and r refreshes after config edits.', 'stonewright' ),
			'limits'   => [
				__( 'Server names may contain only letters, digits, - and _.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function generic( string $url ): array {
		return [
			'tag'     => __( 'Any client', 'stonewright' ),
			'steps'   => [
				/* translators: %s: MCP server URL. */
				sprintf( __( 'Add %s as a remote MCP server with the Streamable HTTP transport and no Authorization header.', 'stonewright' ), $url ),
				__( 'A client that follows the MCP authorization specification finds the sign-in settings on this site and asks you to approve access.', 'stonewright' ),
			],
			'sign_in' => __( 'The client starts the sign-in when this site answers 401 and opens your browser to approve it.', 'stonewright' ),
			'limits'  => [
				__( 'The client must support OAuth with PKCE and identify itself, either with a published client metadata document or through dynamic registration.', 'stonewright' ),
			],
		];
	}

	/** @return array<string, mixed> */
	private static function undocumented( string $slug, string $url ): array {
		$label = self::catalog_label( $slug );
		$kind  = (string) ( ClientCatalog::get( $slug )['kind'] ?? '' );
		return [
			'tag'   => match ( $kind ) {
				'cli'     => __( 'Command line', 'stonewright' ),
				'desktop' => __( 'Desktop app', 'stonewright' ),
				'generic' => __( 'Any client', 'stonewright' ),
				default   => __( 'Editor', 'stonewright' ),
			},
			'steps' => [
				/* translators: %s: client name. */
				sprintf( __( 'This page has no published browser sign-in steps for %s.', 'stonewright' ), $label ),
				/* translators: 1: client name, 2: MCP server URL. */
				sprintf( __( 'If %1$s can add a remote MCP server over Streamable HTTP with OAuth, add %2$s and approve the request on this site.', 'stonewright' ), $label, $url ),
				__( 'Otherwise connect it with an Application Password: choose Application Password in step 2.', 'stonewright' ),
			],
		];
	}

	private static function catalog_label( string $slug ): string {
		$client = ClientCatalog::get( $slug );
		return is_array( $client ) && '' !== (string) ( $client['label'] ?? '' ) ? (string) $client['label'] : $slug;
	}

	/** @return Snippet */
	private static function snippet( string $key, string $title, string $format, string $location, string $code ): array {
		return [
			'key'      => $key,
			'title'    => $title,
			'format'   => $format,
			'location' => $location,
			'code'     => $code,
		];
	}

	/** @return Link */
	private static function link( string $label, string $url, string $scheme ): array {
		return [
			'label'   => $label,
			'url'     => $url,
			'schemes' => [ $scheme ],
		];
	}

	private static function vscode_server( string $name, string $url ): string {
		return (string) wp_json_encode(
			[
				'name' => $name,
				'type' => 'http',
				'url'  => $url,
			],
			JSON_UNESCAPED_SLASHES
		);
	}

	/** @param array<string, mixed> $value */
	private static function json( array $value ): string {
		return (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** A TOML basic string. */
	private static function toml_string( string $value ): string {
		return '"' . str_replace( [ '\\', '"', "\r", "\n", "\t" ], [ '\\\\', '\\"', '\\r', '\\n', '\\t' ], $value ) . '"';
	}

	/**
	 * One command-line word: bare when every character is safe in every common shell,
	 * double-quoted when only globbing or separators would bite, otherwise single-quoted
	 * for POSIX shells.
	 */
	private static function shell_word( string $value ): string {
		if ( 1 === preg_match( '~^[A-Za-z0-9_./:@+-]+$~D', $value ) ) {
			return $value;
		}
		if ( 1 !== preg_match( '~["$`\\\\!%]~', $value ) ) {
			return '"' . $value . '"';
		}
		return "'" . str_replace( "'", "'\\''", $value ) . "'";
	}
}
