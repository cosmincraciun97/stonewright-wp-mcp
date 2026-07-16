/**
 * Always-on Direct-mode operating rules.
 *
 * These ship with the companion (not site memory / Safety UI). They are general
 * product rules — never site-branded — and are returned on every task-start.
 */

export const DIRECT_PERMANENT_RULES: readonly string[] = [
	'Single-target scope: change ONLY the environment the user named (this site alias/URL). Do not also edit local, staging, or another host "for consistency" unless the user explicitly asks.',
	'Direct remote path: for a remote/live site use Direct REST/admin-HTTP tools against that site only. Do not fall back to local WP-CLI, local MySQL, local app paths, or a different MCP server pointed at another install.',
	'No ad-hoc plugins: never scaffold, zip, upload, install, or activate a custom plugin as a workaround. Prefer existing site tools (CPT UI, ACF, Elementor, theme/plugin already installed) or tell the user registration needs server-side PHP.',
	'HTTP-first automation: prefer WP REST → official plugin REST/APIs → Stonewright typed tools → authenticated admin form POST (nonce + cookies). Playwright admin click/fill is last resort; screenshots/visual QA are fine.',
	'Content-model additive only: never use CPT UI full Import/Export to "add one type" — import replaces entire option bags and can wipe existing CPTs/taxonomies. Use Add New / edit with cpt_original + cpt_type_status=edit. Never bulk-import options/field groups/content from another environment unless the user explicitly requests that transfer.',
	'Existing models vs registration: Direct fully edits registered CPT content, taxonomy terms, and ACF field values (when exposed in REST). Registering NEW post types, taxonomies, or field groups has no core REST endpoint — needs PHP on the server (plugin/theme) or additive admin tools already on the site.',
];

export function permanentRulesGuidance(): string[] {
	return DIRECT_PERMANENT_RULES.map((rule) => `HARD RULE: ${rule}`);
}
