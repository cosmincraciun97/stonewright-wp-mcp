import { describe, expect, it, vi } from 'vitest';
import { WpRestError } from '../src/direct/wp-rest-client.js';
import { settingsGet } from '../src/direct/tools/settings.js';
import { userList } from '../src/direct/tools/users.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

type Query = Record<string, unknown> | undefined;

function ctxWith(get: (path: string, opts?: { query?: Query }) => Promise<unknown>) {
	const queries: Query[] = [];
	const client = {
		get: vi.fn((path: string, opts?: { query?: Query }) => {
			queries.push(opts?.query);
			return get(path, opts);
		}),
	};
	return { client: client as never, site, writeMode: 'on' as const, env: process.env, queries, get: client.get };
}

const forbiddenContext = (): WpRestError =>
	new WpRestError({ code: 'rest_forbidden_context', status: 403, message: 'Sorry, you are not allowed to edit users.', hint: 'The account cannot list users.' });

describe('stonewright-user-list roles', () => {
	it('reads the list with context=edit so that each user carries its roles', async () => {
		const ctx = ctxWith(() => Promise.resolve([{ id: 1, name: 'Ann', slug: 'ann', roles: ['administrator'] }, { id: 2, name: 'Bo', slug: 'bo', roles: ['editor'] }]));

		const out = await userList(ctx as never, {});

		const query = ctx.queries[0] ?? {};
		expect(query['context']).toBe('edit');
		expect(String(query['_fields'])).toContain('roles');
		expect(out.items.map((item) => (item as { roles?: string[] }).roles)).toEqual([['administrator'], ['editor']]);
		expect(out.roles_available).toBeUndefined();
	});

	it('falls back to the public view when the account may not use context=edit, and says roles are not available', async () => {
		const ctx = ctxWith((_path, opts) => {
			if (opts?.query?.['context'] === 'edit') return Promise.reject(forbiddenContext());
			return Promise.resolve([{ id: 1, name: 'Ann', slug: 'ann' }]);
		});

		const out = await userList(ctx as never, {});

		expect(ctx.queries).toHaveLength(2);
		expect(ctx.queries[1]?.['context']).toBeUndefined();
		expect(out.roles_available).toBe(false);
		expect(String(out.roles_note)).toMatch(/list_users|administrator/i);
		expect(out.items).toHaveLength(1);
		expect(out.items[0]).not.toHaveProperty('roles');
	});

	it('does not hide other failures behind the fallback', async () => {
		const ctx = ctxWith(() => Promise.reject(new WpRestError({ code: 'rest_no_route', status: 404, message: 'No route', hint: '' })));

		await expect(userList(ctx as never, {})).rejects.toBeInstanceOf(WpRestError);
		expect(ctx.get).toHaveBeenCalledTimes(1);
	});
});

describe('stonewright-settings-get settings core REST does not expose', () => {
	const coreSettings = { title: 'Site', description: 'Tag', timezone: 'UTC', posts_per_page: 10 };

	it('names default_role and users_can_register as not_available_in_direct instead of leaving them out', async () => {
		const out = await settingsGet(ctxWith(() => Promise.resolve(coreSettings)) as never, {});

		expect(out.settings).toMatchObject({ title: 'Site' });
		expect(out.settings).not.toHaveProperty('default_role');
		expect(out.not_available_in_direct).toEqual(['default_role', 'users_can_register']);
		expect(String(out.not_available_note)).toMatch(/core REST/i);
		expect(String(out.not_available_note)).toMatch(/Plugin mode|wp-admin/);
	});

	it('answers a request for those keys with not_available_in_direct, not unknown_keys', async () => {
		const out = await settingsGet(ctxWith(() => Promise.resolve(coreSettings)) as never, { keys: ['title', 'default_role', 'nonsense'] });

		expect(out.settings).toEqual({ title: 'Site' });
		expect(out.not_available_in_direct).toEqual(['default_role']);
		expect(out.unknown_keys).toEqual(['nonsense']);
	});

	it('returns a value a site does expose, with no not-available note for it', async () => {
		const out = await settingsGet(ctxWith(() => Promise.resolve({ ...coreSettings, default_role: 'subscriber' })) as never, { keys: ['default_role', 'users_can_register'] });

		expect(out.settings).toEqual({ default_role: 'subscriber' });
		expect(out.not_available_in_direct).toEqual(['users_can_register']);
	});

	it('adds nothing when a narrowed request does not touch them', async () => {
		const out = await settingsGet(ctxWith(() => Promise.resolve(coreSettings)) as never, { keys: ['title'] });

		expect(out).toEqual({ settings: { title: 'Site' } });
	});
});
