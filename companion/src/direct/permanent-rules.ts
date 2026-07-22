/**
 * Always-on Direct-mode operating rules.
 *
 * These ship with the companion (not site memory / Safety UI). They are general
 * product rules — never site-branded — and are returned on every task-start.
 *
 * Canonical rule texts must stay byte-identical to
 * Stonewright\WpMcp\Core\McpUsePolicy::canonical_operating_rules() — parity
 * tests fail on drift.
 */

import { createHash } from 'node:crypto';

/** Stable id → rule text (mirrors plugin McpUsePolicy::canonical_operating_rules). */
export const CANONICAL_OPERATING_RULES: Readonly<Record<string, string>> = {
	elementor_responsive_preview:
		'Elementor responsive preview: when editing responsive Elementor settings through the UI, switch the device with the editor top-toolbar device tabs (role=tab, discover at runtime). Never resize the whole editor browser window to select an Elementor breakpoint. Verify the selected tab via aria-selected=true.',
	separate_verification_tab:
		'Separate verification tab: keep the Elementor editor tab dedicated to editing (role editor_page). Open or reuse a separate frontend tab (role verification_page) for rendered checks. Resize only the verification tab; never resize or navigate away from the editor window for viewport checks.',
	design_section_isolation:
		'Design section isolation: treat any multi-section design page/node as an ordered section manifest (node id, name, bounds, breakpoints). Capture one visual export and extract layout/typography/assets/colors/spacing per section. Implement and verify one section per guarded transaction, then full-page regression.',
	breakpoint_isolation:
		'Breakpoint isolation: design evidence for one breakpoint authorizes changes only to that breakpoint. Preserve every other breakpoint exactly (hash non-target values before/after). If a native control is not responsive, perform no write, return unsupported_responsive_control, and notify the user — never fall back to base values or Custom CSS.',
	native_first_styling:
		'Native-first styling: use native Elementor, Gutenberg, or FSE controls before Custom CSS or code. If native implementation is impossible, stop and explain the proven native gap before adding Custom CSS or code.',
	fastest_safe_interface:
		'Fastest safe interface: prefer typed Stonewright/native APIs (typed_api), then the Elementor editor command bus (editor_command_bus), then authenticated admin form POST (admin_form), then browser UI locators (browser_ui) only when no safe programmatic interface exists. Never skip permission, backup, validation, confirmation, audit, or readback gates for speed. Never implement via DOM mutation through browser evaluate().',
	verified_learning:
		'Verified learning: when the user explicitly asks Stonewright to remember a correction or stable preference, call stonewright-learning-record in the active mode, read it back, and report memory_id, scope, and verified:true. Never claim it was remembered without verification.',
};

export const DIRECT_PERMANENT_RULES: readonly string[] = [
	...Object.values(CANONICAL_OPERATING_RULES),
	'Single-target scope: change ONLY the environment the user named (this site alias/URL). Do not also edit local, staging, or another host "for consistency" unless the user explicitly asks.',
	'Direct remote path: for a remote/live site use Direct REST/admin-HTTP tools against that site only. Do not fall back to local WP-CLI, local MySQL, local app paths, or a different MCP server pointed at another install.',
	'No ad-hoc plugins: never scaffold, zip, upload, install, or activate a custom plugin as a workaround. Prefer existing site tools (CPT UI, ACF, Elementor, theme/plugin already installed) or tell the user registration needs server-side PHP.',
	'HTTP-first automation: prefer WP REST → official plugin REST/APIs → Stonewright typed tools → authenticated admin form POST (nonce + cookies). Browser admin click/fill is last resort; screenshots and visual verification are fine.',
	'Content-model additive only: never use CPT UI full Import/Export to "add one type" — import replaces entire option bags and can wipe existing CPTs/taxonomies. Use Add New / edit with cpt_original + cpt_type_status=edit. Never bulk-import options/field groups/content from another environment unless the user explicitly requests that transfer.',
	'Existing models vs registration: Direct fully edits registered CPT content, taxonomy terms, and ACF field values (when exposed in REST). Registering NEW post types, taxonomies, or field groups has no core REST endpoint — needs PHP on the server (plugin/theme) or additive admin tools already on the site.',
	'Method selection contract: return chosen method and reason as typed_api | editor_command_bus | admin_form | browser_ui. Prefer stable role/name or data-testid locators over coordinates, brittle CSS, XPath, or fixed sleeps.',
];

export function permanentRulesGuidance(): string[] {
	return DIRECT_PERMANENT_RULES.map((rule) => `HARD RULE: ${rule}`);
}

/** Stable parity fingerprint — must match McpUsePolicy::canonical_rules_fingerprint(). */
export function canonicalRulesFingerprint(): string {
	const keys = Object.keys(CANONICAL_OPERATING_RULES).sort();
	const body = keys.map((k) => CANONICAL_OPERATING_RULES[k]).join('\n');
	return createHash('sha256').update(body).digest('hex');
}
