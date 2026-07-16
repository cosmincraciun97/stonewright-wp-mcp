/**
 * Direct / Plugin capability tiers for honest productization.
 *
 * remote-rest: Application Password + core REST only (no local WP-CLI).
 * local-rest-wpcli: REST + tokenized WP-CLI on a local site (Elementor meta edits).
 * plugin: Stonewright plugin MCP endpoint (full ability surface).
 * plugin-browser-qa: plugin + browser/visual QA tooling (Playwright e2e path).
 */

export type CapabilityTierId =
	| 'remote-rest'
	| 'local-rest-wpcli'
	| 'plugin'
	| 'plugin-browser-qa';

export type CapabilityFeature = {
	id: string;
	label: string;
	/** Tier ids that can honestly promise this feature. */
	availableIn: readonly CapabilityTierId[];
	/** Why lower tiers cannot promise it. */
	reasonUnavailable: string;
	/** How to unlock from a lower tier. */
	upgradePath: string;
};

export type CapabilityTier = {
	id: CapabilityTierId;
	label: string;
	summary: string;
	/** Concrete capabilities this tier may claim. */
	includes: readonly string[];
	/** Explicit non-promises (must not advertise as available). */
	excludes: readonly string[];
	upgradeTo?: CapabilityTierId;
	upgradePath?: string;
};

/** Features that must never be promised for remote-rest. */
export const PLUGIN_ONLY_FEATURE_IDS = [
	'php-execute',
	'elementor-engine',
	'design-spec-render',
	'confirmation-tokens',
	'site-memory-skills-server',
	'content-model-registration',
	'audit-log-admin',
	'browser-qa',
] as const;

