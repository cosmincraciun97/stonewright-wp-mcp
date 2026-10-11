import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { DirectSafetyBlockedError, markTaskStartSeen, type DirectWriteMode } from '../src/direct/writes.js';
import { pluginDeactivate, pluginDelete } from '../src/direct/tools/plugins.js';
import { userDelete, userUpdate } from '../src/direct/tools/users.js';
import { settingsUpdate } from '../src/direct/tools/settings.js';
import { createMcpServer } from '../src/mcp-server.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'session-admin',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

type Call = { method: string; path: string; query: URLSearchParams; body: unknown };
type Handler = (call: Call) => unknown;

function makeFetch(handler: Handler) {
	const calls: Call[] = [];
	const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const parsed = new URL(String(url));
		const call: Call = {
			method: (init?.method ?? 'GET').toUpperCase(),
			path: parsed.pathname.replace(/^\/wp-json/, ''),
			query: parsed.searchParams,
			body: init?.body && typeof init.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined,
		};
		calls.push(call);
		const out = handler(call);
		if (out instanceof Response) return Promise.resolve(out);
		return Promise.resolve(new Response(JSON.stringify(out), { status: 200, headers: { 'content-type': 'application/json' } }));
	});
	const writes = () => calls.filter((c) => c.method !== 'GET');
	return { fetchImpl, calls, writes };
}

async function blocked(promise: Promise<unknown>): Promise<DirectSafetyBlockedError> {
	const err = await promise.then(() => null, (e: unknown) => e);
	expect(err).toBeInstanceOf(DirectSafetyBlockedError);
	return err as DirectSafetyBlockedError;
}

