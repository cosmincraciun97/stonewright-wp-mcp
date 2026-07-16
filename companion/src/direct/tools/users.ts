import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
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


function requireConfirm(confirm: boolean | undefined, tool: string): void {
	if (confirm !== true) {
		throw new Error(`confirm:true is required for this tool (${tool})`);
	}
}

export async function userCreate(
	ctx: DirectToolContext,
	input: {
		username: string;
		email: string;
		password: string;
		roles?: string[] | undefined;
		name?: string | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-user-create');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-user-create',
	});
	const user = await ctx.client.post<WpUser>('/wp/v2/users', {
		body: {
			username: input.username,
			email: input.email,
			password: input.password,
			...(input.roles !== undefined ? { roles: input.roles } : {}),
			...(input.name !== undefined ? { name: input.name } : {}),
		},
	});
	appendDirectAudit({ tool: 'stonewright-user-create', site: ctx.site.alias, resource: `users/${user.id}`, status: 'ok' });
	return compactUser(user, true);
}

export async function userUpdate(
	ctx: DirectToolContext,
	input: {
		id: number;
		email?: string | undefined;
		name?: string | undefined;
		roles?: string[] | undefined;
		password?: string | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-user-update');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-user-update',
	});
	const body: Record<string, unknown> = {};
	if (input.email !== undefined) body.email = input.email;
	if (input.name !== undefined) body.name = input.name;
	if (input.roles !== undefined) body.roles = input.roles;
	if (input.password !== undefined) body.password = input.password;
	const user = await ctx.client.post<WpUser>(`/wp/v2/users/${input.id}`, { body });
	appendDirectAudit({ tool: 'stonewright-user-update', site: ctx.site.alias, resource: `users/${input.id}`, status: 'ok' });
	return compactUser(user, true);
}

export async function userDelete(
	ctx: DirectToolContext,
	input: { id: number; reassign: number; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-user-delete');
	requireConfirm(input.confirm, 'stonewright-user-delete');
	const result = await ctx.client.del(`/wp/v2/users/${input.id}`, {
		query: { force: true, reassign: input.reassign },
	});
	appendDirectAudit({ tool: 'stonewright-user-delete', site: ctx.site.alias, resource: `users/${input.id}`, status: 'ok' });
	return result ?? { deleted: true, id: input.id };
}

type AppPassword = {
	uuid: string;
	name?: string | undefined;
	created?: string | undefined;
	last_used?: string | null | undefined;
	password?: string | undefined;
};

export async function appPasswordList(ctx: DirectToolContext, input: { user_id: number }) {
	assertToolEnabled(ctx.site, 'stonewright-app-password-list');
	const items = await ctx.client.get<AppPassword[]>(`/wp/v2/users/${input.user_id}/application-passwords`);
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map((row) => ({
			uuid: row.uuid,
			name: row.name ?? '',
			created: row.created ?? '',
			last_used: row.last_used ?? null,
		})),
		total: list.length,
	};
}

export async function appPasswordCreate(
	ctx: DirectToolContext,
	input: { user_id: number; name: string; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-app-password-create');
	requireConfirm(input.confirm, 'stonewright-app-password-create');
	const created = await ctx.client.post<AppPassword>(
		`/wp/v2/users/${input.user_id}/application-passwords`,
		{ body: { name: input.name } },
	);
	appendDirectAudit({
		tool: 'stonewright-app-password-create',
		site: ctx.site.alias,
		resource: `users/${input.user_id}/application-passwords`,
		status: 'ok',
	});
	return {
		uuid: created.uuid,
		name: created.name ?? input.name,
		password: created.password ?? '',
		note: 'Store this now; it cannot be retrieved again.',
	};
}

export async function appPasswordRevoke(
	ctx: DirectToolContext,
	input: { user_id: number; uuid: string; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-app-password-revoke');
	requireConfirm(input.confirm, 'stonewright-app-password-revoke');
	const result = await ctx.client.del(
		`/wp/v2/users/${input.user_id}/application-passwords/${encodeURIComponent(input.uuid)}`,
	);
	appendDirectAudit({
		tool: 'stonewright-app-password-revoke',
		site: ctx.site.alias,
		resource: `users/${input.user_id}/application-passwords/${input.uuid}`,
		status: 'ok',
	});
	return result ?? { deleted: true, uuid: input.uuid };
}
