import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

type WpBlockPattern = {
	name?: string | undefined;
	title?: string | undefined;
	description?: string | undefined;
	content?: string | undefined;
	categories?: string[] | undefined;
	keywords?: string[] | undefined;
	blockTypes?: string[] | undefined;
	viewportWidth?: number | undefined;
	inserter?: boolean | undefined;
};

export async function blockPatterns(
	ctx: DirectToolContext,
	input: { search?: string | undefined; category?: string | undefined; per_page?: number | undefined } = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-block-patterns');
	const items = await ctx.client.get<WpBlockPattern[]>('/wp/v2/block-patterns/patterns');
	let list = Array.isArray(items) ? items : [];
	const search = (input.search ?? '').trim().toLowerCase();
	const category = (input.category ?? '').trim().toLowerCase();
	if (search) {
		list = list.filter((row) => {
			const hay = `${row.name ?? ''} ${row.title ?? ''} ${row.description ?? ''}`.toLowerCase();
			return hay.includes(search);
		});
	}
	if (category) {
		list = list.filter((row) => (row.categories ?? []).some((c) => c.toLowerCase() === category));
	}
	const limit = Math.min(Math.max(input.per_page ?? 50, 1), 100);
	const sliced = list.slice(0, limit);
	return {
		items: sliced.map((row) => ({
			name: row.name ?? '',
			title: row.title ?? '',
			description: row.description ?? '',
			categories: row.categories ?? [],
			keywords: row.keywords ?? [],
			content: row.content ?? '',
		})),
		total: list.length,
		returned: sliced.length,
	};
}
