import type { WpRestClient } from '../wp-rest-client.js';
import { assertToolEnabled, assertWriteAllowed, type DirectWriteMode } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { ResolvedSite } from '../sites-config.js';

export interface ContentToolContext {
	client: WpRestClient;
	site: ResolvedSite;
	writeMode: DirectWriteMode;
}

type WpPost = {
	id: number;
	slug?: string | undefined;
	status?: string | undefined;
	modified?: string | undefined;
	link?: string | undefined;
	type?: string | undefined;
	title?: { rendered?: string; raw?: string } | string;
	content?: { rendered?: string; raw?: string } | string;
	excerpt?: { rendered?: string; raw?: string } | string;
	parent?: number | undefined;
	template?: string | undefined;
	meta?: Record<string, unknown> | undefined;
};

function titleOf(post: WpPost): string {
	if (typeof post.title === 'string') {
		return post.title;
	}
	return post.title?.raw ?? post.title?.rendered ?? '';
}

function compactPost(post: WpPost) {
	return {
		id: post.id,
		title: titleOf(post),
		slug: post.slug ?? '',
		status: post.status ?? '',
		modified: post.modified ?? '',
		link: post.link ?? '',
		type: post.type ?? '',
	};
}

function collectionFor(type: string): string {
	const normalized = type.trim().toLowerCase() || 'pages';
	if (normalized === 'page' || normalized === 'pages') {
		return 'pages';
	}
	if (normalized === 'post' || normalized === 'posts') {
		return 'posts';
	}
	return normalized;
}

export async function contentList(
	ctx: ContentToolContext,
	input: {
		type?: string | undefined;
		search?: string | undefined;
		status?: string | undefined;
		per_page?: number | undefined;
		page?: number | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-content-list');
	const collection = collectionFor(input.type ?? 'pages');
	const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpPost[]>(`/wp/v2/${collection}`, {
		query: {
			search: input.search,
			status: input.status,
			per_page: perPage,
			page,
			_fields: 'id,title,slug,status,modified,link,type',
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactPost),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}

export async function contentGet(
	ctx: ContentToolContext,
	input: { type?: string | undefined; id: number; fields?: 'raw' | 'rendered' | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-content-get');
	const collection = collectionFor(input.type ?? 'pages');
	const context = input.fields === 'raw' ? 'edit' : 'view';
	const post = await ctx.client.get<WpPost>(`/wp/v2/${collection}/${input.id}`, {
		query: { context },
	});
	const content =
		typeof post.content === 'string'
			? post.content
			: input.fields === 'raw'
				? (post.content?.raw ?? '')
				: (post.content?.rendered ?? '');
	return {
		...compactPost(post),
		content,
		parent: post.parent ?? 0,
		template: post.template ?? '',
		meta: post.meta ?? {},
	};
}

export async function contentCreate(
	ctx: ContentToolContext,
	input: {
		kind: 'page' | 'post';
		title: string;
		content?: string | undefined;
		status?: string | undefined;
		parent?: number | undefined;
		template?: string | undefined;
		meta?: Record<string, unknown> | undefined;
	},
) {
	const tool = input.kind === 'page' ? 'stonewright-content-create-page' : 'stonewright-content-create-post';
	assertToolEnabled(ctx.site, tool);
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool });
	const collection = input.kind === 'page' ? 'pages' : 'posts';
	try {
		const post = await ctx.client.post<WpPost>(`/wp/v2/${collection}`, {
			body: {
				title: input.title,
				content: input.content,
				status: input.status ?? 'draft',
				parent: input.parent,
				template: input.template,
				meta: input.meta,
			},
		});
		appendDirectAudit({
			tool,
			site: ctx.site.alias,
			resource: `${collection}/${post.id}`,
			status: 'ok',
		});
		return compactPost(post);
	} catch (err) {
		appendDirectAudit({
			tool,
			site: ctx.site.alias,
			resource: collection,
			status: 'error',
			code: err instanceof Error ? err.name : 'error',
		});
		throw err;
	}
}

export async function contentUpdate(
	ctx: ContentToolContext,
	input: {
		type?: string | undefined;
		id: number;
		title?: string | undefined;
		content?: string | undefined;
		status?: string | undefined;
		parent?: number | undefined;
		template?: string | undefined;
		meta?: Record<string, unknown> | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-content-update');
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-content-update' });
	const collection = collectionFor(input.type ?? 'pages');
	const body: Record<string, unknown> = {};
	if (input.title !== undefined) body.title = input.title;
	if (input.content !== undefined) body.content = input.content;
	if (input.status !== undefined) body.status = input.status;
	if (input.parent !== undefined) body.parent = input.parent;
	if (input.template !== undefined) body.template = input.template;
	if (input.meta !== undefined) body.meta = input.meta;

	try {
		const post = await ctx.client.put<WpPost>(`/wp/v2/${collection}/${input.id}`, { body });
		appendDirectAudit({
			tool: 'stonewright-content-update',
			site: ctx.site.alias,
			resource: `${collection}/${input.id}`,
			status: 'ok',
		});
		return compactPost(post);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-content-update',
			site: ctx.site.alias,
			resource: `${collection}/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}

export async function contentDelete(
	ctx: ContentToolContext,
	input: { type?: string | undefined; id: number; force?: boolean | undefined; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-content-delete');
	const force = input.force === true;
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: force,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-content-delete',
	});
	const collection = collectionFor(input.type ?? 'pages');
	try {
		const result = await ctx.client.del<unknown>(`/wp/v2/${collection}/${input.id}`, {
			query: { force },
		});
		appendDirectAudit({
			tool: 'stonewright-content-delete',
			site: ctx.site.alias,
			resource: `${collection}/${input.id}`,
			status: 'ok',
		});
		return { deleted: true, force, result };
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-content-delete',
			site: ctx.site.alias,
			resource: `${collection}/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}

export async function contentRevisions(
	ctx: ContentToolContext,
	input: { type?: string | undefined; id: number; per_page?: number | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-content-revisions');
	const collection = collectionFor(input.type ?? 'pages');
	const perPage = Math.min(Math.max(input.per_page ?? 10, 1), 50);
	const revisions = await ctx.client.get<WpPost[]>(`/wp/v2/${collection}/${input.id}/revisions`, {
		query: {
			per_page: perPage,
			_fields: 'id,title,slug,status,modified,author',
		},
	});
	const list = Array.isArray(revisions) ? revisions : [];
	return {
		items: list.map((row) => ({
			id: row.id,
			title: titleOf(row),
			modified: row.modified ?? '',
		})),
		total: list.length,
	};
}