export const CAPABILITY_FEATURES: readonly CapabilityFeature[] = [
	{
		id: 'core-rest-content',
		label: 'Core REST content (posts/pages/media/menus)',
		availableIn: ['remote-rest', 'local-rest-wpcli', 'plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Requires WordPress REST with Application Password.',
		upgradePath: 'Configure STONEWRIGHT_WP_URL + Application Password credentials.',
	},
	{
		id: 'elementor-data-wpcli',
		label: 'Local Elementor _elementor_data edit via WP-CLI',
		availableIn: ['local-rest-wpcli', 'plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Remote REST cannot run WP-CLI; raw Elementor meta edits need local filesystem access.',
		upgradePath: 'Run the companion on the same machine as the WordPress install (local REST + WP-CLI tier).',
	},
	{
		id: 'php-execute',
		label: 'PHP runtime execution (stonewright/php-execute)',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Plugin-only ability; not available over core REST alone.',
		upgradePath: 'Install and activate the Stonewright plugin, then use stonewright-php-execute.',
	},
	{
		id: 'elementor-engine',
		label: 'Typed Elementor V3/V4 engines and DesignSpec render',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Full Elementor engines require the Stonewright plugin runtime.',
		upgradePath: 'Install the Stonewright plugin for typed Elementor engines and batch-mutate.',
	},
	{
		id: 'design-spec-render',
		label: 'DesignSpec validation and render pipelines',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Validator and renderers live in the plugin runtime.',
		upgradePath: 'Install the Stonewright plugin to use design-native-plan, validate-spec, and render abilities.',
	},
	{
		id: 'confirmation-tokens',
		label: 'Production-safe confirmation tokens',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'ConfirmationToken issue/verify is plugin-only.',
		upgradePath: 'Install the Stonewright plugin for production-safe destructive gates.',
	},
	{
		id: 'site-memory-skills-server',
		label: 'Site-hosted memory/skills + wp-admin UI',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Shared site memory/skills require the plugin; Direct only has per-machine ~/.stonewright storage.',
		upgradePath: 'Install the Stonewright plugin for shared site memory/skills.',
	},
	{
		id: 'content-model-registration',
		label: 'CPT / taxonomy / field-group registration',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Persistent registration APIs require the Stonewright plugin.',
		upgradePath: 'Install the Stonewright plugin for cpt-register, taxonomy-register, and acf-field-group-save.',
	},
	{
		id: 'audit-log-admin',
		label: 'Server-side audit log admin UI',
		availableIn: ['plugin', 'plugin-browser-qa'],
		reasonUnavailable: 'Plugin audit log is separate from companion Direct JSONL.',
		upgradePath: 'Install the Stonewright plugin for the admin Audit Log UI.',
	},
	{
		id: 'browser-qa',
		label: 'Browser visual QA (Playwright admin/frontend checks)',
		availableIn: ['plugin-browser-qa'],
		reasonUnavailable: 'Browser QA is an optional matrix tier, not part of REST-only Direct mode.',
		upgradePath: 'Run e2e/ Playwright against a wp-env or Local site with the plugin active.',
	},
] as const;

export const CAPABILITY_TIERS: readonly CapabilityTier[] = [
	{
		id: 'remote-rest',
		label: 'Remote REST (Direct)',
		summary:
			'Application Password + core WordPress REST from any host. No WP-CLI, no plugin engines.',
		includes: [
			'content list/get/create/update',
			'media, menus, taxonomy, templates, global styles',
			'search, settings, plugins list/activate, themes list',
			'local companion skills/memory under ~/.stonewright',
		],
		excludes: [...PLUGIN_ONLY_FEATURE_IDS, 'elementor-data-wpcli'],
		upgradeTo: 'local-rest-wpcli',
		upgradePath:
			'For local Elementor meta edits, run the companion on the WordPress host with WP-CLI available. For full engines, install the Stonewright plugin.',
	},
	{
		id: 'local-rest-wpcli',
		label: 'Local REST + WP-CLI (Direct)',
		summary:
			'Core REST plus tokenized WP-CLI on the same machine (Elementor data-get/update with file backup).',
		includes: [
			'everything in remote-rest',
			'stonewright-elementor-status / data-get / data-update (local WP-CLI)',
			'tokenized wp-cli-status/discover/run',
		],
		excludes: [...PLUGIN_ONLY_FEATURE_IDS],
		upgradeTo: 'plugin',
		upgradePath:
			'Install and activate the Stonewright plugin, set STONEWRIGHT_MODE=plugin, restart the MCP client.',
	},
	{
		id: 'plugin',
		label: 'Plugin mode',
		summary:
			'Stonewright plugin MCP endpoint: full ability surface, DesignSpec, php-execute, tokens, audit.',
		includes: [
			'all Direct REST workflows via plugin abilities',
			'php-execute, Elementor V3/V4 engines, DesignSpec',
			'production-safe confirmation tokens, site memory/skills, audit log',
		],
		excludes: ['browser-qa'],
		upgradeTo: 'plugin-browser-qa',
		upgradePath: 'Add Playwright e2e (e2e/) against wp-env or Local for visual admin QA.',
	},
	{
		id: 'plugin-browser-qa',
		label: 'Plugin + browser QA',
		summary: 'Plugin mode plus Playwright visual/admin checks for release gates.',
		includes: [
			'everything in plugin',
			'Playwright admin UI matrix (e2e/)',
		],
		excludes: [],
	},
] as const;

export function getCapabilityTier(id: CapabilityTierId): CapabilityTier {
	const tier = CAPABILITY_TIERS.find((t) => t.id === id);
	if (!tier) {
		throw new Error(`Unknown capability tier: ${id}`);
	}
	return tier;
}

export function isFeatureAvailableInTier(featureId: string, tierId: CapabilityTierId): boolean {
	const feature = CAPABILITY_FEATURES.find((f) => f.id === featureId);
	if (!feature) {
		return false;
	}
	return feature.availableIn.includes(tierId);
}

/**
 * Features remote-rest must not advertise. Used by honesty tests.
 */
export function featuresUnavailableForRemoteRest(): readonly CapabilityFeature[] {
	return CAPABILITY_FEATURES.filter((f) => !f.availableIn.includes('remote-rest'));
}

export function describeUnavailable(
	featureId: string,
	tierId: CapabilityTierId,
): { reason: string; upgrade: string } | null {
	const feature = CAPABILITY_FEATURES.find((f) => f.id === featureId);
	if (!feature) {
		return null;
	}
	if (feature.availableIn.includes(tierId)) {
		return null;
	}
	return {
		reason: feature.reasonUnavailable,
		upgrade: feature.upgradePath,
	};
}
