import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createMcpServer } from '../src/mcp-server.js';
import { resolveRuntimeMode } from '../src/direct/mode.js';
import { forgetPluginPresence, setPluginPresenceResolverForTests } from '../src/direct/plugin-guard.js';
import { resetTaskStartSeenForTests } from '../src/direct/writes.js';
import { buildSiteRecord, saveRegistry } from '../src/cli/connect/registry.js';

type ToolHandler = (input: Record<string, unknown>) => Promise<{
	isError?: boolean;
	content: Array<{ text: string }>;
	structuredContent?: Record<string, unknown>;
}>;

const SITE = 'https://example.test';
const BLOCKED = 'stonewright_direct_write_blocked_plugin_present';

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const NO_ROUTE = { code: 'rest_no_route', message: 'No route was found matching the URL and request method.' };

type World = {
	/** The Stonewright plugin is active (its stonewright/v1 namespace is listed, the bridge answers). */
	plugin: boolean;
	/** The /mcp/stonewright route answers (the MCP adapter serves the plugin). */
	mcpRoute: boolean;
	/** Extra namespaces on the index, for example a generic MCP adapter. */
	extraNamespaces: string[];
	/** A cut-off index body to serve instead of the JSON document. */
	rawIndex?: string;
};

function makeSite(world: World) {
	const calls: Array<{ method: string; path: string }> = [];
	const fetchImpl = vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const url = new URL(String(input));
		const method = (init?.method ?? 'GET').toUpperCase();
		const path = url.pathname.replace(/^\/wp-json/, '') || '/';
		calls.push({ method, path });
		if (path === '/') {
			if (world.rawIndex !== undefined) {
				return Promise.resolve(new Response(world.rawIndex, { status: 200, headers: { 'content-type': 'application/json' } }));
			}
			return Promise.resolve(json({
				namespaces: ['wp/v2', ...world.extraNamespaces, ...(world.plugin ? ['stonewright/v1'] : [])],
				routes: {},
			}));
		}
		if (path === '/mcp/stonewright') {
			if (!world.mcpRoute) return Promise.resolve(json(NO_ROUTE, 404));
			if (method === 'HEAD' || method === 'GET') return Promise.resolve(new Response('', { status: 405 }));
			const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string };
			if (body.method === 'initialize') {
				return Promise.resolve(new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18' } }), {
					headers: { 'mcp-session-id': 'session-1', 'content-type': 'application/json' },
				}));
			}
			if (body.method === 'notifications/initialized') return Promise.resolve(new Response('', { status: 202 }));
			if (body.method === 'tools/list') {
				return Promise.resolve(json({
					jsonrpc: '2.0',
					id: 2,
					result: { tools: [{ name: 'stonewright-context-bootstrap', description: 'x', inputSchema: { type: 'object', properties: {} } }] },
				}));
			}
			if (body.method === 'tools/call') {
				return Promise.resolve(json({
					jsonrpc: '2.0',
					id: 3,
					result: {
						structuredContent: {
							ok: true,
							schema_version: 2,
							saved_wordpress_mode: 'development',
							effective_wordpress_mode: 'development',
							context_token: 'plugin-token',
						},
					},
				}));
			}
			return Promise.resolve(json({ jsonrpc: '2.0', id: 4, result: {} }));
		}
		if (path === '/stonewright/v1/direct/task-start') {
			if (!world.plugin) return Promise.resolve(json(NO_ROUTE, 404));
			return Promise.resolve(json({
				ok: true,
				mode: 'production-safe',
				context_token: 'plugin-token',
				expires_at: '2099-01-01T00:00:00Z',
				target_context: { site_fingerprint: 'f'.repeat(64) },
				guidance: ['plugin guidance'],
			}));
		}
		if (path === '/stonewright/v1/skills') return Promise.resolve(json({ skills: [] }));
		if (method === 'GET') {
			return Promise.resolve(json({ id: 7, title: { raw: 'Old', rendered: 'Old' }, content: { raw: '' }, slug: 'a', status: 'draft', type: 'page', modified: '2026-10-01T00:00:00', link: `${SITE}/a` }));
		}
		return Promise.resolve(json({ id: 7, title: { raw: 'New', rendered: 'New' }, slug: 'a', status: 'draft', type: 'page', modified: '2026-10-01T00:00:00', link: `${SITE}/a` }));
	}) as unknown as typeof fetch;
	return { fetchImpl, calls };
}

