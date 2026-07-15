import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { loadSitesConfig, resolveSite, type SitesConfig } from './sites-config.js';
import { WpRestClient, WpRestError } from './wp-rest-client.js';
import { resolveDirectWriteMode } from './writes.js';
import * as content from './tools/content.js';
import * as media from './tools/media.js';
import * as taxonomy from './tools/taxonomy.js';
import * as menus from './tools/menus.js';
import * as templates from './tools/templates.js';
import * as globalStyles from './tools/global-styles.js';
import * as settings from './tools/settings.js';
import * as plugins from './tools/plugins.js';
import * as themes from './tools/themes.js';
import * as users from './tools/users.js';
import * as search from './tools/search.js';
import * as blockPatterns from './tools/block-patterns.js';
import * as siteDiscover from './tools/site-discover.js';
import * as gutenberg from './tools/gutenberg-compose.js';
import * as blueprints from './tools/blueprints.js';

export const DIRECT_WAVE1_TOOL_NAMES = [
	'stonewright-content-list',
	'stonewright-content-get',
	'stonewright-content-create-page',
	'stonewright-content-create-post',
	'stonewright-content-update',
	'stonewright-content-delete',
	'stonewright-content-revisions',
	'stonewright-media-list',
	'stonewright-media-get',
	'stonewright-media-upload',
	'stonewright-media-update',
	'stonewright-taxonomy-terms',
] as const;

export const DIRECT_WAVE2_TOOL_NAMES = [
	'stonewright-menu-list',
	'stonewright-menu-get',
	'stonewright-menu-create',
	'stonewright-menu-update',
	'stonewright-menu-delete',
	'stonewright-menu-items',
	'stonewright-template-list',
	'stonewright-template-get',
	'stonewright-template-update',
	'stonewright-template-part-list',
	'stonewright-template-part-get',
	'stonewright-template-part-update',
	'stonewright-global-styles-get',
	'stonewright-global-styles-update',
	'stonewright-settings-get',
	'stonewright-settings-update',
	'stonewright-plugin-list',
	'stonewright-plugin-activate',
	'stonewright-plugin-deactivate',
	'stonewright-plugin-install',
	'stonewright-theme-list',
	'stonewright-user-list',
	'stonewright-user-get',
	'stonewright-user-me',
	'stonewright-search',
	'stonewright-block-patterns',
	'stonewright-site-discover',
	'stonewright-gutenberg-compose',
	'stonewright-blueprint-list',
	'stonewright-blueprint-get',
	'stonewright-blueprint-apply',
] as const;

export const DIRECT_TOOL_NAMES = [
	...DIRECT_WAVE1_TOOL_NAMES,
	...DIRECT_WAVE2_TOOL_NAMES,
] as const;

export interface DirectModeContext {
	env: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	sitesConfig?: SitesConfig;
	timeoutMs?: number;
}

function toolResponse(data: unknown) {
	return {
		content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
	};
}

function toolError(err: unknown) {
	if (err instanceof WpRestError) {
		return {
			isError: true as const,
			content: [{ type: 'text' as const, text: JSON.stringify(err.toJSON(), null, 2) }],
		};
	}
	const message = err instanceof Error ? err.message : String(err);
	return {
		isError: true as const,
		content: [{ type: 'text' as const, text: JSON.stringify({ error: message }, null, 2) }],
	};
}

function buildContext(ctx: DirectModeContext, siteAlias?: string) {
	const config = ctx.sitesConfig ?? loadSitesConfig({ env: ctx.env });
	const site = resolveSite(config, siteAlias);
	const client = new WpRestClient(site, {
		fetchImpl: ctx.fetchImpl,
		timeoutMs: ctx.timeoutMs,
	});
	const writeMode = resolveDirectWriteMode(ctx.env, site.url);
	return {
		client,
		site,
		writeMode,
		...(ctx.fetchImpl ? { fetchImpl: ctx.fetchImpl } : {}),
	};
}

/**
 * Register Direct mode wave-1 + wave-2 tools (REST-only, no plugin required).
 * Call when plugin MCP endpoint is unavailable or STONEWRIGHT_MODE=direct.
 */
