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
import * as comments from './tools/comments.js';
import * as widgets from './tools/widgets.js';
import * as health from './tools/health.js';
import * as woocommerce from './tools/woocommerce.js';
import * as restRequest from './tools/rest-request.js';
import * as selfImprove from './tools/self-improve.js';
import * as acf from './tools/acf.js';
import * as elementorDirect from './tools/elementor-direct.js';
import * as gutenbergValidate from './tools/gutenberg-validate.js';
import * as agentsMd from './agents-md.js';
import { appendDirectAudit } from './audit.js';

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


export const DIRECT_WAVE3_TOOL_NAMES = [
	// comments
	'stonewright-comment-list',
	'stonewright-comment-get',
	'stonewright-comment-create',
	'stonewright-comment-update',
	'stonewright-comment-delete',
	// users + application passwords
	'stonewright-user-create',
	'stonewright-user-update',
	'stonewright-user-delete',
	'stonewright-app-password-list',
	'stonewright-app-password-create',
	'stonewright-app-password-revoke',
	// revisions + autosaves
	'stonewright-content-revision-get',
	'stonewright-content-revision-delete',
	'stonewright-content-autosaves',
	'stonewright-content-autosave-create',
	// media
	'stonewright-media-delete',
	// widgets + sidebars
	'stonewright-sidebar-list',
	'stonewright-widget-list',
	'stonewright-widget-manage',
	// themes
	'stonewright-theme-activate',
	'stonewright-custom-css',
	// site health
	'stonewright-health-check',
	'stonewright-health-test',
	// search / editor utilities
	'stonewright-oembed',
	'stonewright-url-details',
	'stonewright-block-directory-search',
	'stonewright-pattern-directory-search',
	// FSE completion
	'stonewright-template-create',
	'stonewright-template-delete',
	'stonewright-template-part-create',
	'stonewright-template-part-delete',
	'stonewright-global-styles-theme',
	'stonewright-global-styles-revisions',
	// plugins completion
	'stonewright-plugin-get',
	'stonewright-plugin-delete',
	// menus completion
	'stonewright-menu-locations',
	// WooCommerce (read-only)
	'stonewright-wc-products',
	'stonewright-wc-orders',
	'stonewright-wc-sales-report',
	// generic guarded passthrough
	'stonewright-rest-request',
] as const;

export const DIRECT_WAVE4_SELFIMPROVE_TOOL_NAMES = [
	'stonewright-skill-list',
	'stonewright-skill-get',
	'stonewright-skill-save',
	'stonewright-skill-delete',
	'stonewright-memory-list',
	'stonewright-learning-record',
	'stonewright-task-start',
] as const;

export const DIRECT_WAVE4_ACF_SEO_TOOL_NAMES = [
	'stonewright-acf-fields-get',
	'stonewright-acf-fields-update',
	'stonewright-seo-head-get',
] as const;

export const DIRECT_WAVE4_TOOL_NAMES = [
	...DIRECT_WAVE4_SELFIMPROVE_TOOL_NAMES,
	...DIRECT_WAVE4_ACF_SEO_TOOL_NAMES,
] as const;

export const DIRECT_WAVE5_TOOL_NAMES = [
	'stonewright-elementor-status',
	'stonewright-elementor-data-get',
	'stonewright-elementor-data-update',
	'stonewright-gutenberg-validate',
	'stonewright-agents-md-sync',
] as const;

