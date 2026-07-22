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
	 * Canonical permanent rules (product defaults). Mirrored into Direct mode and
	 * skills/agent-operating-rules. Not Memory rows. Never site-branded.
	 *
	 * @return array<string, string> Stable id => rule text.
	 */
	public static function canonical_operating_rules(): array {
		return [
			'elementor_responsive_preview' => 'Elementor responsive preview: when editing responsive Elementor settings through the UI, switch the device with the editor top-toolbar device tabs (role=tab, discover at runtime). Never resize the whole editor browser window to select an Elementor breakpoint. Verify the selected tab via aria-selected=true.',
			'separate_verification_tab'    => 'Separate verification tab: keep the Elementor editor tab dedicated to editing (role editor_page). Open or reuse a separate frontend tab (role verification_page) for rendered checks. Resize only the verification tab; never resize or navigate away from the editor window for viewport checks.',
			'design_section_isolation'     => 'Design section isolation: treat any multi-section design page/node as an ordered section manifest (node id, name, bounds, breakpoints). Capture one visual export and extract layout/typography/assets/colors/spacing per section. Implement and verify one section per guarded transaction, then full-page regression.',
			'breakpoint_isolation'         => 'Breakpoint isolation: design evidence for one breakpoint authorizes changes only to that breakpoint. Preserve every other breakpoint exactly (hash non-target values before/after). If a native control is not responsive, perform no write, return unsupported_responsive_control, and notify the user — never fall back to base values or Custom CSS.',
			'native_first_styling'         => 'Native-first styling: use native Elementor, Gutenberg, or FSE controls before Custom CSS or code. If native implementation is impossible, stop and explain the proven native gap before adding Custom CSS or code.',
			'fastest_safe_interface'       => 'Fastest safe interface: prefer typed Stonewright/native APIs (typed_api), then the Elementor editor command bus (editor_command_bus), then authenticated admin form POST (admin_form), then browser UI locators (browser_ui) only when no safe programmatic interface exists. Never skip permission, backup, validation, confirmation, audit, or readback gates for speed. Never implement via DOM mutation through browser evaluate().',
			'verified_learning'            => 'Verified learning: when the user explicitly asks Stonewright to remember a correction or stable preference, call stonewright-learning-record in the active mode, read it back, and report memory_id, scope, and verified:true. Never claim it was remembered without verification.',
		];
	}

	/**
	 * Product-default operating rules shipped with the plugin (not site Safety Memory).
	 * General only — never site-branded. Injected into agent instructions.
	 *
	 * @return array<int, string>
	 */
	public static function permanent_operating_rules(): array {
		return array_values(
			array_merge(
				self::canonical_operating_rules(),
				[
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
					'Elementor integrity (P0): never double-encode JSON; never strip unknown settings to pass validation; never convert widgetType (e.g. e-paragraph→text-editor) without explicit user intent; never full-tree rewrite to fix one control — use surgical batch-mutate.',
					'If batch-mutate is missing from the client tool list, call task-start / tool-profile and re-list tools (or stonewright-client-surface-check). Do not invent php-execute or raw REST/WP-CLI meta writes.',
					'Method selection contract: return chosen method and reason as typed_api | editor_command_bus | admin_form | browser_ui. Prefer stable role/name or data-testid locators over coordinates, brittle CSS, XPath, or fixed sleeps.',
				]
			)
		);
	}

	/**
	 * Stable parity fingerprint for Plugin/Direct/skill copies of canonical rules.
	 */
	public static function canonical_rules_fingerprint(): string {
		$rules = self::canonical_operating_rules();
		ksort( $rules );
		return hash( 'sha256', implode( "\n", array_map( 'strval', $rules ) ) );
	}
}
