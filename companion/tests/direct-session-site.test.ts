import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { registerDirectTools } from '../src/direct/registry.js';
import { createMcpServer } from '../src/mcp-server.js';
import { loadSitesConfig, resolveSessionSite, sessionSiteAlias } from '../src/direct/sites-config.js';
import { resolveSelfImproveScope } from '../src/direct/tools/self-improve.js';
import { hasTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';

type ToolHandler = (input: Record<string, unknown>) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;

const PASSWORD = 'fixture-app-pass-0001';
const HOST_A = 'a.example.test';
const HOST_B = 'b.example.test';

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

describe('direct session site binding', () => {
	let dir: string;
	let sitesFile: string;
	let requests: Array<{ method: string; url: string }>;

	beforeEach(() => {
		resetTaskStartSeenForTests();
		dir = mkdtempSync(join(tmpdir(), 'sw-session-site-'));
		sitesFile = join(dir, 'sites.json');
		// Registry default is A (a plugin site); the session is bound to B.
		writeFileSync(
			sitesFile,
			JSON.stringify({
				default: 'plugin-site',
				sites: {
					'plugin-site': { url: `https://${HOST_A}`, username: 'admin', appPassword: PASSWORD },
					'direct-site': { url: `https://${HOST_B}`, username: 'admin', appPassword: PASSWORD },
				},
			}),
		);
		requests = [];
	});

	afterEach(() => {
		resetTaskStartSeenForTests();
		vi.unstubAllEnvs();
		rmSync(dir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	function fakeFetch(): typeof fetch {
		return vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = new URL(String(input));
			const method = (init?.method ?? 'GET').toUpperCase();
			requests.push({ method, url: url.toString() });
			if (url.pathname === '/wp-json/' || url.pathname === '/wp-json') {
				return Promise.resolve(json({ namespaces: ['wp/v2'] }));
			}
			if (url.pathname.endsWith('/stonewright/v1/direct/task-start')) {
				return Promise.resolve(json({ code: 'rest_no_route', message: 'No route' }, 404));
			}
			if (url.pathname.startsWith('/wp-json/wp/v2/pages/7')) {
				return Promise.resolve(
					json({ id: 7, title: { raw: 'Old', rendered: 'Old' }, content: { raw: '<p>x</p>' }, slug: 'a', status: 'draft', type: 'page', modified: '2026-10-01T00:00:00', link: `https://${url.host}/a` }),
				);
			}
			return Promise.resolve(json({}));
		}) as unknown as typeof fetch;
	}

	function env(extra: Record<string, string> = {}): NodeJS.ProcessEnv {
		return {
			STONEWRIGHT_SITES_FILE: sitesFile,
			STONEWRIGHT_STATE_DIR: join(dir, 'state'),
			STONEWRIGHT_DIRECT_WRITES: 'on',
			...extra,
		};
	}

	function tools(runtimeEnv: NodeJS.ProcessEnv): Record<string, ToolHandler> {
		// Several tool modules read the write gate from the process env.
		for (const key of ['STONEWRIGHT_DIRECT_REQUIRE_TASK_START', 'STONEWRIGHT_DIRECT_WRITES', 'STONEWRIGHT_STATE_DIR']) {
			vi.stubEnv(key, runtimeEnv[key]);
		}
		const server = new McpServer({ name: 'test', version: '0.0.0' });
		registerDirectTools(server, { env: runtimeEnv, fetchImpl: fakeFetch(), toolProfile: 'full' });
		const registered = (server as unknown as { _registeredTools: Record<string, { handler: ToolHandler }> })._registeredTools;
		return Object.fromEntries(Object.entries(registered).map(([name, tool]) => [name, tool.handler]));
	}

	function parse(result: { content: Array<{ text: string }> }): Record<string, unknown> {
		return JSON.parse(result.content[0].text) as Record<string, unknown>;
	}

	function auditRows(runtimeEnv: NodeJS.ProcessEnv): Array<Record<string, unknown>> {
		const path = join(runtimeEnv['STONEWRIGHT_STATE_DIR']!, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
	}

	describe('session alias resolution', () => {
		it('reads the alias from STONEWRIGHT_SITE_ALIAS and ignores blanks', () => {
			expect(sessionSiteAlias({ STONEWRIGHT_SITE_ALIAS: ' direct-site ' })).toBe('direct-site');
			expect(sessionSiteAlias({ STONEWRIGHT_SITE_ALIAS: '   ' })).toBeNull();
			expect(sessionSiteAlias({})).toBeNull();
		});

		it('resolves the session site instead of the registry default', () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' });
			const config = loadSitesConfig({ env: runtimeEnv });
			expect(config.default).toBe('plugin-site');
			expect(resolveSessionSite(config, runtimeEnv).url).toBe(`https://${HOST_B}`);
		});

		it('keeps the registry default when the session has no alias', () => {
			const runtimeEnv = env();
			const config = loadSitesConfig({ env: runtimeEnv });
			expect(resolveSessionSite(config, runtimeEnv).alias).toBe('plugin-site');
			expect(resolveSessionSite(config, runtimeEnv, 'direct-site').alias).toBe('direct-site');
		});

		it('refuses a site argument that names another alias', () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' });
			const config = loadSitesConfig({ env: runtimeEnv });
			expect(() => resolveSessionSite(config, runtimeEnv, 'plugin-site')).toThrow(/site_not_in_session/);
			expect(resolveSessionSite(config, runtimeEnv, 'DIRECT-SITE').alias).toBe('direct-site');
		});
	});

	describe('probe: session alias B, registry default A', () => {
		it('content-update hits B and never A', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off' });
			const handlers = tools(runtimeEnv);

			const result = await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });

			expect(result.isError, result.content[0].text).toBeUndefined();
			const contentRequests = requests.filter((r) => r.url.includes('/wp/v2/pages/7'));
			expect(contentRequests.map((r) => `${r.method} ${new URL(r.url).host}`)).toEqual([
				`GET ${HOST_B}`,
				`PUT ${HOST_B}`,
				// the readback after the write goes to the session site too
				`GET ${HOST_B}`,
			]);
			expect(requests.some((r) => r.url.includes(HOST_A))).toBe(false);
		});

		it('refuses a site argument that names another alias and sends nothing', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off' });
			const handlers = tools(runtimeEnv);

			const result = await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x', site: 'plugin-site' });

			expect(result.isError).toBe(true);
			expect(parse(result)).toMatchObject({ ok: false, error: 'site_not_in_session', blocked: true });
			expect(requests).toEqual([]);
			expect(auditRows(runtimeEnv).map((row) => [row['code'], row['status']])).toContainEqual(['site_not_in_session', 'blocked']);
		});

		it('refuses a mismatched site on reads too', async () => {
			const handlers = tools(env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' }));
			const result = await handlers['stonewright-content-list']({ site: 'plugin-site' });
			expect(result.isError).toBe(true);
			expect(parse(result)['error']).toBe('site_not_in_session');
			expect(requests).toEqual([]);
		});

		it('accepts the session alias passed explicitly', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off' });
			const handlers = tools(runtimeEnv);
			const result = await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x', site: 'direct-site' });
			expect(result.isError, result.content[0].text).toBeUndefined();
			expect(requests.some((r) => r.url.includes(HOST_A))).toBe(false);
		});
	});

	describe('session scope for memory, incidents and the task-start latch', () => {
		it('arms the task-start latch for B only, and a write then succeeds without a site argument', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' });
			const handlers = tools(runtimeEnv);

			const start = parse(await handlers['stonewright-task-start']({ task: 'edit a page' }));

			expect(start['site']).toBe('direct-site');
			expect(hasTaskStartSeen({ alias: 'direct-site', url: `https://${HOST_B}` })).toBe(true);
			expect(hasTaskStartSeen({ alias: 'plugin-site', url: `https://${HOST_A}` })).toBe(false);
			const write = await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			expect(write.isError).toBeUndefined();
		});

		it('task-start refuses a site argument that names another alias', async () => {
			const handlers = tools(env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' }));
			const result = await handlers['stonewright-task-start']({ task: 'edit a page', site: 'plugin-site' });
			expect(result.isError).toBe(true);
			expect(parse(result)['error']).toBe('site_not_in_session');
			expect(hasTaskStartSeen({ alias: 'plugin-site', url: `https://${HOST_A}` })).toBe(false);
		});

		it('scopes memory and skills to the session alias', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' });
			const handlers = tools(runtimeEnv);

			const list = parse(await handlers['stonewright-memory-list']({}));
			expect(list['scope']).toBe('direct-site');

			const scope = resolveSelfImproveScope({ env: runtimeEnv }, undefined);
			expect(scope).toMatchObject({ scope: 'direct-site', siteAlias: 'direct-site' });
			expect(() => resolveSelfImproveScope({ env: runtimeEnv }, 'plugin-site')).toThrow(/site_not_in_session/);
		});

		it('binds incident repair to the session site fingerprint', async () => {
			const runtimeEnv = env({ STONEWRIGHT_SITE_ALIAS: 'direct-site' });
			const handlers = tools(runtimeEnv);
			// No incident exists. The lookup must resolve the session site, not fail on A.
			const result = await handlers['stonewright-incident-repair-record']({
				incident_id: 'a'.repeat(64),
				resolution_event_id: '00000000-0000-4000-8000-000000000000',
				repair_recipe: 'Re-save the page after clearing the stale cache entry.',
			});
			expect(result.isError).toBe(true);
			expect(JSON.stringify(parse(result))).toMatch(/incident_not_found/);
		});
	});

	describe('through the companion server (STONEWRIGHT_SITE_ALIAS in the client entry)', () => {
		async function server(extra: Record<string, string> = {}) {
			return createMcpServer({
				env: {
					STONEWRIGHT_SITES_FILE: sitesFile,
					STONEWRIGHT_SITE_ALIAS: 'direct-site',
					STONEWRIGHT_MODE: 'direct',
					STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
					STONEWRIGHT_STATE_DIR: join(dir, 'state'),
					STONEWRIGHT_DIRECT_WRITES: 'on',
					...extra,
				},
				fetchImpl: fakeFetch(),
			});
		}

		function handlers(srv: unknown): Record<string, ToolHandler & { structured?: never }> {
			const registered = (srv as { _registeredTools: Record<string, { handler: ToolHandler }> })._registeredTools;
			return Object.fromEntries(Object.entries(registered).map(([name, tool]) => [name, tool.handler])) as never;
		}

		it('writes to the session site after task-start and never contacts the registry default', async () => {
			vi.stubEnv('STONEWRIGHT_STATE_DIR', join(dir, 'state'));
			vi.stubEnv('STONEWRIGHT_DIRECT_WRITES', 'on');
			const tools = handlers(await server());
			requests = [];

			const start = await tools['stonewright-task-start']({ task: 'edit a page' });
			expect((start as { structuredContent?: { site?: string } }).structuredContent?.site).toBe('direct-site');
			const write = await tools['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });

			expect(write.isError, write.content[0].text).toBeUndefined();
			expect(requests.some((r) => r.url.includes(HOST_A))).toBe(false);
			expect(requests.filter((r) => r.method === 'PUT').map((r) => new URL(r.url).host)).toEqual([HOST_B]);
		});

		it('refuses a task-start for another alias with a structured result', async () => {
			const tools = handlers(await server());
			requests = [];

			const start = await tools['stonewright-task-start']({ task: 'edit a page', site: 'plugin-site' });

			const body = (start as { structuredContent?: Record<string, unknown> }).structuredContent ?? {};
			expect(body).toMatchObject({ ok: false, blocked: true, error: 'site_not_in_session' });
			expect(requests.some((r) => r.url.includes(HOST_A))).toBe(false);
		});
	});

	describe('no session alias', () => {
		it('keeps multi-site behaviour: default site without an argument, any alias with one', async () => {
			const runtimeEnv = env({ STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off' });
			const handlers = tools(runtimeEnv);

			await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			await handlers['stonewright-content-update']({ id: 7, type: 'page', title: 'x', site: 'direct-site' });

			const puts = requests.filter((r) => r.method === 'PUT').map((r) => new URL(r.url).host);
			expect(puts).toEqual([HOST_A, HOST_B]);
		});
	});
});