export const DIRECT_TOOL_NAMES = [
	...DIRECT_WAVE1_TOOL_NAMES,
	...DIRECT_WAVE2_TOOL_NAMES,
	...DIRECT_WAVE3_TOOL_NAMES,
	...DIRECT_WAVE4_TOOL_NAMES,
	...DIRECT_WAVE5_TOOL_NAMES,
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

function toolError(err: unknown, meta?: { tool?: string; site?: string }) {
	const message =
		err instanceof WpRestError
			? JSON.stringify(err.toJSON())
			: err instanceof Error
				? err.message
				: String(err);
	if (meta?.tool) {
		try {
			appendDirectAudit({
				tool: meta.tool,
				site: meta.site && meta.site.length > 0 ? meta.site : '_global',
				status: 'error',
				error: message.slice(0, 200),
			});
		} catch {
			// best-effort audit
		}
	}
	if (err instanceof WpRestError) {
		return {
			isError: true as const,
			content: [{ type: 'text' as const, text: JSON.stringify(err.toJSON(), null, 2) }],
		};
	}
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

	// --- Wave 3 tools ---
	const w3 = (name: string, desc: string, shape: Record<string, z.ZodTypeAny>, fn: (input: Record<string, unknown>, runtime: ReturnType<typeof buildContext>) => Promise<unknown>) => {
		server.tool(name, desc, shape, async (input) => {
			const site = String((input as { site?: string }).site ?? '_global');
			try {
				return toolResponse(await fn(input as Record<string, unknown>, buildContext(ctx, (input as { site?: string }).site)));
			} catch (err) {
				return toolError(err, { tool: name, site });
			}
		});
	};

	w3('stonewright-comment-list', 'List comments via core REST (Direct mode).', {
		site: siteArg, post: z.number().int().optional(), status: z.string().optional(), search: z.string().optional(),
		per_page: z.number().int().min(1).max(50).optional(), page: z.number().int().min(1).optional(),
	}, (input, runtime) => comments.commentList(runtime, input as never));
	w3('stonewright-comment-get', 'Get a comment via core REST (Direct mode).', {
		site: siteArg, id: z.number().int().positive(),
	}, (input, runtime) => comments.commentGet(runtime, input as never));
	w3('stonewright-comment-create', 'Create a comment via core REST (Direct mode).', {
		site: siteArg, post: z.number().int().positive(), content: z.string().min(1), parent: z.number().int().optional(),
		author_name: z.string().optional(), author_email: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => comments.commentCreate(runtime, input as never));
	w3('stonewright-comment-update', 'Update or moderate a comment (status: approved|hold|spam|trash) via core REST (Direct mode).', {
		site: siteArg, id: z.number().int().positive(), content: z.string().optional(),
		status: z.enum(['approved', 'hold', 'spam', 'trash']).optional(), confirm: confirmArg,
	}, (input, runtime) => comments.commentUpdate(runtime, input as never));
	w3('stonewright-comment-delete', 'Delete a comment via core REST (Direct mode).', {
		site: siteArg, id: z.number().int().positive(), force: z.boolean().optional(), confirm: confirmArg,
	}, (input, runtime) => comments.commentDelete(runtime, input as never));

	w3('stonewright-user-create', 'Create a user via core REST (Direct mode).', {
		site: siteArg, username: z.string().min(1), email: z.string().email(), password: z.string().min(12),
		roles: z.array(z.string()).optional(), name: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => users.userCreate(runtime, input as never));
	w3('stonewright-user-update', 'Update a user via core REST (Direct mode).', {
		site: siteArg, id: z.number().int().positive(), email: z.string().email().optional(), name: z.string().optional(),
		roles: z.array(z.string()).optional(), password: z.string().min(12).optional(), confirm: confirmArg,
	}, (input, runtime) => users.userUpdate(runtime, input as never));
	w3('stonewright-user-delete', 'Delete a user via core REST (requires reassign + confirm:true always).', {
		site: siteArg, id: z.number().int().positive(), reassign: z.number().int().positive(), confirm: confirmArg,
	}, (input, runtime) => users.userDelete(runtime, input as never));
	w3('stonewright-app-password-list', 'List application passwords for a user (no secret material).', {
		site: siteArg, user_id: z.number().int().positive(),
	}, (input, runtime) => users.appPasswordList(runtime, input as never));
	w3('stonewright-app-password-create', 'Create an application password (plaintext returned once; confirm:true always).', {
		site: siteArg, user_id: z.number().int().positive(), name: z.string().min(1), confirm: confirmArg,
	}, (input, runtime) => users.appPasswordCreate(runtime, input as never));
	w3('stonewright-app-password-revoke', 'Revoke an application password (confirm:true always).', {
		site: siteArg, user_id: z.number().int().positive(), uuid: z.string().min(1), confirm: confirmArg,
	}, (input, runtime) => users.appPasswordRevoke(runtime, input as never));

	w3('stonewright-content-revision-get', 'Get a single post revision via core REST (Direct mode).', {
		site: siteArg, type: z.string().optional(), id: z.number().int().positive(), revision_id: z.number().int().positive(),
	}, (input, runtime) => content.contentRevisionGet(runtime, input as never));
	w3('stonewright-content-revision-delete', 'Force-delete a post revision via core REST (Direct mode).', {
		site: siteArg, type: z.string().optional(), id: z.number().int().positive(), revision_id: z.number().int().positive(), confirm: confirmArg,
	}, (input, runtime) => content.contentRevisionDelete(runtime, input as never));
	w3('stonewright-content-autosaves', 'List or get autosaves for a post via core REST (Direct mode).', {
		site: siteArg, type: z.string().optional(), id: z.number().int().positive(), autosave_id: z.number().int().positive().optional(),
	}, (input, runtime) => content.contentAutosaves(runtime, input as never));
	w3('stonewright-content-autosave-create', 'Create an autosave via core REST (Direct mode).', {
		site: siteArg, type: z.string().optional(), id: z.number().int().positive(), title: z.string().optional(), content: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => content.contentAutosaveCreate(runtime, input as never));
	w3('stonewright-media-delete', 'Delete a media item via core REST (Direct mode).', {
		site: siteArg, id: z.number().int().positive(), force: z.boolean().optional(), confirm: confirmArg,
	}, (input, runtime) => media.mediaDelete(runtime, input as never));

	w3('stonewright-sidebar-list', 'List widget sidebars via core REST (Direct mode).', {
		site: siteArg, id: z.string().optional(),
	}, (input, runtime) => widgets.sidebarList(runtime, input as never));
	w3('stonewright-widget-list', 'List widgets via core REST (Direct mode).', {
		site: siteArg, sidebar: z.string().optional(),
	}, (input, runtime) => widgets.widgetList(runtime, input as never));
	w3('stonewright-widget-manage', 'Create, update, or delete a widget via core REST (Direct mode).', {
		site: siteArg, action: z.enum(['create', 'update', 'delete']), id: z.string().optional(),
		id_base: z.string().optional(), sidebar: z.string().optional(),
		instance: z.record(z.string(), z.unknown()).optional(), confirm: confirmArg,
	}, (input, runtime) => widgets.widgetManage(runtime, input as never));

	w3('stonewright-theme-activate', 'Activate a theme via core REST (confirm:true always required).', {
		site: siteArg, stylesheet: z.string().min(1), confirm: confirmArg,
	}, (input, runtime) => themes.themeActivate(runtime, input as never));
	w3('stonewright-custom-css', 'Probe or attempt custom CSS via settings REST (may require plugin).', {
		site: siteArg, action: z.enum(['get', 'update']), css: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => themes.customCss(runtime, input as never));

	w3('stonewright-health-check', 'Aggregate site health tests + directory sizes (Direct mode).', {
		site: siteArg,
	}, (_input, runtime) => health.healthCheck(runtime));
	w3('stonewright-health-test', 'Run one site health test via REST (Direct mode).', {
		site: siteArg, test: z.enum(['authorization-header','background-updates','dotorg-communication','https-status','loopback-requests','page-cache']),
	}, (input, runtime) => health.healthTest(runtime, input as never));

	w3('stonewright-oembed', 'Resolve oEmbed data for a URL (Direct mode).', {
		site: siteArg, url: z.string().url(), maxwidth: z.number().int().optional(), proxy: z.boolean().optional(),
	}, (input, runtime) => search.oembed(runtime, input as never));
	w3('stonewright-url-details', 'Fetch block-editor URL details (Direct mode).', {
		site: siteArg, url: z.string().url(),
	}, (input, runtime) => search.urlDetails(runtime, input as never));
	w3('stonewright-block-directory-search', 'Search the WordPress.org block directory (Direct mode).', {
		site: siteArg, term: z.string().min(1), page: z.number().int().min(1).optional(), per_page: z.number().int().min(1).max(50).optional(),
	}, (input, runtime) => search.blockDirectorySearch(runtime, input as never));
	w3('stonewright-pattern-directory-search', 'Search the pattern directory (Direct mode).', {
		site: siteArg, search: z.string().optional(), category: z.string().optional(),
		per_page: z.number().int().min(1).max(50).optional(), include_content: z.boolean().optional(),
	}, (input, runtime) => search.patternDirectorySearch(runtime, input as never));

	w3('stonewright-template-create', 'Create an FSE template via core REST (Direct mode).', {
		site: siteArg, slug: z.string().min(1), title: z.string().optional(), content: z.string().optional(), description: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => templates.templateCreate(runtime, input as never));
	w3('stonewright-template-delete', 'Delete an FSE template via core REST (Direct mode).', {
		site: siteArg, id: z.string().min(1), force: z.boolean().optional(), confirm: confirmArg,
	}, (input, runtime) => templates.templateDelete(runtime, input as never));
	w3('stonewright-template-part-create', 'Create an FSE template part via core REST (Direct mode).', {
		site: siteArg, slug: z.string().min(1), title: z.string().optional(), content: z.string().optional(), area: z.string().optional(), confirm: confirmArg,
	}, (input, runtime) => templates.templatePartCreate(runtime, input as never));
	w3('stonewright-template-part-delete', 'Delete an FSE template part via core REST (Direct mode).', {
		site: siteArg, id: z.string().min(1), force: z.boolean().optional(), confirm: confirmArg,
	}, (input, runtime) => templates.templatePartDelete(runtime, input as never));
	w3('stonewright-global-styles-theme', 'Get global styles theme defaults or variations (Direct mode).', {
		site: siteArg, stylesheet: z.string().optional(), variations: z.boolean().optional(),
	}, (input, runtime) => globalStyles.globalStylesTheme(runtime, input as never));
	w3('stonewright-global-styles-revisions', 'List or get global styles revisions (Direct mode).', {
		site: siteArg, parent: z.string().min(1), revision_id: z.number().int().positive().optional(),
	}, (input, runtime) => globalStyles.globalStylesRevisions(runtime, input as never));

	w3('stonewright-plugin-get', 'Get a plugin via core REST (Direct mode).', {
		site: siteArg, plugin: z.string().min(1),
	}, (input, runtime) => plugins.pluginGet(runtime, input as never));
	w3('stonewright-plugin-delete', 'Delete an inactive plugin (confirm:true always).', {
		site: siteArg, plugin: z.string().min(1), confirm: confirmArg,
	}, (input, runtime) => plugins.pluginDelete(runtime, input as never));
	w3('stonewright-menu-locations', 'List menu locations via core REST (Direct mode).', {
		site: siteArg,
	}, (_input, runtime) => menus.menuLocations(runtime));

	w3('stonewright-wc-products', 'List WooCommerce products (read-only Direct mode).', {
		site: siteArg, search: z.string().optional(), status: z.string().optional(),
		per_page: z.number().int().min(1).max(50).optional(), page: z.number().int().min(1).optional(),
	}, (input, runtime) => woocommerce.wcProducts(runtime, input as never));
	w3('stonewright-wc-orders', 'List WooCommerce orders (read-only Direct mode).', {
		site: siteArg, status: z.string().optional(), after: z.string().optional(), before: z.string().optional(),
		per_page: z.number().int().min(1).max(50).optional(), page: z.number().int().min(1).optional(),
	}, (input, runtime) => woocommerce.wcOrders(runtime, input as never));
	w3('stonewright-wc-sales-report', 'WooCommerce sales report (read-only Direct mode).', {
		site: siteArg, period: z.string().optional(), date_min: z.string().optional(), date_max: z.string().optional(),
	}, (input, runtime) => woocommerce.wcSalesReport(runtime, input as never));

	w3(
		'stonewright-rest-request',
		'Read-only escape hatch for REST namespaces without a dedicated tool (GET only). Use stonewright-site-discover first. Writes must use typed Direct tools or WP-CLI.',
		{
			site: siteArg,
			method: z.enum(['GET']).optional().default('GET'),
			path: z.string().min(1),
			query: z.record(z.string(), z.union([z.string(), z.number(), z.boolean()])).optional(),
		},
		(input, runtime) => restRequest.restRequest(runtime, input as never),
	);

	// --- Wave 4: pluginless self-improvement (no WordPress credentials required) ---
	const selfCtx = (): selfImprove.SelfImproveContext => ({
		env: ctx.env,
		directToolCount: DIRECT_TOOL_NAMES.length,
	});

	const w4 = (
		name: string,
		description: string,
		shape: Record<string, z.ZodTypeAny>,
		handler: (input: Record<string, unknown>) => unknown | Promise<unknown>,
	) => {
		server.tool(name, description, shape, async (input) => {
			const site = String((input as { site?: string }).site ?? '_global');
			try {
				return toolResponse(await handler(input as Record<string, unknown>));
			} catch (err) {
				return toolError(err, { tool: name, site });
			}
		});
	};

	w4(
		'stonewright-skill-list',
		'List local companion skills for this site (or _global). Compact index only — no bodies.',
		{ site: siteArg },
		(input) => selfImprove.skillList(selfCtx(), input as never),
	);
	w4(
		'stonewright-skill-get',
		'Load one local skill body by slug (on-demand).',
		{ site: siteArg, slug: z.string().min(1) },
		(input) => selfImprove.skillGet(selfCtx(), input as never),
	);
	w4(
		'stonewright-skill-save',
		'Create or update a local companion skill under ~/.stonewright/skills/.',
		{
			site: siteArg,
			slug: z.string().min(1),
			name: z.string().min(1),
			description: z.string(),
			triggers: z.array(z.string()),
			body: z.string(),
			enabled: z.boolean().optional(),
			global: z.boolean().optional(),
		},
		(input) => selfImprove.skillSave(selfCtx(), input as never),
	);
	w4(
		'stonewright-skill-delete',
		'Delete a local companion skill. Requires confirm:true.',
		{
			site: siteArg,
			slug: z.string().min(1),
			confirm: z.boolean().optional(),
			global: z.boolean().optional(),
		},
		(input) => selfImprove.skillDelete(selfCtx(), input as never),
	);
	w4(
		'stonewright-memory-list',
		'List local companion memory entries (newest first).',
		{ site: siteArg, limit: z.number().int().min(1).max(100).optional() },
		(input) => selfImprove.memoryList(selfCtx(), input as never),
	);
	w4(
		'stonewright-learning-record',
		'Record a correction/lesson in local memory; optional disabled draft skill.',
		{
			site: siteArg,
			text: z.string().min(1),
			kind: z.enum(['correction', 'lesson', 'preference', 'fact']).optional(),
			tags: z.array(z.string()).optional(),
			draft_skill: z
				.object({
					slug: z.string(),
					name: z.string(),
					description: z.string(),
					triggers: z.array(z.string()),
					body: z.string(),
				})
				.optional(),
		},
		(input) => selfImprove.learningRecord(selfCtx(), input as never),
	);
	w4(
		'stonewright-task-start',
		'Direct-mode task start: matched local skills, memory highlights, write mode, and guidance. Works with zero WordPress credentials.',
		{
			site: siteArg,
			task: z.string().min(1),
			surface: z.string().optional(),
			intent: z.string().optional(),
		},
		(input) => selfImprove.taskStart(selfCtx(), input as never),
	);

	w3(
		'stonewright-acf-fields-get',
		'Read ACF field values from core REST when ACF Show in REST is enabled.',
		{
			site: siteArg,
			id: z.number().int().positive(),
			type: z.string().optional(),
		},
		(input, runtime) => acf.acfFieldsGet(runtime, input as never),
	);
	w3(
		'stonewright-acf-fields-update',
		'Update ACF field values via core REST (requires ACF Show in REST). Gated by STONEWRIGHT_DIRECT_WRITES.',
		{
			site: siteArg,
			id: z.number().int().positive(),
			type: z.string().optional(),
			acf: z.record(z.string(), z.unknown()),
			confirm: confirmArg,
		},
		(input, runtime) => acf.acfFieldsUpdate(runtime, input as never),
	);
	w3(
		'stonewright-seo-head-get',
		'Read Yoast SEO head JSON for a post when available over REST.',
		{
			site: siteArg,
			id: z.number().int().positive(),
			type: z.string().optional(),
		},
		(input, runtime) => acf.seoHeadGet(runtime, input as never),
	);

	// --- Wave 5: pluginless Elementor/Gutenberg + agents-md ---
	w4(
		'stonewright-elementor-status',
		'Detect local WP-CLI + Elementor availability for Direct-mode data editing.',
		{ site: siteArg, cwd: z.string().optional(), path: z.string().optional() },
		(input) => elementorDirect.elementorStatus(ctx.env, input as never),
	);
	w4(
		'stonewright-elementor-data-get',
		'Read _elementor_data for a post via tokenized WP-CLI (local sites).',
		{
			site: siteArg,
			post_id: z.number().int().positive(),
			cwd: z.string().optional(),
			path: z.string().optional(),
		},
		(input) => elementorDirect.elementorDataGet(ctx.env, input as never),
	);
	w4(
		'stonewright-elementor-data-update',
		'Update _elementor_data via WP-CLI with mandatory file backup. JSON is passed on stdin (ARG_MAX safe). CSS flush is best-effort.',
		{
			site: siteArg,
			post_id: z.number().int().positive(),
			data: z.union([z.string(), z.array(z.unknown()), z.record(z.string(), z.unknown())]),
			confirm: confirmArg,
			cwd: z.string().optional(),
			path: z.string().optional(),
		},
		(input) => elementorDirect.elementorDataUpdate(ctx.env, input as never),
	);
	w3(
		'stonewright-gutenberg-validate',
		'Validate Gutenberg block markup round-trip for a post (raw vs rendered heuristics).',
		{
			site: siteArg,
			post_id: z.number().int().positive(),
			type: z.string().optional(),
		},
		(input, runtime) => gutenbergValidate.gutenbergValidate(runtime, input as never),
	);
	w4(
		'stonewright-agents-md-sync',
		'Read-only: report ~/.stonewright/AGENTS.md path and which global agent configs lack the Stonewright pointer.',
		{ extra_paths: z.array(z.string()).optional() },
		(input) => agentsMd.agentsMdSync(ctx.env, input as never),
	);

	return [...DIRECT_TOOL_NAMES];
}