describe('direct self-protection and settings rules', () => {
	let stateDir: string;
	let savedState: string | undefined;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-selfprot-'));
		savedState = process.env['STONEWRIGHT_STATE_DIR'];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});

	afterEach(() => {
		if (savedState === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = savedState;
		rmSync(stateDir, { recursive: true, force: true });
	});

	function ctx(fetchImpl: typeof fetch, writeMode: DirectWriteMode = 'on') {
		return { client: new WpRestClient(site, { fetchImpl }), site, writeMode, env: process.env, fetchImpl };
	}

	describe('plugin-deactivate and plugin-delete', () => {
		const stonewrightPaths = [
			'stonewright/stonewright',
			'stonewright/stonewright.php',
			'/stonewright/stonewright',
			'Stonewright/Stonewright.php',
			'stonewright\\stonewright.php',
			'stonewright/includes/Core/Plugin.php',
			' stonewright/stonewright ',
			'stonewright',
		];
		const adapterPaths = ['mcp-adapter/mcp-adapter', 'mcp-adapter/mcp-adapter.php', 'MCP-Adapter/mcp-adapter'];

		it.each([...stonewrightPaths, ...adapterPaths])('deactivate refuses %s and sends nothing', async (plugin) => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(pluginDeactivate(ctx(fetchImpl), { plugin, confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(err.message).toMatch(/Stonewright|connection/i);
			expect(calls).toHaveLength(0);
		});

		it.each([...stonewrightPaths, ...adapterPaths])('delete refuses %s and sends nothing', async (plugin) => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(pluginDelete(ctx(fetchImpl), { plugin, confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(calls).toHaveLength(0);
		});

		it('recognises Stonewright by its text domain when the folder was renamed, and writes nothing', async () => {
			const { fetchImpl, calls, writes } = makeFetch(() => ({ plugin: 'sw-renamed/sw', name: 'Stonewright', textdomain: 'stonewright', status: 'active' }));
			const err = await blocked(pluginDeactivate(ctx(fetchImpl), { plugin: 'sw-renamed/sw', confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /wp/v2/plugins/sw-renamed/sw']);
			expect(writes()).toHaveLength(0);
		});

		it('a plugin that only has stonewright in its name is another plugin', async () => {
			const { fetchImpl, calls } = makeFetch((call) =>
				call.method === 'GET'
					? { plugin: 'stonewright-helper/stonewright-helper', name: 'Helper', textdomain: 'helper', status: 'active' }
					: { plugin: 'stonewright-helper/stonewright-helper', name: 'Helper', status: 'inactive' },
			);
			await pluginDeactivate(ctx(fetchImpl), { plugin: 'stonewright-helper/stonewright-helper', confirm: true });
			// self-protection read, the write, and the readback
			expect(calls.map((c) => c.method)).toEqual(['GET', 'PUT', 'GET']);
		});

		it('an ordinary plugin deactivates and deletes as before', async () => {
			const first = makeFetch((call) => (call.method === 'GET' ? { plugin: 'akismet/akismet', name: 'Akismet', textdomain: 'akismet' } : { plugin: 'akismet/akismet', status: 'inactive' }));
			await pluginDeactivate(ctx(first.fetchImpl), { plugin: 'akismet/akismet', confirm: true });
			expect(first.writes().map((c) => c.method)).toEqual(['PUT']);
			const second = makeFetch((call) => (call.method === 'GET' ? { plugin: 'akismet/akismet', name: 'Akismet', textdomain: 'akismet' } : { deleted: true }));
			await pluginDelete(ctx(second.fetchImpl), { plugin: 'akismet/akismet', confirm: true });
			expect(second.writes().map((c) => c.method)).toEqual(['DELETE']);
		});

		it('when the plugin cannot be looked up nothing is written', async () => {
			const { fetchImpl, writes } = makeFetch(() => new Response(JSON.stringify({ code: 'rest_plugin_not_found', message: 'Plugin not found.' }), { status: 404, headers: { 'content-type': 'application/json' } }));
			await expect(pluginDelete(ctx(fetchImpl), { plugin: 'ghost/ghost', confirm: true })).rejects.toThrow(/not found/i);
			expect(writes()).toHaveLength(0);
		});
	});

	describe('user-delete and user-update', () => {
		const ME = { id: 11, roles: ['administrator'] };

		function usersApi(over: { target?: { id: number; roles: string[] }; admins?: number[]; me?: { id: number; roles: string[] } } = {}) {
			const me = over.me ?? ME;
			const target = over.target ?? { id: 22, roles: ['editor'] };
			const admins = over.admins ?? [me.id];
			return makeFetch((call) => {
				if (call.method === 'GET' && call.path === '/wp/v2/users/me') return me;
				if (call.method === 'GET' && call.path === '/wp/v2/users' && call.query.get('roles') === 'administrator') return admins.map((id) => ({ id }));
				if (call.method === 'GET' && call.path === `/wp/v2/users/${target.id}`) return target;
				if (call.method === 'GET' && call.path === `/wp/v2/users/${me.id}`) return me;
				return { id: target.id, roles: ['editor'] };
			});
		}

		it('refuses to delete the user whose Application Password the session uses', async () => {
			const { fetchImpl, writes, calls } = usersApi();
			const err = await blocked(userDelete(ctx(fetchImpl), { id: ME.id, reassign: 22, confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(err.message).toMatch(/signs in|Application Password/i);
			expect(writes()).toHaveLength(0);
			expect(calls[0]).toMatchObject({ method: 'GET', path: '/wp/v2/users/me' });
		});

		it('refuses to delete the last administrator', async () => {
			const { fetchImpl, writes } = usersApi({ target: { id: 22, roles: ['administrator'] }, admins: [22] });
			const err = await blocked(userDelete(ctx(fetchImpl), { id: 22, reassign: 11, confirm: true }));
			expect(err.code).toBe('last_administrator');
			expect(writes()).toHaveLength(0);
		});

		it('deletes another administrator when one remains', async () => {
			const { fetchImpl, writes } = usersApi({ target: { id: 22, roles: ['administrator'] }, admins: [11, 22] });
			await userDelete(ctx(fetchImpl), { id: 22, reassign: 11, confirm: true });
			expect(writes().map((c) => `${c.method} ${c.path}`)).toEqual(['DELETE /wp/v2/users/22']);
		});

		it('deletes an ordinary user without listing administrators', async () => {
			const { fetchImpl, calls, writes } = usersApi();
			await userDelete(ctx(fetchImpl), { id: 22, reassign: 11, confirm: true });
			expect(writes()).toHaveLength(1);
			expect(calls.some((c) => c.query.get('roles') === 'administrator')).toBe(false);
		});

		it('when the session user cannot be read nothing is deleted', async () => {
			const { fetchImpl, writes } = makeFetch(() => new Response(JSON.stringify({ code: 'rest_not_logged_in', message: 'You are not currently logged in.' }), { status: 401, headers: { 'content-type': 'application/json' } }));
			await expect(userDelete(ctx(fetchImpl), { id: 22, reassign: 11, confirm: true })).rejects.toThrow(/logged in/);
			expect(writes()).toHaveLength(0);
		});

		it('refuses a role change that removes administrator from the session user', async () => {
			const { fetchImpl, writes } = usersApi();
			const err = await blocked(userUpdate(ctx(fetchImpl), { id: ME.id, roles: ['editor'], confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(writes()).toHaveLength(0);
		});

		it('refuses a role change that removes the last administrator', async () => {
			const { fetchImpl, writes } = usersApi({ target: { id: 22, roles: ['administrator'] }, admins: [22] });
			const err = await blocked(userUpdate(ctx(fetchImpl), { id: 22, roles: ['subscriber'], confirm: true }));
			expect(err.code).toBe('last_administrator');
			expect(writes()).toHaveLength(0);
		});

		it('clearing the roles of the session user is refused as well', async () => {
			const { fetchImpl, writes } = usersApi();
			const err = await blocked(userUpdate(ctx(fetchImpl), { id: ME.id, roles: [], confirm: true }));
			expect(err.code).toBe('self_protection');
			expect(writes()).toHaveLength(0);
		});

		it('demoting another administrator is allowed while one remains', async () => {
			const { fetchImpl, writes } = usersApi({ target: { id: 22, roles: ['administrator'] }, admins: [11, 22] });
			await userUpdate(ctx(fetchImpl), { id: 22, roles: ['editor'], confirm: true });
			expect(writes().map((c) => `${c.method} ${c.path}`)).toEqual(['POST /wp/v2/users/22']);
		});

		it('keeping administrator, or not touching the roles, needs no extra reads', async () => {
			const keep = usersApi();
			await userUpdate(ctx(keep.fetchImpl), { id: ME.id, roles: ['administrator'], confirm: true });
			// no extra reads before the write; one readback after it
			expect(keep.calls.map((c) => c.method)).toEqual(['POST', 'GET']);
			const rename = usersApi();
			await userUpdate(ctx(rename.fetchImpl), { id: ME.id, name: 'New Name', confirm: true });
			expect(rename.calls.map((c) => c.method)).toEqual(['POST', 'GET']);
		});
	});

	describe('settings-update', () => {
		it.each([
			['siteurl'],
			['home'],
			['url'],
			['email'],
			['admin_email'],
			['wp_user_roles'],
			['active_plugins'],
			['template'],
			['made_up_option'],
		])('refuses the key %s and sends nothing', async (key) => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(settingsUpdate(ctx(fetchImpl), { settings: { title: 'Fine', [key]: 'x' }, confirm: true }));
			expect(err.code).toBe('settings_key_not_allowed');
			expect(err.message).toContain(key);
			expect(calls).toHaveLength(0);
		});

		it.each([
			['administrator'],
			['shop_manager'],
			['custom_role'],
			[''],
		])('refuses default_role %j and sends nothing', async (role) => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(settingsUpdate(ctx(fetchImpl), { settings: { default_role: role }, confirm: true }));
			expect(err.code).toBe('settings_value_not_allowed');
			expect(err.message).toMatch(/default_role/);
			expect(calls).toHaveLength(0);
		});

		it('refuses a default_role that is not a string', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			await blocked(settingsUpdate(ctx(fetchImpl), { settings: { default_role: ['administrator'] }, confirm: true }));
			expect(calls).toHaveLength(0);
		});

		it.each([['subscriber'], ['contributor'], ['author'], ['editor']])('accepts default_role %s', async (role) => {
			const { fetchImpl, calls } = makeFetch(() => ({ default_role: role }));
			await settingsUpdate(ctx(fetchImpl), { settings: { default_role: role }, confirm: true });
			expect(calls.map((c) => c.method)).toEqual(['POST', 'GET']);
		});

		it('refuses object and array values for an allowed key', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(settingsUpdate(ctx(fetchImpl), { settings: { title: { raw: 'x' } }, confirm: true }));
			expect(err.code).toBe('settings_value_not_allowed');
			expect(calls).toHaveLength(0);
		});

		it('refuses the whole call when one key is not allowed, so nothing is written in part', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			await blocked(settingsUpdate(ctx(fetchImpl), { settings: { title: 'A', posts_per_page: 5, email: 'a@example.com' }, confirm: true }));
			expect(calls).toHaveLength(0);
		});

		it('passes the allowed keys, under both their REST names and their option names', async () => {
			const settings = {
				title: 'A', description: 'B', blogname: 'A', blogdescription: 'B', timezone: 'UTC', timezone_string: 'UTC',
				date_format: 'Y-m-d', time_format: 'H:i', start_of_week: 1, posts_per_page: 12, default_comment_status: 'closed',
				default_ping_status: 'closed', users_can_register: false, show_on_front: 'page', page_on_front: 4, page_for_posts: 5, site_icon: 0,
			};
			const { fetchImpl, calls } = makeFetch(() => settings);
			await settingsUpdate(ctx(fetchImpl), { settings, confirm: true });
			// the write and its readback
			expect(calls).toHaveLength(2);
			// Core REST knows title, description and timezone: the option names are sent under those.
			const sent: Record<string, unknown> = { ...settings };
			for (const option of ['blogname', 'blogdescription', 'timezone_string']) delete sent[option];
			expect(calls[0]?.body).toEqual(sent);
		});

		it('still needs confirm in confirm mode', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const err = await blocked(settingsUpdate(ctx(fetchImpl, 'confirm'), { settings: { title: 'A' } }));
			expect(err.code).toBe('confirmation_required');
			expect(calls).toHaveLength(0);
		});
	});
});

describe('the same rules through the registered Direct tools', () => {
	let stateDir: string;
	let savedState: string | undefined;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-selfprot-reg-'));
		savedState = process.env['STONEWRIGHT_STATE_DIR'];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});
	afterEach(() => {
		if (savedState === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = savedState;
		rmSync(stateDir, { recursive: true, force: true });
	});

	type Handler = (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;

	async function directSession() {
		const { fetchImpl, calls, writes } = makeFetch(() => ({}));
		const env: NodeJS.ProcessEnv = {
			STONEWRIGHT_MODE: 'direct',
			STONEWRIGHT_STATE_DIR: stateDir,
			STONEWRIGHT_SITES_FILE: join(stateDir, 'none.json'),
			STONEWRIGHT_WP_URL: 'http://example.test',
			STONEWRIGHT_WP_USERNAME: 'agent',
			STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
		};
		const server = await createMcpServer({ env, fetchImpl: fetchImpl as never });
		const baseline = calls.length;
		const tools = (server as unknown as { _registeredTools: Record<string, { handler: Handler }> })._registeredTools;
		const call = (name: string, input: unknown) => (tools[name] as { handler: Handler }).handler(input);
		return { call, calls, writes, baseline };
	}

	function blockedRows(): Array<Record<string, unknown>> {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>).filter((row) => row['status'] === 'blocked');
	}

	it('plugin-delete of Stonewright, a settings write outside the allowlist, and a comment with a script all come back refused with a blocked audit row and no write', async () => {
		const { call, writes } = await directSession();
		markTaskStartSeen({ alias: 'default', url: 'http://example.test' });

		const plugin = await call('stonewright-plugin-delete', { plugin: 'stonewright/stonewright', confirm: true });
		expect(plugin.isError).toBe(true);
		expect(JSON.parse(plugin.content[0]?.text ?? '{}')).toMatchObject({ ok: false, error: 'self_protection', blocked: true });

		const settings = await call('stonewright-settings-update', { settings: { email: 'a@example.com' }, confirm: true });
		expect(JSON.parse(settings.content[0]?.text ?? '{}')).toMatchObject({ ok: false, error: 'settings_key_not_allowed', blocked: true });

		const comment = await call('stonewright-comment-create', { post: 1, content: '<script>alert(1)</script>', confirm: true });
		expect(JSON.parse(comment.content[0]?.text ?? '{}')).toMatchObject({ error: 'custom_code_approval_required', approval_required: true, written: false });

		expect(writes()).toHaveLength(0);
		const codes = blockedRows().map((row) => row['code']);
		expect(codes).toEqual(expect.arrayContaining(['self_protection', 'settings_key_not_allowed', 'custom_code_approval_required']));
	});
});
