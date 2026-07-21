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
		return 'No MCP bypasses: private client configs, repo/source schema spelunking, hand-rolled JSON-RPC, scratch/action scripts (query-mcp.js, run-ability.js, query-local-stonewright.js, run-loop-mutate.js, run-bootstrap-and-mutate.js), helper JSON args, REST runner shell calls, shell wp commands, or generic PHP adapters instead of stonewright/php-execute.';
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
			'If wordpress-mcp-status says connected and configured_mcp_surface is full/essential but php-execute is missing from the client tool list, this is a client surface / profile sync bug: call stonewright-task-start or stonewright-tool-profile activate, re-list tools, or restart the MCP client. Do not invent REST workarounds.',
			'Use stonewright-client-surface-check (companion local) to diagnose profile vs client registration mismatches.',
		];
	}

	public static function client_note_suffix(): string {
		return 'Stonewright MCP must be visible (`stonewright-context-bootstrap`) before WordPress work; no private config inspection, no scratch scripts, no helper JSON argument files, no direct companion shell launch, no action scripts, no source-code schema spelunking, and no REST runner, shell WP-CLI, or generic PHP-adapter workaround.';
	}

	/**
	 * Product-default operating rules shipped with the plugin (not site Safety Memory).
	 * General only — never site-branded. Injected into agent instructions.
	 *
	 * @return array<int, string>
	 */
	public static function permanent_operating_rules(): array {
		return [
			'Change only the WordPress environment the user named (this site). Do not also mutate local, staging, or another host for consistency unless the user explicitly asks.',
			'Never scaffold, zip, upload, or activate ad-hoc custom plugins as a workaround for CPT/taxonomy/field registration. Prefer tools already on the site, typed Stonewright abilities, or tell the user server-side PHP is required.',
			'Automate HTTP-first: WP REST and official plugin APIs, then Stonewright typed abilities, then authenticated admin form POST with nonces. Browser click/fill automation is last resort; screenshots and visual verification are fine.',
			'Content-model changes are additive. Do not use CPT UI full Import to add one type — import replaces entire option bags and can wipe existing post types/taxonomies. Prefer Add New / targeted edit. Never bulk-transfer models or content between environments unless the user explicitly requests that transfer.',
			'Implementation priority: Elementor native controls/widgets first; scoped child-theme CSS under a section parent class only when native controls cannot express the need; scripts/HTML/JS only as last resort with explicit user approval when required.',
			'Never duplicate Elementor widgets with hide_desktop/hide_mobile only to change typography between breakpoints. Use one widget and native responsive Typography controls (font size / line-height / letter-spacing per device).',
			'Custom CSS for a section requires a custom class on the parent container first; scope all related CSS under that class. Prefer child-theme style.css over loose global selectors or Elementor page Custom CSS for native widgets.',
			'For Nested Carousel peek/inset effects, use native Direction / Offset Sides / Offset Width controls (infinite often required). Do not fake peek with CSS padding on the carousel track.',
			'Never set overflow:visible on .elementor-main-swiper to expose outside arrows — it breaks Swiper clipping. Keep overflow hidden and position native arrows inside the track.',
			'Every Elementor V3 tree node needs a non-empty unique id. Never write raw _elementor_data through php-execute; use typed Elementor abilities with backup and schema validation.',
		];
	}
}
