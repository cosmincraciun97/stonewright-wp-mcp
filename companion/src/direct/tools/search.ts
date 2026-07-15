import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

type WpSearchResult = {
	id: number;
	title?: string | undefined;
	url?: string | undefined;
	type?: string | undefined;
	subtype?: string | undefined;
};

export async function siteSearch(
	ctx: DirectToolContext,
	input: {
		search: string;
		type?: string | undefined;
		subtype?: string | undefined;
		per_page?: number | undefined;
		page?: number | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-search');
	const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpSearchResult[]>('/wp/v2/search', {
		query: {
			search: input.search,
			type: input.type,
			subtype: input.subtype,
			per_page: perPage,
			page,
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map((row) => ({
			id: row.id,
			title: row.title ?? '',
			url: row.url ?? '',
			type: row.type ?? '',
			subtype: row.subtype ?? '',
		})),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}
