import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { loadSitesConfig, resolveSite, type SitesConfig } from './sites-config.js';
import { WpRestClient, WpRestError } from './wp-rest-client.js';
import { resolveDirectWriteMode } from './writes.js';
import * as content from './tools/content.js';
import * as media from './tools/media.js';
import * as taxonomy from './tools/taxonomy.js';

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
 * Register Direct mode wave-1 tools (content, media, taxonomy).
 * Call only when plugin MCP endpoint is unavailable or STONEWRIGHT_MODE=direct.
 */
export function registerDirectTools(server: McpServer, ctx: DirectModeContext): string[] {
	const siteArg = z.string().optional().describe('Site alias from ~/.stonewright/sites.json');

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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentList(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentGet(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentCreate(runtime, { ...input, kind: 'page' } as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentCreate(runtime, { ...input, kind: 'post' } as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

	server.tool(
		'stonewright-content-update',
		'Update a content item via core REST (Direct mode). Only provided fields are sent.',
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentUpdate(runtime, input as never));
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
			confirm: z.boolean().optional(),
		},
		async (input) => {
			try {
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentDelete(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await content.contentRevisions(runtime, input as never));
			} catch (err) {
				return toolError(err);
			}
		},
	);

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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await media.mediaList(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await media.mediaGet(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await media.mediaUpload(runtime, input as never));
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
				const runtime = buildContext(ctx, input.site);
				return toolResponse(await media.mediaUpdate(runtime, input as never));
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
			confirm: z.boolean().optional(),
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

	return [...DIRECT_WAVE1_TOOL_NAMES];
}
