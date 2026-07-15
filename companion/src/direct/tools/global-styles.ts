import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { DirectToolContext } from './types.js';

type WpGlobalStyles = {
	id: string;
	title?: { raw?: string; rendered?: string } | string;
	settings?: Record<string, unknown> | undefined;
	styles?: Record<string, unknown> | undefined;
};

function titleOf(item: WpGlobalStyles): string {
	if (typeof item.title === 'string') return item.title;
	return item.title?.raw ?? item.title?.rendered ?? '';
}

export async function globalStylesGet(ctx: DirectToolContext, input: { id?: string | undefined } = {}) {
	assertToolEnabled(ctx.site, 'stonewright-global-styles-get');
	const id = (input.id ?? 'themes').trim() || 'themes';
	const styles = await ctx.client.get<WpGlobalStyles>(`/wp/v2/global-styles/${encodeURIComponent(id)}`, {
		query: { context: 'edit' },
	});
	return {
		id: styles.id,
		title: titleOf(styles),
		settings: styles.settings ?? {},
		styles: styles.styles ?? {},
	};
}

export async function globalStylesUpdate(
	ctx: DirectToolContext,
	input: {
		id?: string | undefined;
		settings?: Record<string, unknown> | undefined;
		styles?: Record<string, unknown> | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-global-styles-update');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-global-styles-update',
	});
	const id = (input.id ?? 'themes').trim() || 'themes';
	const body: Record<string, unknown> = {};
	if (input.settings !== undefined) body.settings = input.settings;
	if (input.styles !== undefined) body.styles = input.styles;
	try {
		const styles = await ctx.client.post<WpGlobalStyles>(
			`/wp/v2/global-styles/${encodeURIComponent(id)}`,
			{ body },
		);
		appendDirectAudit({
			tool: 'stonewright-global-styles-update',
			site: ctx.site.alias,
			resource: `global-styles/${id}`,
			status: 'ok',
		});
		return {
			id: styles.id,
			title: titleOf(styles),
			settings: styles.settings ?? {},
			styles: styles.styles ?? {},
		};
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-global-styles-update',
			site: ctx.site.alias,
			resource: `global-styles/${id}`,
			status: 'error',
		});
		throw err;
	}
}


export async function globalStylesTheme(
	ctx: DirectToolContext,
	input: { stylesheet?: string | undefined; variations?: boolean | undefined } = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-global-styles-theme');
	let stylesheet = input.stylesheet?.trim() ?? '';
	if (!stylesheet) {
		const themes = await ctx.client.get<Array<{ stylesheet?: string }>>('/wp/v2/themes', {
			query: { status: 'active' },
		});
		const active = Array.isArray(themes) ? themes[0] : undefined;
		stylesheet = active?.stylesheet ?? '';
		if (!stylesheet) {
			throw new Error('Could not resolve active theme stylesheet');
		}
	}
	const path = input.variations
		? `/wp/v2/global-styles/themes/${encodeURIComponent(stylesheet)}/variations`
		: `/wp/v2/global-styles/themes/${encodeURIComponent(stylesheet)}`;
	return ctx.client.get(path);
}

export async function globalStylesRevisions(
	ctx: DirectToolContext,
	input: { parent: string; revision_id?: number | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-global-styles-revisions');
	const base = `/wp/v2/global-styles/${encodeURIComponent(input.parent)}/revisions`;
	if (input.revision_id !== undefined) {
		return ctx.client.get(`${base}/${input.revision_id}`);
	}
	return ctx.client.get(base);
}