describe('mode resolution never lands in Direct on a live plugin', () => {
	let dir: string;

	beforeEach(() => {
		setPluginPresenceResolverForTests(null);
		forgetPluginPresence();
		resetTaskStartSeenForTests();
		dir = mkdtempSync(join(tmpdir(), 'sw-mode-resolution-'));
		vi.stubEnv('STONEWRIGHT_STATE_DIR', join(dir, 'state'));
		vi.stubEnv('STONEWRIGHT_DIRECT_WRITES', 'on');
		vi.stubEnv('STONEWRIGHT_DIRECT_REQUIRE_TASK_START', 'off');
	});

	afterEach(() => {
		vi.unstubAllEnvs();
		setPluginPresenceResolverForTests(null);
		forgetPluginPresence();
		resetTaskStartSeenForTests();
		rmSync(dir, { recursive: true, force: true });
	});

	const baseEnv = (extra: Record<string, string> = {}): NodeJS.ProcessEnv => ({
		STONEWRIGHT_WP_URL: SITE,
		STONEWRIGHT_WP_USERNAME: 'admin',
		STONEWRIGHT_WP_APP_PASSWORD: 'pw',
		STONEWRIGHT_STATE_DIR: join(dir, 'state'),
		STONEWRIGHT_DIRECT_WRITES: 'on',
		STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
		STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
		...extra,
	});

	function toolsOf(server: unknown): Record<string, ToolHandler & { enabled?: boolean }> {
		const registered = (server as { _registeredTools: Record<string, { handler: ToolHandler; enabled?: boolean }> })._registeredTools;
		return Object.fromEntries(
			Object.entries(registered).map(([name, tool]) => [name, Object.assign(tool.handler.bind(tool), { enabled: tool.enabled })]),
		) as Record<string, ToolHandler & { enabled?: boolean }>;
	}

	function enabled(server: unknown, name: string): boolean {
		const registered = (server as { _registeredTools: Record<string, { enabled?: boolean }> })._registeredTools;
		return registered[name]?.enabled === true;
	}

	async function status(server: unknown): Promise<Record<string, unknown>> {
		const result = await toolsOf(server)['stonewright-wordpress-mcp-status']({});
		return result.structuredContent ?? {};
	}

	describe('resolveRuntimeMode', () => {
		it('auto with the plugin namespace listed and only the MCP route missing reports a plugin problem, not Direct', async () => {
			const { fetchImpl } = makeSite({ plugin: true, mcpRoute: false, extraNamespaces: [] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('plugin');
			expect(result.pluginRouteState).toBe('missing');
			expect(result.errorCode).toBe('plugin_route_missing');
			expect(result.stonewrightPluginDetected).toBe(true);
			expect(result.reason).toMatch(/plugin is active/i);
		});

		it('auto without the plugin namespace still falls back to Direct', async () => {
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('direct');
			expect(result.stonewrightPluginDetected).toBe(false);
		});

		it('a generic MCP adapter without the plugin is not a live plugin', async () => {
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: ['mcp'] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('direct');
			expect(result.pluginNamespaceDetected).toBe(true);
			expect(result.stonewrightPluginDetected).toBe(false);
		});

		it('finds the plugin namespace in an index body cut off at the size cap', async () => {
			const head = '{"name":"Site","namespaces":["oembed/1.0","wp/v2","stonewright/v1"],"authentication":[],"routes":{';
			const { fetchImpl } = makeSite({ plugin: true, mcpRoute: false, extraNamespaces: [], rawIndex: head + '"/x":{"a":"b"},'.repeat(10_000) });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('plugin');
			expect(result.stonewrightPluginDetected).toBe(true);
		});

		it.each([
			['never', 'plugin'],
			['direct-when-plugin-unavailable', 'direct'],
			['always-direct', 'direct'],
		] as const)('auto on a pluginless site with fallback_policy %s resolves %s', async (policy, expected) => {
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const result = await resolveRuntimeMode({
				env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_WP_URL: SITE },
				fetchImpl,
				fallbackPolicy: policy,
			});
			expect(result.mode).toBe(expected);
			if (expected === 'plugin') {
				expect(result.errorCode).toBe('plugin_route_missing');
				expect(result.reason).toMatch(/fallback_policy/);
			}
		});

		it('direct-only still detects the plugin and reports the conflict', async () => {
			const { fetchImpl, calls } = makeSite({ plugin: true, mcpRoute: true, extraNamespaces: [] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'direct', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('direct');
			expect(result.modeConflict).toBe(true);
			expect(result.stonewrightPluginDetected).toBe(true);
			expect(result.reason).toMatch(/writes are refused/i);
			expect(calls.every((c) => c.method === 'GET')).toBe(true);
			expect(calls.some((c) => c.path === '/mcp/stonewright')).toBe(false);
		});

		it('direct-only on a pluginless site reports no conflict', async () => {
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'direct', STONEWRIGHT_WP_URL: SITE }, fetchImpl });
			expect(result.mode).toBe('direct');
			expect(result.modeConflict).toBe(false);
		});

		it('direct-only with no site URL sends no request', async () => {
			const { fetchImpl, calls } = makeSite({ plugin: true, mcpRoute: true, extraNamespaces: [] });
			const result = await resolveRuntimeMode({ env: { STONEWRIGHT_MODE: 'direct' }, fetchImpl });
			expect(result.mode).toBe('direct');
			expect(calls).toEqual([]);
		});
	});

	describe('createMcpServer', () => {
		it('auto plus a live plugin with its MCP route missing stays degraded and registers no Direct tools', async () => {
			const { fetchImpl } = makeSite({ plugin: true, mcpRoute: false, extraNamespaces: [] });
			const server = await createMcpServer({ env: baseEnv({ STONEWRIGHT_MODE: 'auto' }), fetchImpl });

			expect(enabled(server, 'stonewright-content-update')).toBe(false);
			const state = await status(server);
			expect(state['mode']).not.toBe('direct');
			expect(state['connected']).toBe(false);
			expect(state['error_code']).toBe('plugin_route_missing');
			expect(String(state['next_action'])).toMatch(/MCP Adapter/);
		});

		it('auto plus fallback_policy never on a pluginless site stays degraded', async () => {
			const file = join(dir, 'sites.json');
			const record = { ...buildSiteRecord({ alias: 'strict', url: SITE, username: 'admin', credential_ref: 'env://FIXTURE_APP_PASSWORD' }), fallback_policy: 'never' as const };
			saveRegistry({ schema_version: 2, default_site_id: record.id, sites: [record] }, { sitesFile: file });
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const server = await createMcpServer({
				env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_SITES_FILE: file, STONEWRIGHT_SITE_ALIAS: 'strict', FIXTURE_APP_PASSWORD: 'pw', STONEWRIGHT_STATE_DIR: join(dir, 'state') },
				fetchImpl,
			});

			expect(enabled(server, 'stonewright-content-update')).toBe(false);
			const state = await status(server);
			expect(state['mode']).not.toBe('direct');
			expect(state['error_code']).toBe('plugin_route_missing');
		});

		it('auto plus fallback_policy always-direct on a pluginless site registers Direct', async () => {
			const file = join(dir, 'sites.json');
			const record = { ...buildSiteRecord({ alias: 'loose', url: SITE, username: 'admin', credential_ref: 'env://FIXTURE_APP_PASSWORD' }), fallback_policy: 'always-direct' as const };
			saveRegistry({ schema_version: 2, default_site_id: record.id, sites: [record] }, { sitesFile: file });
			const { fetchImpl } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const server = await createMcpServer({
				env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_SITES_FILE: file, STONEWRIGHT_SITE_ALIAS: 'loose', FIXTURE_APP_PASSWORD: 'pw', STONEWRIGHT_STATE_DIR: join(dir, 'state'), STONEWRIGHT_MCP_TOOL_PROFILE: 'full' },
				fetchImpl,
			});
			expect(enabled(server, 'stonewright-content-update')).toBe(true);
		});

		it('direct-only on a plugin site registers Direct, reports the conflict, refuses writes and serves reads', async () => {
			const { fetchImpl, calls } = makeSite({ plugin: true, mcpRoute: true, extraNamespaces: [] });
			const server = await createMcpServer({ env: baseEnv({ STONEWRIGHT_MODE: 'direct' }), fetchImpl });

			const state = await status(server);
			expect(state['mode']).toBe('direct');
			expect(state['mode_conflict']).toMatchObject({ plugin_detected: true, direct_writes: 'refused', reads: 'allowed' });
			expect(String(state['mode_reason'])).toMatch(/plugin is active/i);

			const tools = toolsOf(server);
			calls.length = 0;
			const read = await tools['stonewright-content-get']({ id: 7, type: 'page' });
			expect(read.isError).toBeUndefined();
			const write = await tools['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			expect(write.isError).toBe(true);
			expect(JSON.parse(write.content[0].text)).toMatchObject({ error: BLOCKED });
			expect(calls.filter((c) => c.method !== 'GET')).toEqual([]);
		});

		it('direct-only on a pluginless site writes normally and reports no conflict', async () => {
			const { fetchImpl, calls } = makeSite({ plugin: false, mcpRoute: false, extraNamespaces: [] });
			const server = await createMcpServer({ env: baseEnv({ STONEWRIGHT_MODE: 'direct' }), fetchImpl });
			expect((await status(server))['mode_conflict']).toBeNull();
			const write = await toolsOf(server)['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			expect(write.isError, write.content[0].text).toBeUndefined();
			expect(calls.some((c) => c.method === 'PUT')).toBe(true);
		});
	});

	describe('a plugin that appears mid-session', () => {
		async function directSessionThenInstallPlugin(extraEnv: Record<string, string>) {
			const world: World = { plugin: false, mcpRoute: false, extraNamespaces: [] };
			const site = makeSite(world);
			const server = await createMcpServer({ env: baseEnv(extraEnv), fetchImpl: site.fetchImpl });
			expect((await status(server))['mode']).toBe('direct');
			world.plugin = true;
			return { world, server, ...site };
		}

		it('promotes an auto session to Plugin mode at the next task-start', async () => {
			const { world, server, calls } = await directSessionThenInstallPlugin({ STONEWRIGHT_MODE: 'auto' });
			world.mcpRoute = true;
			calls.length = 0;

			const result = await toolsOf(server)['stonewright-task-start']({ task: 'edit the home page' });

			const state = await status(server);
			expect(state['mode']).toBe('plugin');
			expect(state['connected']).toBe(true);
			expect(enabled(server, 'stonewright-content-update')).toBe(false);
			expect(result.structuredContent?.['source']).toBe('plugin');
			expect(calls.filter((c) => c.method !== 'GET' && c.path !== '/mcp/stonewright' && !c.path.startsWith('/stonewright/v1/direct/'))).toEqual([]);
		});

		it('with the MCP route still missing, tells the agent to switch and blocks Direct writes', async () => {
			const { server, calls } = await directSessionThenInstallPlugin({ STONEWRIGHT_MODE: 'auto' });
			calls.length = 0;

			const result = await toolsOf(server)['stonewright-task-start']({ task: 'edit the home page' });
			const body = result.structuredContent ?? {};

			expect(body['plugin_detected']).toBe(true);
			expect(body['required_actions']).toContain('switch_to_plugin_mode');
			expect(String(body['next_action'])).toMatch(/plugin/i);
			expect(body['source']).not.toBe('plugin');

			const write = await toolsOf(server)['stonewright-content-update']({ id: 7, type: 'page', title: 'x' });
			expect(JSON.parse(write.content[0].text)).toMatchObject({ error: BLOCKED });
			expect(calls.filter((c) => c.method === 'PUT' || c.method === 'POST' && c.path.startsWith('/wp/v2'))).toEqual([]);
		});

		it('a direct-only session is told to switch, not switched', async () => {
			const { world, server } = await directSessionThenInstallPlugin({ STONEWRIGHT_MODE: 'direct' });
			world.mcpRoute = true;

			const result = await toolsOf(server)['stonewright-task-start']({ task: 'edit the home page' });
			const body = result.structuredContent ?? {};

			expect((await status(server))['mode']).toBe('direct');
			expect(body['plugin_detected']).toBe(true);
			expect(body['required_actions']).toContain('switch_to_plugin_mode');
			expect(String(body['next_action'])).toMatch(/STONEWRIGHT_MODE/);
		});

		it('a reconnect after the plugin appears switches an auto session to Plugin mode', async () => {
			const { world, server } = await directSessionThenInstallPlugin({ STONEWRIGHT_MODE: 'auto' });
			world.mcpRoute = true;

			const result = await toolsOf(server)['stonewright-reconnect']({ reason: 'plugin installed', force_probe: true });

			expect(result.structuredContent?.['ok']).toBe(true);
			expect((await status(server))['mode']).toBe('plugin');
			expect(enabled(server, 'stonewright-content-update')).toBe(false);
		});

		it('the plugin bridge reply never leaves the session looking like a normal Direct task start', async () => {
			const { server } = await directSessionThenInstallPlugin({ STONEWRIGHT_MODE: 'direct' });
			const result = await toolsOf(server)['stonewright-task-start']({ task: 'edit the home page' });
			const body = result.structuredContent ?? {};
			expect(body['plugin_detected']).toBe(true);
			expect(JSON.stringify(body['guidance'])).toMatch(/Plugin mode/);
		});
	});

});
