import { assertToolEnabled, assertWriteAllowed } from '../writes.js';
import { appendDirectAudit } from '../audit.js';
import type { DirectToolContext } from './types.js';

type WpMenu = {
	id: number;
	name?: string | undefined;
	slug?: string | undefined;
	description?: string | undefined;
	count?: number | undefined;
	locations?: string[] | undefined;
	auto_add?: boolean | undefined;
};

type WpMenuItem = {
	id: number;
	title?: { rendered?: string; raw?: string } | string;
	status?: string | undefined;
	url?: string | undefined;
	attr_title?: string | undefined;
	description?: string | undefined;
	type?: string | undefined;
	type_label?: string | undefined;
	object?: string | undefined;
	object_id?: number | undefined;
	parent?: number | undefined;
	menu_order?: number | undefined;
	target?: string | undefined;
	classes?: string[] | undefined;
	xfn?: string[] | undefined;
	invalid?: boolean | undefined;
	menus?: number | undefined;
};

function titleOf(item: WpMenuItem): string {
	if (typeof item.title === 'string') return item.title;
	return item.title?.raw ?? item.title?.rendered ?? '';
}

function compactMenu(menu: WpMenu) {
	return {
		id: menu.id,
		name: menu.name ?? '',
		slug: menu.slug ?? '',
		description: menu.description ?? '',
		count: menu.count ?? 0,
		locations: menu.locations ?? [],
	};
}

function compactMenuItem(item: WpMenuItem) {
	return {
		id: item.id,
		title: titleOf(item),
		status: item.status ?? '',
		url: item.url ?? '',
		type: item.type ?? '',
		object: item.object ?? '',
		object_id: item.object_id ?? 0,
		parent: item.parent ?? 0,
		menu_order: item.menu_order ?? 0,
		menus: item.menus ?? 0,
	};
}

export async function menuList(
	ctx: DirectToolContext,
	input: { search?: string | undefined; per_page?: number | undefined; page?: number | undefined } = {},
) {
	assertToolEnabled(ctx.site, 'stonewright-menu-list');
	const perPage = Math.min(Math.max(input.per_page ?? 50, 1), 50);
	const page = Math.max(input.page ?? 1, 1);
	const items = await ctx.client.get<WpMenu[]>('/wp/v2/menus', {
		query: {
			search: input.search,
			per_page: perPage,
			page,
			_fields: 'id,name,slug,description,count,locations',
		},
	});
	const list = Array.isArray(items) ? items : [];
	return {
		items: list.map(compactMenu),
		total: list.length,
		page,
		per_page: perPage,
		next_page: list.length === perPage ? page + 1 : undefined,
	};
}

export async function menuGet(ctx: DirectToolContext, input: { id: number }) {
	assertToolEnabled(ctx.site, 'stonewright-menu-get');
	const menu = await ctx.client.get<WpMenu>(`/wp/v2/menus/${input.id}`);
	return compactMenu(menu);
}

export async function menuCreate(
	ctx: DirectToolContext,
	input: { name: string; description?: string | undefined; locations?: string[] | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-menu-create');
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-menu-create' });
	try {
		const menu = await ctx.client.post<WpMenu>('/wp/v2/menus', {
			body: {
				name: input.name,
				description: input.description,
				locations: input.locations,
			},
		});
		appendDirectAudit({
			tool: 'stonewright-menu-create',
			site: ctx.site.alias,
			resource: `menus/${menu.id}`,
			status: 'ok',
		});
		return compactMenu(menu);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-menu-create',
			site: ctx.site.alias,
			resource: 'menus',
			status: 'error',
		});
		throw err;
	}
}

