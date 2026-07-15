import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { DirectToolContext } from './types.js';

type WpComment = {
	id: number;
	post?: number | undefined;
	parent?: number | undefined;
	status?: string | undefined;
	author_name?: string | undefined;
	author_email?: string | undefined;
	date?: string | undefined;
	link?: string | undefined;
	content?: { rendered?: string; raw?: string } | undefined;
};

function compactComment(c: WpComment) {
	return {
		id: c.id,
		post: c.post ?? 0,
		parent: c.parent ?? 0,
		status: c.status ?? '',
		author_name: c.author_name ?? '',
		date: c.date ?? '',
		link: c.link ?? '',
		content: c.content?.raw ?? c.content?.rendered ?? '',
	};
}

export async function commentList(
	ctx: DirectToolContext,
	input: {
		post?: number | undefined;
		status?: string | undefined;
		search?: string | undefined;
		per_page?: number | undefined;
		page?: number | undefined;
	} = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-comment-list');
	const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpComment[]>('/wp/v2/comments', {
		query: {
			post: input.post,
			status: input.status,
			search: input.search,
			per_page: perPage,
			page,
			context: 'edit',
			_fields: 'id,post,parent,status,author_name,date,link,content',
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactComment),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}

export async function commentGet(ctx: DirectToolContext, input: { id: number }) {
	assertToolEnabled(ctx.site, 'stonewright-comment-get');
	const c = await ctx.client.get<WpComment>(`/wp/v2/comments/${input.id}`, {
		query: {
			context: 'edit',
			_fields: 'id,post,parent,status,author_name,author_email,date,link,content',
		},
	});
	return compactComment(c);
}

export async function commentCreate(
	ctx: DirectToolContext,
	input: {
		post: number;
		content: string;
		parent?: number | undefined;
		author_name?: string | undefined;
		author_email?: string | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-comment-create');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-comment-create',
	});
	const c = await ctx.client.post<WpComment>('/wp/v2/comments', {
		body: {
			post: input.post,
			content: input.content,
			...(input.parent !== undefined ? { parent: input.parent } : {}),
			...(input.author_name !== undefined ? { author_name: input.author_name } : {}),
			...(input.author_email !== undefined ? { author_email: input.author_email } : {}),
		},
	});
	appendDirectAudit({
		tool: 'stonewright-comment-create',
		site: ctx.site.alias,
		resource: `comments/${c.id}`,
		status: 'ok',
	});
	return compactComment(c);
}

export async function commentUpdate(
	ctx: DirectToolContext,
	input: {
		id: number;
		content?: string | undefined;
		status?: string | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-comment-update');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-comment-update',
	});
	const body: Record<string, unknown> = {};
	if (input.content !== undefined) body.content = input.content;
	if (input.status !== undefined) body.status = input.status;
	const c = await ctx.client.post<WpComment>(`/wp/v2/comments/${input.id}`, { body });
	appendDirectAudit({
		tool: 'stonewright-comment-update',
		site: ctx.site.alias,
		resource: `comments/${input.id}`,
		status: 'ok',
	});
	return compactComment(c);
}

export async function commentDelete(
	ctx: DirectToolContext,
	input: { id: number; force?: boolean | undefined; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-comment-delete');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-comment-delete',
	});
	const result = await ctx.client.del<unknown>(`/wp/v2/comments/${input.id}`, {
		query: { force: input.force === true },
	});
	appendDirectAudit({
		tool: 'stonewright-comment-delete',
		site: ctx.site.alias,
		resource: `comments/${input.id}`,
		status: 'ok',
	});
	return result ?? { deleted: true, id: input.id };
}
