import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

function restBaseForType(type: string): string {
	if (type === 'page') return 'pages';
	if (type === 'post' || type === '') return 'posts';
	return type;
}

export async function gutenbergValidate(
	ctx: DirectToolContext,
	input: { post_id: number; type?: string },
) {
	assertToolEnabled(ctx.site, 'stonewright-gutenberg-validate');
	const base = restBaseForType(input.type ?? 'page');
	const row = await ctx.client.get<{
		id?: number;
		content?: { raw?: string; rendered?: string };
	}>(`/wp/v2/${base}/${input.post_id}`, {
		query: { context: 'edit', _fields: 'id,content' },
	});

	const raw = String(row.content?.raw ?? '');
	const rendered = String(row.content?.rendered ?? '');
	const open = (raw.match(/<!--\s*wp:/g) ?? []).length;
	const close = (raw.match(/<!--\s*\/wp:/g) ?? []).length;
	const names = [...raw.matchAll(/<!--\s*wp:([a-z0-9/-]+)/gi)].map((m) => m[1] ?? '');
	const unique = [...new Set(names.filter(Boolean))];
	const suspicious: string[] = [];
	if (raw.length > 0 && rendered.trim().length === 0) {
		suspicious.push('raw content present but rendered output empty');
	}
	if (open !== close) {
		suspicious.push(`unbalanced block comment delimiters (${open} open vs ${close} close)`);
	}

	return {
		post_id: input.post_id,
		has_blocks: open > 0,
		block_names: unique,
		raw_length: raw.length,
		rendered_length: rendered.length,
		suspicious,
	};
}
