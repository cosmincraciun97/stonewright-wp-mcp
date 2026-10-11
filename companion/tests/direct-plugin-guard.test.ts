import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDirectTools } from '../src/direct/registry.js';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { resetTaskStartSeenForTests } from '../src/direct/writes.js';
import { elementorDataUpdate } from '../src/direct/tools/elementor-direct.js';
import {
	PLUGIN_PRESENCE_TTL_MS,
	forgetPluginPresence,
	setPluginPresenceResolverForTests,
} from '../src/direct/plugin-guard.js';

type ToolHandler = (input: Record<string, unknown>) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;

const BLOCKED = 'stonewright_direct_write_blocked_plugin_present';
const CHECK_FAILED = 'stonewright_direct_plugin_check_failed';
const PASSWORD = 'fixture-app-pass-0001';

const site: ResolvedSite = {
	alias: 'plugin-check',
	url: 'https://example.test',
	restBase: 'https://example.test/wp-json',
	username: 'admin',
	appPassword: PASSWORD,
	disabledTools: [],
};

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

describe('direct write-time plugin guard', () => {
	let dir: string;
	let calls: Array<{ method: string; path: string; host: string }>;
	let world: { plugin: boolean; indexStatus: number };

	beforeEach(() => {
		// The suite-wide setup file stubs the probe; these tests use the real one.
		setPluginPresenceResolverForTests(null);
		forgetPluginPresence();
		resetTaskStartSeenForTests();
		dir = mkdtempSync(join(tmpdir(), 'sw-plugin-guard-'));
		calls = [];
		world = { plugin: true, indexStatus: 200 };
		vi.stubEnv('STONEWRIGHT_STATE_DIR', join(dir, 'state'));
		vi.stubEnv('STONEWRIGHT_DIRECT_WRITES', 'on');
		vi.stubEnv('STONEWRIGHT_DIRECT_REQUIRE_TASK_START', 'off');
		vi.stubEnv('STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE', undefined);
	});

	afterEach(() => {
		vi.useRealTimers();
		vi.unstubAllEnvs();
		setPluginPresenceResolverForTests(null);
		forgetPluginPresence();
		resetTaskStartSeenForTests();
		rmSync(dir, { recursive: true, force: true });
	});

	function fakeFetch(): typeof fetch {
		return vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = new URL(String(input));
			const method = (init?.method ?? 'GET').toUpperCase();
			calls.push({ method, path: url.pathname.replace(/^\/wp-json/, '') || '/', host: url.host });
			if (url.pathname === '/wp-json/' || url.pathname === '/wp-json') {
				if (world.indexStatus !== 200) return Promise.resolve(json({ code: 'internal_error' }, world.indexStatus));
				return Promise.resolve(
					json({ namespaces: world.plugin ? ['wp/v2', 'stonewright/v1'] : ['wp/v2'], routes: {} }),
				);
			}
			if (url.pathname.startsWith('/wp-json/stonewright/v1/direct/')) {
				return Promise.resolve(json({ ok: true, mode: 'plugin', context_token: 'ctx', expires_at: '2099-01-01T00:00:00Z' }));
			}
			if (method === 'GET') {
				return Promise.resolve(
					json({ id: 7, title: { raw: 'Old', rendered: 'Old' }, content: { raw: '<p>x</p>' }, slug: 'a', status: 'draft', type: 'page', modified: '2026-10-01T00:00:00', link: 'https://example.test/a', meta: { _elementor_data: '[]' } }),
				);
			}
			return Promise.resolve(
				json({ id: 7, title: { raw: 'New', rendered: 'New' }, slug: 'a', status: 'draft', type: 'page', modified: '2026-10-01T00:00:00', link: 'https://example.test/a', name: 'Menu', source_url: 'https://example.test/a.png', mime_type: 'image/png', status_code: 200 }),
			);
		}) as unknown as typeof fetch;
	}

	function writeRequests(): Array<{ method: string; path: string }> {
		return calls.filter((c) => c.method !== 'GET');
	}

	function indexRequests(): number {
		return calls.filter((c) => c.method === 'GET' && c.path === '/').length;
	}

	function sitesFile(): string {
		const file = join(dir, 'sites.json');
		writeFileSync(
			file,
			JSON.stringify({ default: 'plugin-check', sites: { 'plugin-check': { url: site.url, username: site.username, appPassword: PASSWORD } } }),
		);
		return file;
	}

	function tools(extraEnv: Record<string, string> = {}): Record<string, ToolHandler> {
		const env: NodeJS.ProcessEnv = {
			STONEWRIGHT_SITES_FILE: sitesFile(),
			STONEWRIGHT_STATE_DIR: join(dir, 'state'),
			STONEWRIGHT_DIRECT_WRITES: 'on',
			STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			...extraEnv,
		};
		for (const [key, value] of Object.entries(extraEnv)) vi.stubEnv(key, value);
		const server = new McpServer({ name: 'test', version: '0.0.0' });
		registerDirectTools(server, { env, fetchImpl: fakeFetch(), toolProfile: 'full' });
		const registered = (server as unknown as { _registeredTools: Record<string, { handler: ToolHandler }> })._registeredTools;
		return Object.fromEntries(Object.entries(registered).map(([name, tool]) => [name, tool.handler]));
	}

	function parse(result: { content: Array<{ text: string }> }): Record<string, unknown> {
		return JSON.parse(result.content[0].text) as Record<string, unknown>;
	}

	function auditRows(): Array<Record<string, unknown>> {
		const path = join(dir, 'state', 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
	}

	describe('WpRestClient', () => {
		it('refuses every non-GET method when the plugin namespace is present, sending no write', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			for (const call of [
				() => client.post('/wp/v2/pages', { body: { title: 'x' } }),
				() => client.put('/wp/v2/pages/7', { body: { title: 'x' } }),
				() => client.del('/wp/v2/pages/7'),
				() => client.request('PATCH', '/wp/v2/pages/7', { body: { title: 'x' } }),
			]) {
				await expect(call()).rejects.toMatchObject({ code: BLOCKED });
			}
			expect(writeRequests()).toEqual([]);
		});

		it('names Plugin mode in the refusal', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await expect(client.post('/wp/v2/pages', { body: {} })).rejects.toThrow(/Plugin mode/);
		});

		it('lets reads through on a plugin site', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await expect(client.get('/wp/v2/pages/7')).resolves.toMatchObject({ id: 7 });
			expect(indexRequests()).toBe(0);
		});

		it('sends the write when the plugin namespace is absent', async () => {
			world.plugin = false;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await client.post('/wp/v2/pages', { body: { title: 'x' } });
			expect(writeRequests()).toEqual([{ method: 'POST', path: '/wp/v2/pages', host: 'example.test' }]);
		});

		it('allows the typed plugin bridge without a probe', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await client.post('/stonewright/v1/direct/task-start', { body: { task: 'x' } });
			await client.post('/stonewright/v1/direct/learning-record', { body: {} });
			expect(writeRequests().map((c) => c.path)).toEqual([
				'/stonewright/v1/direct/task-start',
				'/stonewright/v1/direct/learning-record',
			]);
			expect(indexRequests()).toBe(0);
		});

		it('does not allow other stonewright routes or path tricks', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await expect(client.post('/stonewright/v1/abilities/run', { body: {} })).rejects.toMatchObject({ code: BLOCKED });
			await expect(client.post('/stonewright/v1/direct/../abilities/run', { body: {} })).rejects.toMatchObject({ code: BLOCKED });
			expect(writeRequests()).toEqual([]);
		});

		it('adds no request per write once the answer is cached', async () => {
			world.plugin = false;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await client.post('/wp/v2/pages', { body: {} });
			await client.put('/wp/v2/pages/7', { body: {} });
			await client.del('/wp/v2/pages/7');
			expect(indexRequests()).toBe(1);
			expect(writeRequests()).toHaveLength(3);
		});

		it('shares one probe between concurrent writes', async () => {
			world.plugin = false;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await Promise.all([client.post('/wp/v2/pages', { body: {} }), client.post('/wp/v2/pages', { body: {} })]);
			expect(indexRequests()).toBe(1);
		});

		it('re-checks after the TTL and notices a plugin that appeared mid-session', async () => {
			vi.useFakeTimers({ toFake: ['Date'] });
			vi.setSystemTime(new Date('2026-10-11T10:00:00Z'));
			world.plugin = false;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await client.post('/wp/v2/pages', { body: {} });

			world.plugin = true;
			vi.setSystemTime(Date.now() + PLUGIN_PRESENCE_TTL_MS - 1_000);
			await client.post('/wp/v2/pages', { body: {} });
			expect(indexRequests()).toBe(1);

			vi.setSystemTime(Date.now() + 2_000);
			await expect(client.post('/wp/v2/pages', { body: {} })).rejects.toMatchObject({ code: BLOCKED });
			expect(indexRequests()).toBe(2);
		});

		it('re-checks right away after a reconnect clears the cache', async () => {
			world.plugin = false;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await client.post('/wp/v2/pages', { body: {} });
			world.plugin = true;
			forgetPluginPresence(site.url);
			await expect(client.post('/wp/v2/pages', { body: {} })).rejects.toMatchObject({ code: BLOCKED });
			expect(indexRequests()).toBe(2);
		});

		it('keeps one cache entry per site', async () => {
			world.plugin = false;
			const other: ResolvedSite = { ...site, alias: 'other', url: 'https://other.example.test', restBase: 'https://other.example.test/wp-json' };
			const a = new WpRestClient(site, { fetchImpl: fakeFetch() });
			const b = new WpRestClient(other, { fetchImpl: fakeFetch() });
			await a.post('/wp/v2/pages', { body: {} });
			await b.post('/wp/v2/pages', { body: {} });
			expect(indexRequests()).toBe(2);
		});

		it('retries the probe once, then refuses with a check failure and does not cache it', async () => {
			world.indexStatus = 500;
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			await expect(client.post('/wp/v2/pages', { body: {} })).rejects.toMatchObject({ code: CHECK_FAILED });
			expect(indexRequests()).toBe(2);
			expect(writeRequests()).toEqual([]);

			world.indexStatus = 200;
			world.plugin = false;
			await client.post('/wp/v2/pages', { body: {} });
			expect(writeRequests()).toHaveLength(1);
		});

		it('reads the namespace list even when the index body is cut off', async () => {
			const big = `{"name":"x","namespaces":["wp/v2","stonewright/v1"],"routes":{"/a":${'"x"'.repeat(1)}`;
			const fetchImpl = vi.fn(() => Promise.resolve(new Response(big, { status: 200, headers: { 'content-type': 'application/json' } }))) as unknown as typeof fetch;
			const client = new WpRestClient(site, { fetchImpl });
			await expect(client.post('/wp/v2/pages', { body: {} })).rejects.toMatchObject({ code: BLOCKED });
		});
	});

	describe('WP-CLI write path', () => {
		it('refuses the Elementor WP-CLI write on a plugin site and runs no command', async () => {
			const client = new WpRestClient(site, { fetchImpl: fakeFetch() });
			const cli = vi.fn(() => Promise.resolve({ ok: true, available: true, stdout: '[]', stderr: '' }));
			const rest = {
				target: client.target,
				get: client.get.bind(client),
				post: client.post.bind(client),
				assertWritable: () => client.assertWritable(),
			};

			await expect(
				elementorDataUpdate({ STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off', STONEWRIGHT_DIRECT_WRITES: 'on' }, { post_id: 7, data: [] }, cli as never, rest as never),
			).rejects.toMatchObject({ code: BLOCKED });
			expect(cli).not.toHaveBeenCalled();
			expect(writeRequests()).toEqual([]);
		});
	});

	describe('typed tools on a plugin site', () => {
		const writes: Array<[string, Record<string, unknown>]> = [
			['stonewright-content-create-page', { title: 'x' }],
			['stonewright-content-update', { id: 7, type: 'page', title: 'x' }],
			['stonewright-template-update', { id: 'theme//home', content: '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' }],
			['stonewright-global-styles-update', { id: 3, styles: { color: { background: '#fff' } } }],
			['stonewright-plugin-activate', { plugin: 'hello', confirm: true }],
			['stonewright-user-create', { username: 'newuser', email: 'new@example.test', password: 'p'.repeat(14), confirm: true }],
			['stonewright-media-upload', { url: 'https://example.com/a.png' }],
			['stonewright-menu-create', { name: 'Main' }],
			['stonewright-settings-update', { settings: { title: 'x' }, confirm: true }],
			['stonewright-comment-create', { post: 7, content: 'hi', confirm: true }],
			['stonewright-theme-activate', { stylesheet: 'twentytwentyfive', confirm: true }],
			['stonewright-elementor-data-update', { post_id: 7, data: [], confirm: true }],
		];

		it.each(writes)('%s is refused and sends no write', async (name, input) => {
			const handlers = tools();
			const result = await handlers[name](input);

			expect(result.isError, result.content[0].text).toBe(true);
			expect(parse(result)).toMatchObject({ ok: false, error: BLOCKED, blocked: true });
			expect(writeRequests()).toEqual([]);
			expect(calls.some((c) => c.host === 'example.com')).toBe(false);
		});

		it('records the refusal in the audit file as blocked, not as an error', async () => {
			const handlers = tools();
			await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			const rows = auditRows().filter((row) => row['code'] === BLOCKED);
			expect(rows.length).toBeGreaterThan(0);
			expect(rows.every((row) => row['status'] === 'blocked')).toBe(true);
			expect(auditRows().some((row) => row['status'] === 'error')).toBe(false);
		});

		it('still serves reads', async () => {
			const handlers = tools();
			const result = await handlers['stonewright-content-get']({ id: 7, type: 'page' });
			expect(result.isError).toBeUndefined();
			expect(writeRequests()).toEqual([]);
		});

		it('does not block local skill deletion on a plugin site', async () => {
			const handlers = tools();
			const result = await handlers['stonewright-skill-delete']({ slug: 'no-such-skill', confirm: true });
			expect(JSON.parse(result.content[0].text)).not.toMatchObject({ error: BLOCKED });
		});

		it('does not repeat a failing index request on every read', async () => {
			world.indexStatus = 500;
			const handlers = tools();
			await handlers['stonewright-content-get']({ id: 7, type: 'page' });
			await handlers['stonewright-content-get']({ id: 7, type: 'page' });
			await handlers['stonewright-content-get']({ id: 7, type: 'page' });
			// One warm-up (two attempts), then the pause.
			expect(indexRequests()).toBe(2);
		});

		it('sends the write on a site without the plugin', async () => {
			world.plugin = false;
			const handlers = tools();
			const result = await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			expect(result.isError, result.content[0].text).toBeUndefined();
			expect(writeRequests().map((c) => c.method)).toEqual(['PUT']);
			expect(indexRequests()).toBe(1);
		});
	});

	describe('emergency override', () => {
		const call = { id: 7, type: 'page', title: 'x' };

		it('needs both the environment switch and confirm:true', async () => {
			const withoutConfirm = await tools({ STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE: '1' })['stonewright-content-update'](call);
			expect(parse(withoutConfirm)).toMatchObject({ error: BLOCKED });

			forgetPluginPresence();
			const withoutEnv = await tools()['stonewright-content-update']({ ...call, confirm: true });
			expect(parse(withoutEnv)).toMatchObject({ error: BLOCKED });
			expect(writeRequests()).toEqual([]);
		});

		it('lets the write through, warns in the result and writes an audit row per use', async () => {
			const handlers = tools({ STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE: '1' });
			const result = await handlers['stonewright-content-update']({ ...call, confirm: true });

			expect(result.isError, result.content[0].text).toBeUndefined();
			expect(writeRequests().map((c) => c.method)).toEqual(['PUT']);
			const body = parse(result);
			expect(JSON.stringify(body['warnings'])).toMatch(/Stonewright plugin/);
			const overrides = auditRows().filter((row) => row['code'] === 'direct_write_plugin_override');
			expect(overrides).toHaveLength(1);
			expect(overrides[0]).toMatchObject({ tool: 'stonewright-content-update', status: 'ok', category: 'SAFETY' });

			await handlers['stonewright-content-update']({ ...call, confirm: true });
			expect(auditRows().filter((row) => row['code'] === 'direct_write_plugin_override')).toHaveLength(2);
		});

		it('also covers a failed plugin check', async () => {
			world.indexStatus = 500;
			const handlers = tools({ STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE: '1' });
			const result = await handlers['stonewright-content-update']({ ...call, confirm: true });
			expect(result.isError, result.content[0].text).toBeUndefined();
			expect(JSON.stringify(parse(result)['warnings'])).toMatch(/could not confirm/i);
		});
	});
});
