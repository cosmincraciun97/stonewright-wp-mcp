import { assertToolEnabled } from '../writes.js';
import type { DirectToolContext } from './types.js';

type WpUser = {
	id: number;
	name?: string | undefined;
	slug?: string | undefined;
	url?: string | undefined;
	description?: string | undefined;
	link?: string | undefined;
	username?: string | undefined;
	email?: string | undefined;
	roles?: string[] | undefined;
	capabilities?: Record<string, boolean> | undefined;
};

function compactUser(user: WpUser, includePrivate = false) {
	const base = {
		id: user.id,
		name: user.name ?? '',
		slug: user.slug ?? '',
		url: user.url ?? '',
		description: user.description ?? '',
		link: user.link ?? '',
		roles: user.roles ?? [],
	};
	if (!includePrivate) return base;
	return {
		...base,
		username: user.username ?? '',
		email: user.email ?? '',
	};
}

export async function userList(
	ctx: DirectToolContext,
	input: {
		search?: string | undefined;
		roles?: string | undefined;
		per_page?: number | undefined;
		page?: number | undefined;
	} = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-user-list');
	const perPage = Math.min(Math.max(input.per_page ?? 20, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpUser[]>('/wp/v2/users', {
		query: {
			search: input.search,
			roles: input.roles,
			per_page: perPage,
			page,
			_fields: 'id,name,slug,url,description,link,roles',
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map((row) => compactUser(row)),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}

export async function userGet(ctx: DirectToolContext, input: { id: number }) {
	assertToolEnabled(ctx.site, 'stonewright-user-get');
	const user = await ctx.client.get<WpUser>(`/wp/v2/users/${input.id}`, {
		query: { context: 'edit', _fields: 'id,name,slug,url,description,link,roles,username,email' },
	});
	return compactUser(user, true);
}

export async function userMe(ctx: DirectToolContext) {
	assertToolEnabled(ctx.site, 'stonewright-user-me');
	const user = await ctx.client.get<WpUser>('/wp/v2/users/me', {
		query: { context: 'edit', _fields: 'id,name,slug,url,description,link,roles,username,email' },
	});
	return compactUser(user, true);
}
