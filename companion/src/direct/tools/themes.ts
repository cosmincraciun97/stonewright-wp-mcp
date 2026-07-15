import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

type WpTheme = {
	stylesheet: string;
	template?: string | undefined;
	name?: { raw?: string; rendered?: string } | string;
	status?: string | undefined;
	version?: string | undefined;
	author?: { raw?: string; rendered?: string } | string;
	description?: { raw?: string; rendered?: string } | string;
	theme_supports?: Record<string, unknown> | undefined;
};

function textOf(value: { raw?: string; rendered?: string } | string | undefined): string {
	if (typeof value === 'string') return value;
	return value?.raw ?? value?.rendered ?? '';
}

function compactTheme(theme: WpTheme) {
	return {
		stylesheet: theme.stylesheet,
		template: theme.template ?? '',
		name: textOf(theme.name),
		status: theme.status ?? '',
		version: theme.version ?? '',
		author: textOf(theme.author),
		description: textOf(theme.description),
	};
}

export async function themeList(
	ctx: DirectToolContext,
	input: { status?: string | undefined } = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-theme-list');
	const items = await ctx.client.get<WpTheme[]>('/wp/v2/themes', {
		query: {
			status: input.status,
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactTheme),
		total: list.length,
	};
}
