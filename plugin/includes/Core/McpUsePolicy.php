<?php
declare( strict_types=1 );

namespace Stonewright\WpMcp\Core;

/**
 * Shared MCP access guardrails for every agent and client setup surface.
 */
final class McpUsePolicy {

	public static function missing_context_bootstrap_rule(): string {
		return 'If stonewright-context-bootstrap is not visible in the MCP tool list, stop and tell the user the Stonewright MCP server is not loaded. Ask them to restart or reload the AI client, or fix the Stonewright MCP config, before WordPress work.';
	}

	public static function compact_bypass_ban_rule(): string {
		return 'No MCP bypasses: private client configs, repo/source schema spelunking, hand-rolled JSON-RPC, scratch/action scripts (query-mcp.js, run-ability.js, query-local-stonewright.js, run-loop-mutate.js, run-bootstrap-and-mutate.js), helper JSON args, REST runner shell calls, shell wp commands, or arbitrary PHP.';
	}

	public static function wp_cli_local_requirement_note(): string {
		return 'Local WP-CLI requires PHP CLI with mysqli/MySQL enabled, wp or wp-cli.phar, STONEWRIGHT_WP_ROOT pointing at wp-config.php, and a running database reachable from wp-config.php.';
	}

	public static function wp_cli_remote_not_required_note(): string {
		return 'Remote HTTP MCP sites do not require local PHP/MySQL unless the companion is expected to run WP-CLI for that site.';
	}

	public static function wp_cli_restart_note(): string {
		return 'Restart or reload the MCP client after changing Stonewright env vars, PHP/WP-CLI paths, or the release tarball.';
	}

	/**
	 * @return array<int, string>
	 */
	public static function bypass_ban_rules(): array {
		return [
			'Do not inspect private AI-client config files to find or call Stonewright.',
			'Do not parse repository files as a substitute for the live MCP tool list.',
			'Do not hand-roll JSON-RPC calls to bypass a missing MCP server.',
			'Do not create scratch scripts such as query-mcp.js or run-ability.js to bypass the MCP client tool surface.',
			'Do not create helper JSON argument files such as bootstrap-args.json, cli_command.json, or get_structure.json to bypass typed MCP tool input.',
			'Do not launch the Stonewright companion from ad hoc shell scripts such as query-local-stonewright.js to bypass the MCP client tool list.',
			'Do not create or modify action scripts such as run-loop-mutate.js or run-bootstrap-and-mutate.js to bypass typed Stonewright tool calls.',
			'Do not inspect plugin or companion source code to reverse-engineer tool schemas during WordPress implementation tasks.',
			'Do not call /wp-json/stonewright/v1/abilities/run from shell as an MCP workaround.',
		];
	}

	public static function client_note_suffix(): string {
		return 'Stonewright MCP must be visible (`stonewright-context-bootstrap`) before WordPress work; no private config inspection, no scratch scripts, no helper JSON argument files, no direct companion shell launch, no action scripts, no source-code schema spelunking, and no REST runner or shell WP-CLI workaround. '
			. self::wp_cli_local_requirement_note()
			. ' Usually, remote HTTP MCP does not require local PHP/MySQL unless the companion will run WP-CLI for that site; restart or reload after changing Stonewright env vars, PHP/WP-CLI paths, or release tarball.';
	}
}
