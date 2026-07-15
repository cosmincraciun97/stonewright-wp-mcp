import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { DirectToolContext } from './types.js';

type WpPlugin = {
	plugin: string;
	status?: string | undefined;
	name?: string | undefined;
	plugin_uri?: string | undefined;
	author?: string | undefined;
	author_uri?: string | undefined;
	description?: { raw?: string; rendered?: string } | string;
	version?: string | undefined;
	network_only?: boolean | undefined;
	requires_wp?: string | undefined;
	requires_php?: string | undefined;
	textdomain?: string | undefined;
};

function descriptionOf(plugin: WpPlugin): string {
	if (typeof plugin.description === 'string') return plugin.description;
	return plugin.description?.raw ?? plugin.description?.rendered ?? '';
}

function compactPlugin(plugin: WpPlugin) {
	return {
		plugin: plugin.plugin,
		status: plugin.status ?? '',
		name: plugin.name ?? '',
		version: plugin.version ?? '',
		author: plugin.author ?? '',
		description: descriptionOf(plugin),
		network_only: plugin.network_only === true,
	};
}

function encodePlugin(plugin: string): string {
	return plugin.split('/').map(encodeURIComponent).join('/');
}

export async function pluginList(
	ctx: DirectToolContext,
	input: { status?: string | undefined; search?: string | undefined } = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-plugin-list');
	const items = await ctx.client.get<WpPlugin[]>('/wp/v2/plugins', {
		query: {
			status: input.status,
			search: input.search,
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactPlugin),
		total: list.length,
	};
}

export async function pluginActivate(
	ctx: DirectToolContext,
	input: { plugin: string; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-plugin-activate');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-plugin-activate',
	});
	const plugin = input.plugin.trim();
	if (!plugin) throw new Error('plugin is required (e.g. akismet/akismet)');
	try {
		const result = await ctx.client.put<WpPlugin>(`/wp/v2/plugins/${encodePlugin(plugin)}`, {
			body: { status: 'active' },
		});
		appendDirectAudit({
			tool: 'stonewright-plugin-activate',
			site: ctx.site.alias,
			resource: plugin,
			status: 'ok',
		});
		return compactPlugin(result);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-plugin-activate',
			site: ctx.site.alias,
			resource: plugin,
			status: 'error',
		});
		throw err;
	}
}

export async function pluginDeactivate(
	ctx: DirectToolContext,
	input: { plugin: string; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-plugin-deactivate');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-plugin-deactivate',
	});
	const plugin = input.plugin.trim();
	if (!plugin) throw new Error('plugin is required (e.g. akismet/akismet)');
	try {
		const result = await ctx.client.put<WpPlugin>(`/wp/v2/plugins/${encodePlugin(plugin)}`, {
			body: { status: 'inactive' },
		});
		appendDirectAudit({
			tool: 'stonewright-plugin-deactivate',
			site: ctx.site.alias,
			resource: plugin,
			status: 'ok',
		});
		return compactPlugin(result);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-plugin-deactivate',
			site: ctx.site.alias,
			resource: plugin,
			status: 'error',
		});
		throw err;
	}
}

export async function pluginInstall(
	ctx: DirectToolContext,
	input: { slug: string; status?: 'active' | 'inactive' | undefined; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-plugin-install');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-plugin-install',
	});
	const slug = input.slug.trim();
	if (!slug) throw new Error('slug is required (WordPress.org plugin slug)');
	try {
		const result = await ctx.client.post<WpPlugin>('/wp/v2/plugins', {
			body: {
				slug,
				status: input.status ?? 'inactive',
			},
		});
		appendDirectAudit({
			tool: 'stonewright-plugin-install',
			site: ctx.site.alias,
			resource: slug,
			status: 'ok',
		});
		return compactPlugin(result);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-plugin-install',
			site: ctx.site.alias,
			resource: slug,
			status: 'error',
		});
		throw err;
	}
}
