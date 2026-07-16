import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

export const PLUGIN_ONLY_CAPABILITIES = [
	{
		id: 'php-execute',
		label: 'PHP runtime execution',
		reason: 'Requires the Stonewright plugin ability stonewright/php-execute.',
		upgrade: 'Install and activate the Stonewright plugin, then use stonewright-php-execute.',
	},
	{
		id: 'elementor-engine',
		label: 'Elementor engines / schema / DesignSpec render',
		reason:
			'Full Elementor V3/V4 engines and schema tools require the plugin. Raw _elementor_data edit is available in Direct only on local WP-CLI sites via stonewright-elementor-data-*.',
		upgrade:
			'Install the Stonewright plugin for typed Elementor engines; on local sites use stonewright-elementor-status first.',
	},
	{
		id: 'design-spec-render',
		label: 'DesignSpec validation and render pipelines',
		reason: 'Validator and renderers live in the plugin runtime.',
		upgrade: 'Install the Stonewright plugin to use design-native-plan, validate-spec, and render abilities.',
	},
	{
		id: 'confirmation-tokens',
		label: 'Production-safe confirmation tokens',
		reason: 'ConfirmationToken issue/verify is plugin-only.',
		upgrade: 'Install the Stonewright plugin for production-safe destructive gates.',
	},
	{
		id: 'site-memory-skills',
		label: 'Server-side site memory, skills, and learning (wp-admin)',
		reason: 'Shared, site-hosted memory/skills and Admin UI require the Stonewright plugin.',
		upgrade:
			'Install the plugin for shared site memory/skills. Direct mode already provides per-machine local skills/memory via stonewright-skill-* and stonewright-learning-record.',
	},
	{
		id: 'content-model-registration',
		label: 'CPT / taxonomy / field-group registration',
		reason: 'Persistent registration APIs require the Stonewright plugin (or CPT UI + ACF on-site).',
		upgrade: 'Install the Stonewright plugin for cpt-register, taxonomy-register, and acf-field-group-save.',
	},
	{
		id: 'audit-log',
		label: 'Server-side audit log',
		reason: 'Plugin audit log is separate from the companion Direct JSONL write log.',
		upgrade: 'Install the Stonewright plugin for the admin Audit Log UI and ability-level audit.',
	},
] as const;

type WpIndex = {
	name?: string | undefined;
	description?: string | undefined;
	url?: string | undefined;
	home?: string | undefined;
	gmt_offset?: number | undefined;
	timezone_string?: string | undefined;
	namespaces?: string[] | undefined;
	authentication?: Record<string, unknown> | undefined;
	routes?: Record<string, unknown> | undefined;
};

type WpType = {
	slug?: string | undefined;
	name?: string | undefined;
	rest_base?: string | undefined;
	hierarchical?: boolean | undefined;
};

type WpTaxonomy = {
	slug?: string | undefined;
	name?: string | undefined;
	rest_base?: string | undefined;
	hierarchical?: boolean | undefined;
};

function detectFromNamespaces(namespaces: string[]) {
	const set = new Set(namespaces.map((n) => n.toLowerCase()));
	const has = (prefix: string) => [...set].some((n) => n === prefix || n.startsWith(`${prefix}/`) || n.startsWith(prefix));
	return {
		elementor: has('elementor'),
		woocommerce: has('wc') || has('wc/v3') || has('wc-admin'),
		acf: has('acf') || has('acf/v3'),
		stonewright_plugin: has('mcp/stonewright') || has('stonewright') || has('stonewright/v1'),
		yoast: has('yoast') || has('yoast/v1'),
		rankmath: has('rankmath'),
	};
}

export async function siteDiscover(ctx: DirectToolContext) {
	assertToolEnabled(ctx.site, 'stonewright-site-discover');

	let index: WpIndex;
	try {
		index = await ctx.client.get<WpIndex>('/');
	} catch {
		// Some hosts reject bare `/`; fall back via absolute rest root fetch.
		const fetchImpl = ctx.fetchImpl ?? fetch;
		const response = await fetchImpl(ctx.client.restBase.replace(/\/+$/, ''), {
			headers: {
				accept: 'application/json',
				authorization: `Basic ${Buffer.from(`${ctx.site.username}:${ctx.site.appPassword}`).toString('base64')}`,
			},
		});
		if (!response.ok) {
			throw new Error(`Failed to read REST index: HTTP ${response.status}`);
		}
		index = (await response.json()) as WpIndex;
	}

	const namespaces = Array.isArray(index.namespaces) ? index.namespaces : [];
	const detected = detectFromNamespaces(namespaces);

	let types: Array<{ slug: string; name: string; rest_base: string; hierarchical: boolean }> = [];
	let taxonomies: Array<{ slug: string; name: string; rest_base: string; hierarchical: boolean }> = [];

	try {
		const typeMap = await ctx.client.get<Record<string, WpType>>('/wp/v2/types');
		types = Object.values(typeMap ?? {}).map((row) => ({
			slug: row.slug ?? '',
			name: row.name ?? '',
			rest_base: row.rest_base ?? '',
			hierarchical: row.hierarchical === true,
		})).filter((row) => row.slug);
	} catch {
		types = [];
	}

	try {
		const taxMap = await ctx.client.get<Record<string, WpTaxonomy>>('/wp/v2/taxonomies');
		taxonomies = Object.values(taxMap ?? {}).map((row) => ({
			slug: row.slug ?? '',
			name: row.name ?? '',
			rest_base: row.rest_base ?? '',
			hierarchical: row.hierarchical === true,
		})).filter((row) => row.slug);
	} catch {
		taxonomies = [];
	}

	const unavailable = PLUGIN_ONLY_CAPABILITIES.map((cap) => {
		if (cap.id === 'elementor-engine' && detected.elementor) {
			return {
				...cap,
				site_signal: 'Elementor REST namespace detected on this site; install Stonewright plugin to edit Elementor data safely.',
			};
		}
		return { ...cap };
	});

	return {
		mode: 'direct' as const,
		site: {
			alias: ctx.site.alias,
			url: ctx.site.url,
			name: index.name ?? '',
			description: index.description ?? '',
			home: index.home ?? index.url ?? ctx.site.url,
		},
		namespaces,
		post_types: types,
		taxonomies,
		detected_plugins: detected,
		available_without_plugin: [
			'content (posts/pages/CPT via REST)',
			'media upload/list/update',
			'taxonomy terms',
			'menus + menu items',
			'FSE templates + template parts',
			'global styles (theme.json)',
			'settings',
			'plugins list/activate/deactivate/install',
			'themes list',
			'users list/get/me',
			'search',
			'block patterns',
			'gutenberg compose (local markup helper)',
			'WP-CLI tools (local companion)',
		],
		unavailable_without_plugin: unavailable,
		first_tool: 'stonewright-site-discover',
		upgrade_hint:
			detected.stonewright_plugin
				? 'Stonewright plugin namespaces detected. Prefer STONEWRIGHT_MODE=plugin or auto for full ability proxy.'
				: 'Install the Stonewright plugin (Setup page → about 1 minute) for Elementor engine, php-execute, memory, and production-safe confirmation tokens.',
	};
}