export function registerDirectTools(server: McpServer, ctx: DirectModeContext): string[] {
	const siteArg = z.string().optional().describe('Site alias from ~/.stonewright/sites.json');
	const confirmArg = z.boolean().optional().describe('Required true for destructive tools when remote/confirm mode');

	// --- Wave 1: content ---
	server.tool(
		'stonewright-content-list',
		'List posts/pages/CPT items via core REST (Direct mode).',
		{
			site: siteArg,
			type: z.string().optional(),
			search: z.string().optional(),
			status: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await content.contentList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-get',
		'Get a single content item via core REST (Direct mode).',
		{
			site: siteArg,
			type: z.string().optional(),
			id: z.number().int().positive(),
			fields: z.enum(['raw', 'rendered']).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await content.contentGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-create-page',
		'Create a page via core REST (Direct mode).',
		{
			site: siteArg,
			title: z.string().min(1),
			content: z.string().optional(),
			status: z.string().optional(),
			parent: z.number().int().optional(),
			template: z.string().optional(),
			meta: z.record(z.unknown()).optional(),
		},
		async (input) => {
			try {
				return toolResponse(
					await content.contentCreate(buildContext(ctx, input.site), { ...input, kind: 'page' } as never),
				);
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-create-post',
		'Create a post via core REST (Direct mode).',
		{
			site: siteArg,
			title: z.string().min(1),
			content: z.string().optional(),
			status: z.string().optional(),
			parent: z.number().int().optional(),
			template: z.string().optional(),
			meta: z.record(z.unknown()).optional(),
		},
		async (input) => {
			try {
				return toolResponse(
					await content.contentCreate(buildContext(ctx, input.site), { ...input, kind: 'post' } as never),
				);
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-update',
		'Update a content item via core REST (Direct mode). Only provided fields are sent. Accepts Gutenberg block markup in content.',
		{
			site: siteArg,
			type: z.string().optional(),
			id: z.number().int().positive(),
			title: z.string().optional(),
			content: z.string().optional(),
			status: z.string().optional(),
			parent: z.number().int().optional(),
			template: z.string().optional(),
			meta: z.record(z.unknown()).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await content.contentUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-delete',
		'Delete/trash a content item via core REST (Direct mode). force:true requires confirm:true in confirm mode.',
		{
			site: siteArg,
			type: z.string().optional(),
			id: z.number().int().positive(),
			force: z.boolean().optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await content.contentDelete(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-revisions',
		'List content revisions via core REST (Direct mode).',
		{
			site: siteArg,
			type: z.string().optional(),
			id: z.number().int().positive(),
			per_page: z.number().int().min(1).max(50).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await content.contentRevisions(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	// --- Wave 1: media ---
	server.tool(
		'stonewright-media-list',
		'List media via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await media.mediaList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-media-get',
		'Get a media item via core REST (Direct mode).',
		{
			site: siteArg,
			id: z.number().int().positive(),
		},
		async (input) => {
			try {
				return toolResponse(await media.mediaGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-media-upload',
		'Upload media from a local path or URL via core REST (Direct mode).',
		{
			site: siteArg,
			path: z.string().optional(),
			url: z.string().optional(),
			filename: z.string().optional(),
			title: z.string().optional(),
			alt_text: z.string().optional(),
			caption: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await media.mediaUpload(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-media-update',
		'Update media title/alt/caption via core REST (Direct mode).',
		{
			site: siteArg,
			id: z.number().int().positive(),
			title: z.string().optional(),
			alt_text: z.string().optional(),
			caption: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await media.mediaUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-taxonomy-terms',
		'List/create/update/delete taxonomy terms via core REST (Direct mode). action=delete requires confirm:true in confirm mode.',
		{
			site: siteArg,
			action: z.enum(['list', 'create', 'update', 'delete']).default('list'),
			taxonomy: z.string().optional(),
			id: z.number().int().positive().optional(),
			name: z.string().optional(),
			slug: z.string().optional(),
			description: z.string().optional(),
			parent: z.number().int().optional(),
			search: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
			force: z.boolean().optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				const runtime = buildContext(ctx, input.site);
				switch (input.action) {
					case 'create':
						if (!input.name) throw new Error('name is required for create');
						return toolResponse(
							await taxonomy.taxonomyCreate(runtime, {
								...(input.taxonomy !== undefined ? { taxonomy: input.taxonomy } : {}),
								name: input.name,
								...(input.slug !== undefined ? { slug: input.slug } : {}),
								...(input.description !== undefined ? { description: input.description } : {}),
								...(input.parent !== undefined ? { parent: input.parent } : {}),
							}),
						);
					case 'update':
						if (!input.id) throw new Error('id is required for update');
						return toolResponse(
							await taxonomy.taxonomyUpdate(runtime, {
								...(input.taxonomy !== undefined ? { taxonomy: input.taxonomy } : {}),
								id: input.id,
								...(input.name !== undefined ? { name: input.name } : {}),
								...(input.slug !== undefined ? { slug: input.slug } : {}),
								...(input.description !== undefined ? { description: input.description } : {}),
								...(input.parent !== undefined ? { parent: input.parent } : {}),
							}),
						);
					case 'delete':
						if (!input.id) throw new Error('id is required for delete');
						return toolResponse(
							await taxonomy.taxonomyDelete(runtime, {
								...(input.taxonomy !== undefined ? { taxonomy: input.taxonomy } : {}),
								id: input.id,
								...(input.force !== undefined ? { force: input.force } : {}),
								...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
							}),
						);
					default:
						return toolResponse(
							await taxonomy.taxonomyList(runtime, {
								...(input.taxonomy !== undefined ? { taxonomy: input.taxonomy } : {}),
								...(input.search !== undefined ? { search: input.search } : {}),
								...(input.per_page !== undefined ? { per_page: input.per_page } : {}),
								...(input.page !== undefined ? { page: input.page } : {}),
							}),
						);
				}
			} catch (err) {
				return toolError(err);
			}
		},
	);

	// --- Wave 2: menus ---
	server.tool(
		'stonewright-menu-list',
		'List navigation menus via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await menus.menuList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-menu-get',
		'Get a navigation menu via core REST (Direct mode).',
		{ site: siteArg, id: z.number().int().positive() },
		async (input) => {
			try {
				return toolResponse(await menus.menuGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-menu-create',
		'Create a navigation menu via core REST (Direct mode).',
		{
			site: siteArg,
			name: z.string().min(1),
			description: z.string().optional(),
			locations: z.array(z.string()).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await menus.menuCreate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-menu-update',
		'Update a navigation menu via core REST (Direct mode).',
		{
			site: siteArg,
			id: z.number().int().positive(),
			name: z.string().optional(),
			description: z.string().optional(),
			locations: z.array(z.string()).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await menus.menuUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-menu-delete',
		'Delete a navigation menu via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			id: z.number().int().positive(),
			force: z.boolean().optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await menus.menuDelete(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-menu-items',
		'List/create/update/delete menu items via core REST (Direct mode). action=delete requires confirm:true on remote.',
		{
			site: siteArg,
			action: z.enum(['list', 'create', 'update', 'delete']).default('list'),
			menu_id: z.number().int().positive().optional(),
			id: z.number().int().positive().optional(),
			title: z.string().optional(),
			url: z.string().optional(),
			type: z.string().optional(),
			object: z.string().optional(),
			object_id: z.number().int().optional(),
			parent: z.number().int().optional(),
			menu_order: z.number().int().optional(),
			status: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
			force: z.boolean().optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await menus.menuItems(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	// --- Wave 2: templates ---
	server.tool(
		'stonewright-template-list',
		'List FSE templates via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await templates.templateList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-template-get',
		'Get an FSE template via core REST (Direct mode).',
		{ site: siteArg, id: z.string().min(1) },
		async (input) => {
			try {
				return toolResponse(await templates.templateGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-template-update',
		'Update an FSE template via core REST (Direct mode).',
		{
			site: siteArg,
			id: z.string().min(1),
			title: z.string().optional(),
			content: z.string().optional(),
			description: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await templates.templateUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-template-part-list',
		'List FSE template parts via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await templates.templatePartList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-template-part-get',
		'Get an FSE template part via core REST (Direct mode).',
		{ site: siteArg, id: z.string().min(1) },
		async (input) => {
			try {
				return toolResponse(await templates.templatePartGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-template-part-update',
		'Update an FSE template part via core REST (Direct mode).',
		{
			site: siteArg,
			id: z.string().min(1),
			title: z.string().optional(),
			content: z.string().optional(),
			description: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await templates.templatePartUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	// --- Wave 2: global styles / settings ---
	server.tool(
		'stonewright-global-styles-get',
		'Get global styles (theme.json) via core REST (Direct mode).',
		{ site: siteArg, id: z.string().optional() },
		async (input) => {
			try {
				return toolResponse(await globalStyles.globalStylesGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-global-styles-update',
		'Update global styles via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			id: z.string().optional(),
			settings: z.record(z.unknown()).optional(),
			styles: z.record(z.unknown()).optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await globalStyles.globalStylesUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-settings-get',
		'Get site settings via core REST (Direct mode).',
		{ site: siteArg },
		async (input) => {
			try {
				return toolResponse(await settings.settingsGet(buildContext(ctx, input.site)));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-settings-update',
		'Update site settings via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			settings: z.record(z.unknown()),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await settings.settingsUpdate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	// --- Wave 2: plugins / themes / users ---
	server.tool(
		'stonewright-plugin-list',
		'List plugins via core REST (Direct mode).',
		{
			site: siteArg,
			status: z.string().optional(),
			search: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await plugins.pluginList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-plugin-activate',
		'Activate a plugin via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			plugin: z.string().min(1).describe('Plugin file path, e.g. akismet/akismet'),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await plugins.pluginActivate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-plugin-deactivate',
		'Deactivate a plugin via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			plugin: z.string().min(1),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await plugins.pluginDeactivate(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-plugin-install',
		'Install a plugin from wordpress.org via core REST (Direct mode). Requires confirm:true on remote/confirm mode.',
		{
			site: siteArg,
			slug: z.string().min(1),
			status: z.enum(['active', 'inactive']).optional(),
			confirm: confirmArg,
		},
		async (input) => {
			try {
				return toolResponse(await plugins.pluginInstall(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-theme-list',
		'List themes via core REST (Direct mode).',
		{
			site: siteArg,
			status: z.string().optional(),
		},
		async (input) => {
			try {
				return toolResponse(await themes.themeList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-user-list',
		'List users via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			roles: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await users.userList(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-user-get',
		'Get a user via core REST (Direct mode).',
		{ site: siteArg, id: z.number().int().positive() },
		async (input) => {
			try {
				return toolResponse(await users.userGet(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-user-me',
		'Get the authenticated user via core REST (Direct mode).',
		{ site: siteArg },
		async (input) => {
			try {
				return toolResponse(await users.userMe(buildContext(ctx, input.site)));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-search',
		'Site search via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().min(1),
			type: z.string().optional(),
			subtype: z.string().optional(),
			per_page: z.number().int().min(1).max(50).optional(),
			page: z.number().int().min(1).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await search.siteSearch(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-block-patterns',
		'List registered block patterns via core REST (Direct mode).',
		{
			site: siteArg,
			search: z.string().optional(),
			category: z.string().optional(),
			per_page: z.number().int().min(1).max(100).optional(),
		},
		async (input) => {
			try {
				return toolResponse(await blockPatterns.blockPatterns(buildContext(ctx, input.site), input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-site-discover',
		'Discover REST namespaces, post types, taxonomies, and plugin signals. First recommended tool in Direct mode. Reports plugin-only capabilities that are unavailable.',
		{ site: siteArg },
		async (input) => {
			try {
				return toolResponse(await siteDiscover.siteDiscover(buildContext(ctx, input.site)));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-gutenberg-compose',
		'Compose Gutenberg block markup from a simple JSON block spec (local, no network). Pass result.markup to content-create/update.',
		{
			site: siteArg,
			blocks: z.array(z.record(z.unknown())).min(1),
		},
		async (input) => {
			try {
				const runtime = buildContext(ctx, input.site);
				return toolResponse(
					gutenberg.gutenbergCompose(
						{ site: runtime.site },
						{ blocks: input.blocks as never },
					),
				);
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-blueprint-list',
		'List bundled landing blueprints available in Direct mode (Gutenberg apply only).',
		{ site: siteArg },
		async () => {
			try {
				return toolResponse({ ok: true, blueprints: blueprints.listBlueprints() });
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-blueprint-get',
		'Get a bundled blueprint JSON by id (Direct mode).',
		{ site: siteArg, id: z.string().min(1) },
		async (input) => {
			try {
				const bp = blueprints.getBlueprint(input.id);
				if (!bp) {
					return toolResponse({ ok: false, error: 'not_found', id: input.id });
				}
				return toolResponse({ ok: true, blueprint: bp });
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-blueprint-apply',
		'Apply a bundled blueprint as a Gutenberg draft via core REST. Elementor engine requires the Stonewright plugin.',
		{
			site: siteArg,
			id: z.string().min(1),
			title: z.string().optional(),
			status: z.enum(['draft', 'publish']).optional(),
			post_id: z.number().int().positive().optional(),
			confirm: z.boolean().optional(),
			engine: z.string().optional(),
		},
		async (input) => {
			try {
				const runtime = buildContext(ctx, input.site);
				const applyArgs: {
					id: string;
					title?: string;
					status?: 'draft' | 'publish';
					post_id?: number;
					confirm?: boolean;
					engine?: string;
				} = { id: input.id };
				if (input.title !== undefined) applyArgs.title = input.title;
				if (input.status !== undefined) applyArgs.status = input.status;
				if (input.post_id !== undefined) applyArgs.post_id = input.post_id;
				if (input.confirm !== undefined) applyArgs.confirm = input.confirm;
				if (input.engine !== undefined) applyArgs.engine = input.engine;
				return toolResponse(await blueprints.applyBlueprint(runtime.client, applyArgs, ctx.env));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	return [...DIRECT_TOOL_NAMES];
}
