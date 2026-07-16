import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import type { DirectToolContext } from './types.js';

function restBaseForType(type: string): string {
	if (type === 'page') return 'pages';
	if (type === 'post' || type === '') return 'posts';
	return type;
}

export async function acfFieldsGet(
	ctx: DirectToolContext,
	input: { id: number; type?: string },
) {
	assertToolEnabled(ctx.site, 'stonewright-acf-fields-get');
	const base = restBaseForType(input.type ?? 'post');
	const row = await ctx.client.get<Record<string, unknown>>(`/wp/v2/${base}/${input.id}`, {
		query: { context: 'edit' },
	});
	if (row && typeof row === 'object' && 'acf' in row) {
		return { id: input.id, acf: row.acf ?? {}, source: 'rest' };
	}
	return {
		id: input.id,
		acf: null,
		hint: 'No acf object on this REST response. Enable ACF "Show in REST" or use the Stonewright plugin acf-values-get ability.',
	};
}

export async function acfFieldsUpdate(
	ctx: DirectToolContext,
	input: { id: number; type?: string; acf: Record<string, unknown>; confirm?: boolean },
) {
	assertToolEnabled(ctx.site, 'stonewright-acf-fields-update');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: false,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-acf-fields-update',
	});
	const base = restBaseForType(input.type ?? 'post');
	const updated = await ctx.client.post<Record<string, unknown>>(`/wp/v2/${base}/${input.id}`, {
		body: { acf: input.acf },
	});
	return {
		id: input.id,
		acf: updated && typeof updated === 'object' && 'acf' in updated ? updated.acf : input.acf,
		ok: true,
	};
}

export async function seoHeadGet(
	ctx: DirectToolContext,
	input: { id: number; type?: string },
) {
	assertToolEnabled(ctx.site, 'stonewright-seo-head-get');
	const base = restBaseForType(input.type ?? 'post');
	try {
		const row = await ctx.client.get<Record<string, unknown>>(`/wp/v2/${base}/${input.id}`, {
			query: { _fields: 'id,yoast_head_json,link' },
		});
		const head = (row?.yoast_head_json ?? null) as Record<string, unknown> | null;
		if (head && typeof head === 'object') {
			return {
				id: input.id,
				plugin: 'yoast',
				title: String(head.title ?? ''),
				description: String(head.description ?? ''),
				canonical: String(head.canonical ?? ''),
				robots: head.robots ?? null,
			};
		}
	} catch {
		// fall through
	}
	return {
		id: input.id,
		plugin: null,
		hint: 'No Yoast head JSON on this post. Install an SEO plugin or use plugin-mode seo-meta-get.',
	};
}