export async function menuUpdate(
	ctx: DirectToolContext,
	input: {
		id: number;
		name?: string | undefined;
		description?: string | undefined;
		locations?: string[] | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-menu-update');
	assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-menu-update' });
	const body: Record<string, unknown> = {};
	if (input.name !== undefined) body.name = input.name;
	if (input.description !== undefined) body.description = input.description;
	if (input.locations !== undefined) body.locations = input.locations;
	try {
		const menu = await ctx.client.put<WpMenu>(`/wp/v2/menus/${input.id}`, { body });
		appendDirectAudit({
			tool: 'stonewright-menu-update',
			site: ctx.site.alias,
			resource: `menus/${input.id}`,
			status: 'ok',
		});
		return compactMenu(menu);
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-menu-update',
			site: ctx.site.alias,
			resource: `menus/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}

export async function menuDelete(
	ctx: DirectToolContext,
	input: { id: number; force?: boolean | undefined; confirm?: boolean | undefined },
) {
	assertToolEnabled(ctx.site, 'stonewright-menu-delete');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-menu-delete',
	});
	try {
		const result = await ctx.client.del<unknown>(`/wp/v2/menus/${input.id}`, {
			query: { force: input.force !== false },
		});
		appendDirectAudit({
			tool: 'stonewright-menu-delete',
			site: ctx.site.alias,
			resource: `menus/${input.id}`,
			status: 'ok',
		});
		return { deleted: true, result };
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-menu-delete',
			site: ctx.site.alias,
			resource: `menus/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}

export async function menuItems(
	ctx: DirectToolContext,
	input: {
		action?: 'list' | 'create' | 'update' | 'delete' | undefined;
		menu_id?: number | undefined;
		id?: number | undefined;
		title?: string | undefined;
		url?: string | undefined;
		type?: string | undefined;
		object?: string | undefined;
		object_id?: number | undefined;
		parent?: number | undefined;
		menu_order?: number | undefined;
		status?: string | undefined;
		per_page?: number | undefined;
		page?: number | undefined;
		force?: boolean | undefined;
		confirm?: boolean | undefined;
	},
) {
	assertToolEnabled(ctx.site, 'stonewright-menu-items');
	const action = input.action ?? 'list';

	if (action === 'list') {
		const perPage = Math.min(Math.max(input.per_page ?? 50, 1), 50);
		const page = Math.max(input.page ?? 1, 1);
		const items = await ctx.client.get<WpMenuItem[]>('/wp/v2/menu-items', {
			query: {
				menus: input.menu_id,
				per_page: perPage,
				page,
				_fields: 'id,title,status,url,type,object,object_id,parent,menu_order,menus',
			},
		});
		const list = Array.isArray(items) ? items : [];
		return {
			items: list.map(compactMenuItem),
			total: list.length,
			page,
			per_page: perPage,
			next_page: list.length === perPage ? page + 1 : undefined,
		};
	}

	if (action === 'create') {
		assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-menu-items' });
		if (!input.menu_id) throw new Error('menu_id is required for create');
		if (!input.title) throw new Error('title is required for create');
		try {
			const item = await ctx.client.post<WpMenuItem>('/wp/v2/menu-items', {
				body: {
					title: input.title,
					url: input.url,
					type: input.type ?? 'custom',
					object: input.object,
					object_id: input.object_id,
					parent: input.parent,
					menu_order: input.menu_order,
					status: input.status ?? 'publish',
					menus: input.menu_id,
				},
			});
			appendDirectAudit({
				tool: 'stonewright-menu-items',
				site: ctx.site.alias,
				resource: `menu-items/${item.id}`,
				status: 'ok',
			});
			return compactMenuItem(item);
		} catch (err) {
			appendDirectAudit({
				tool: 'stonewright-menu-items',
				site: ctx.site.alias,
				resource: 'menu-items',
				status: 'error',
			});
			throw err;
		}
	}

	if (action === 'update') {
		assertWriteAllowed({ mode: ctx.writeMode, destructive: false, tool: 'stonewright-menu-items' });
		if (!input.id) throw new Error('id is required for update');
		const body: Record<string, unknown> = {};
		if (input.title !== undefined) body.title = input.title;
		if (input.url !== undefined) body.url = input.url;
		if (input.type !== undefined) body.type = input.type;
		if (input.object !== undefined) body.object = input.object;
		if (input.object_id !== undefined) body.object_id = input.object_id;
		if (input.parent !== undefined) body.parent = input.parent;
		if (input.menu_order !== undefined) body.menu_order = input.menu_order;
		if (input.status !== undefined) body.status = input.status;
		if (input.menu_id !== undefined) body.menus = input.menu_id;
		try {
			const item = await ctx.client.put<WpMenuItem>(`/wp/v2/menu-items/${input.id}`, { body });
			appendDirectAudit({
				tool: 'stonewright-menu-items',
				site: ctx.site.alias,
				resource: `menu-items/${input.id}`,
				status: 'ok',
			});
			return compactMenuItem(item);
		} catch (err) {
			appendDirectAudit({
				tool: 'stonewright-menu-items',
				site: ctx.site.alias,
				resource: `menu-items/${input.id}`,
				status: 'error',
			});
			throw err;
		}
	}

	if (!input.id) throw new Error('id is required for delete');
	assertWriteAllowed({
		mode: ctx.writeMode,
		destructive: true,
		...(input.confirm !== undefined ? { confirm: input.confirm } : {}),
		tool: 'stonewright-menu-items',
	});
	try {
		const result = await ctx.client.del<unknown>(`/wp/v2/menu-items/${input.id}`, {
			query: { force: input.force !== false },
		});
		appendDirectAudit({
			tool: 'stonewright-menu-items',
			site: ctx.site.alias,
			resource: `menu-items/${input.id}`,
			status: 'ok',
		});
		return { deleted: true, result };
	} catch (err) {
		appendDirectAudit({
			tool: 'stonewright-menu-items',
			site: ctx.site.alias,
			resource: `menu-items/${input.id}`,
			status: 'error',
		});
		throw err;
	}
}
