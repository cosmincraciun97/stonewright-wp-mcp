import { describe, expect, it, vi } from 'vitest';
import { createMcpServer } from '../../src/mcp-server.js';
import {
	pluginMcpEndpoint,
	probePluginEndpoint,
	resolveRuntimeMode,
	restIndexUrl,
} from '../../src/direct/mode.js';
import { buildConnectionStatusV3, defaultEndpointEvidence } from '../../src/connection/status-contract.js';
import { readBoundedBody } from '../../src/wordpress-mcp.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function jsonResponse(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: { 'content-type': 'application/json' },
	});
}

function restNoRoute(): Response {
	return jsonResponse({ code: 'rest_no_route', message: 'No route was found matching the URL and request method.' }, 404);
}

function html404(): Response {
	return new Response('<!DOCTYPE html><html><body>cdn 404</body></html>', {
		status: 404,
		headers: { 'content-type': 'text/html; charset=utf-8' },
	});
}

const sampleAuthentication = {
	configured: true,
	method: 'app-password' as const,
	state: 'authenticated' as const,
	reason_code: null,
	last_success_at: null,
	refresh_expires_at: null,
	continuity_target_seconds: 604800 as const,
	agent_notice_required: false,
	user_action: null,
};

const sampleRecovery = {
	catalog_preserved: true,
	remote_calls_available: true,
	last_success_at: null,
	reconnect_attempted: false,
	reconnect_coalesced: false,
};

describe('endpoint evidence', () => {
	it('does not treat HEAD 404 plus GET 401 as a missing plugin route', async () => {
		const probe = await probePluginEndpoint(
			'https://example.test/wp-json/mcp/stonewright',
			vi.fn<typeof fetch>((_input, init) => {
				if (init?.method === 'HEAD') {
					return Promise.resolve(new Response('', { status: 404 }));
				}
				return Promise.resolve(new Response('', { status: 401 }));
			}),
		);
		expect(probe.present).toBe(true);
		expect(probe.route_state).toBe('present');
		expect(probe.status).toBe(401);
	});

	it('keeps HTML CDN 404 inconclusive instead of plugin-absent', async () => {
		const probe = await probePluginEndpoint(
			'https://example.test/wp-json/mcp/stonewright',
			vi.fn(() => Promise.resolve(html404())) as unknown as typeof fetch,
		);
		expect(probe.present).toBeNull();
		expect(probe.route_state).toBe('inconclusive');
		expect(probe.body_kind).toBe('html');
	});

	it('classifies WordPress rest_no_route JSON as missing', async () => {
		const probe = await probePluginEndpoint(
			'https://example.test/wp-json/mcp/stonewright',
			vi.fn((input) => {
				const url = String(input);
				if (url.includes('/wp-json/') && !url.includes('/mcp/')) {
					return Promise.resolve(jsonResponse({ namespaces: [] }));
				}
				return Promise.resolve(restNoRoute());
			}) as unknown as typeof fetch,
		);
		expect(probe.present).toBe(false);
		expect(probe.route_state).toBe('missing');
		expect(probe.rest_code).toBe('rest_no_route');
	});

	it('plugin-only plus a missing route stays degraded without Direct tools', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: 'https://example.test',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: vi.fn((input) => {
				const url = String(input);
				if (url.includes('/wp-json/') && !url.includes('/mcp/')) {
					return Promise.resolve(jsonResponse({ namespaces: ['mcp'] }));
				}
				return Promise.resolve(restNoRoute());
			}) as unknown as typeof fetch,
		});
		const names = registeredToolNames(server);
		expect(names).toContain('stonewright-wordpress-mcp-status');
		expect(names).not.toContain('stonewright-site-discover');
		expect(names).not.toContain('stonewright-content-list');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				startup_ready?: boolean;
				ok?: boolean;
				mode?: string;
				endpoint_evidence?: { plugin_route_state?: string; initialized?: boolean; plugin_http_status?: number | null; active_url?: string | null; last_checked_at?: string | null };
				plugin?: { reachable?: boolean | null; registry_ready?: boolean };
				error_code?: string | null;
			};
		};
		expect(status.structuredContent?.connected).toBe(false);
		expect(status.structuredContent?.startup_ready).toBe(false);
		expect(status.structuredContent?.ok).toBe(false);
		expect(status.structuredContent?.mode).not.toBe('direct');
		expect(status.structuredContent?.plugin?.registry_ready).toBe(false);
		expect(status.structuredContent?.plugin?.reachable).toBe(false);
		expect(status.structuredContent?.error_code).toBe('plugin_route_missing');
		expect(status.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('missing');
		expect(status.structuredContent?.endpoint_evidence?.plugin_http_status).toBe(404);
		expect(status.structuredContent?.endpoint_evidence?.active_url).toBeNull();
		expect(status.structuredContent?.endpoint_evidence?.last_checked_at).toEqual(expect.any(String));
	});

	it('auto plus a missing plugin route falls back to Direct with explicit evidence', async () => {
		const site = 'https://example.test';
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_WP_URL: site,
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
			},
			fetchImpl: vi.fn((input) => {
				const url = String(input);
				if (url.replace(/\/+$/, '').endsWith('/wp-json')) {
					return Promise.resolve(jsonResponse({ namespaces: ['mcp'] }));
				}
				return Promise.resolve(restNoRoute());
			}) as unknown as typeof fetch,
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				active_mode?: string;
				mode?: string;
				endpoint_evidence?: {
					plugin_route_state?: string;
					initialized?: boolean;
					active_url?: string | null;
					configured_mcp_url?: string | null;
				};
				plugin?: { reachable?: boolean | null; registry_ready?: boolean };
				error_code?: string | null;
			};
		};
		expect(status.structuredContent?.mode).toBe('direct');
		expect(status.structuredContent?.active_mode).toBe('direct');
		expect(status.structuredContent?.connected).toBe(true);
		expect(status.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('missing');
		expect(status.structuredContent?.endpoint_evidence?.initialized).toBe(false);
		expect(status.structuredContent?.plugin?.reachable).toBe(false);
		expect(status.structuredContent?.plugin?.registry_ready).toBe(false);
		expect(status.structuredContent?.endpoint_evidence?.active_url).toBe(restIndexUrl(site));
		expect(status.structuredContent?.endpoint_evidence?.configured_mcp_url).toBe(pluginMcpEndpoint(site));
		expect(status.structuredContent?.endpoint_evidence?.active_url).not.toBe(
			status.structuredContent?.endpoint_evidence?.configured_mcp_url,
		);
	});

	it('keeps transport.mcp_url as the configured plugin MCP endpoint in Direct', () => {
		const status = buildConnectionStatusV3({
			configuredMode: 'auto',
			activeMode: 'direct',
			connectionStage: 'direct-ready',
			connectionGeneration: 1,
			mcpUrl: 'https://example.test/wp-json/mcp/stonewright',
			authentication: sampleAuthentication,
			recovery: sampleRecovery,
			plugin: { reachable: false, enabled_requested: true, effective_state: 'direct-ready', registry_ready: false },
			endpointEvidence: {
				...defaultEndpointEvidence(),
				configured_mcp_url: 'https://example.test/wp-json/mcp/stonewright',
				active_url: 'https://example.test/wp-json/',
				plugin_route_state: 'missing',
				plugin_http_status: 404,
			},
			surface: {
				profile: 'full',
				local_tool_count: 9,
				remote_tool_count: 40,
				registered_tool_count: 49,
				revision: 1,
				digest: 'sha256:direct',
				relist_required: false,
			},
			startupReady: true,
		});
		expect(status.transport.mcp_url).toBe('https://example.test/wp-json/mcp/stonewright');
		expect(status.endpoint_evidence.active_url).toBe('https://example.test/wp-json/');
		expect(status.connected).toBe(true);
		expect(status.plugin.reachable).toBe(false);
		expect(status.plugin.registry_ready).toBe(false);
	});

	it('does not mark plugin-registering as connected before handshake', () => {
		const status = buildConnectionStatusV3({
			configuredMode: 'plugin-only',
			activeMode: 'plugin',
			connectionStage: 'plugin-registering',
			connectionGeneration: 1,
			authentication: { ...sampleAuthentication, state: 'unknown' },
			recovery: { ...sampleRecovery, remote_calls_available: false },
			plugin: { reachable: true, enabled_requested: true, effective_state: 'plugin-registering', registry_ready: false },
			surface: {
				profile: 'essential-static',
				local_tool_count: 9,
				remote_tool_count: 0,
				registered_tool_count: 9,
				revision: 1,
				digest: 'sha256:staging',
				relist_required: false,
			},
			startupReady: false,
		});
		expect(status.connected).toBe(false);
		expect(status.startup_ready).toBe(false);
	});

	it('plugin-only force_probe with a missing route does not switch configured mode', async () => {
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: 'https://example.test',
			},
			fetchImpl: vi.fn((input) => {
				const url = String(input);
				if (url.replace(/\/+$/, '').endsWith('/wp-json')) {
					return Promise.resolve(jsonResponse({ namespaces: [] }));
				}
				return Promise.resolve(restNoRoute());
			}) as unknown as typeof fetch,
			forceProbe: true,
		});
		expect(result.mode).toBe('plugin');
		expect(result.configured).toBe('plugin-only');
		expect(result.pluginRouteState).toBe('missing');
		expect(result.errorCode).toBe('plugin_route_missing');
	});

	it('caps response bytes while reading instead of slicing an unbounded body', async () => {
		const body = `${'n'.repeat(5000)}rest_no_route`;
		const result = await readBoundedBody(new Response(body));
		expect(result.truncated).toBe(true);
		expect(result.text.length).toBe(4096);
		expect(result.text.includes('rest_no_route')).toBe(false);
	});

	it('does not treat a truncated REST body as a missing plugin route', async () => {
		const padded = JSON.stringify({
			code: 'rest_no_route',
			message: 'No route was found matching the URL and request method.',
			pad: 'x'.repeat(6000),
		});
		const probe = await probePluginEndpoint(
			'https://example.test/wp-json/mcp/stonewright',
			vi.fn<typeof fetch>(() => Promise.resolve(new Response(padded, {
				status: 404,
				headers: { 'content-type': 'application/json' },
			}))),
		);
		expect(probe.route_state).toBe('inconclusive');
		expect(probe.present).toBeNull();
	});

	it('HEAD 200 then initialize rest_no_route overwrites present evidence and falls back in auto', async () => {
		const site = 'https://example.test';
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_WP_URL: site,
				STONEWRIGHT_MCP_URL: `${site}/wp-json/mcp/stonewright`,
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: vi.fn<typeof fetch>((input, init) => {
				const url = String(input);
				const method = init?.method ?? 'GET';
				if (method === 'HEAD') {
					return Promise.resolve(new Response('', { status: 200 }));
				}
				if (url.replace(/\/+$/, '').endsWith('/wp-json')) {
					return Promise.resolve(jsonResponse({ namespaces: ['wp/v2'] }));
				}
				return Promise.resolve(restNoRoute());
			}),
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				mode?: string;
				endpoint_evidence?: {
					plugin_route_state?: string;
					plugin_http_status?: number | null;
					initialized?: boolean;
					active_url?: string | null;
					configured_mcp_url?: string | null;
				};
				plugin?: { reachable?: boolean | null; registry_ready?: boolean };
			};
		};
		expect(status.structuredContent?.mode).toBe('direct');
		expect(status.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('missing');
		expect(status.structuredContent?.endpoint_evidence?.plugin_http_status).toBe(404);
		expect(status.structuredContent?.endpoint_evidence?.initialized).toBe(false);
		expect(status.structuredContent?.plugin?.reachable).toBe(false);
		expect(status.structuredContent?.plugin?.registry_ready).toBe(false);
		expect(status.structuredContent?.endpoint_evidence?.active_url).toBe(restIndexUrl(site));
		expect(status.structuredContent?.endpoint_evidence?.active_url).not.toBe(
			status.structuredContent?.endpoint_evidence?.configured_mcp_url,
		);
	});

	it('does not treat HTML 404, timeouts, refused sockets, or JSON-RPC not found as plugin absence', async () => {
		const cases: Array<{ name: string; fetchImpl: typeof fetch; expectState: string }> = [
			{
				name: 'html',
				fetchImpl: vi.fn<typeof fetch>(() => Promise.resolve(html404())),
				expectState: 'inconclusive',
			},
			{
				name: 'timeout',
				fetchImpl: vi.fn<typeof fetch>(() => {
					const err = new Error('timeout');
					err.name = 'TimeoutError';
					return Promise.reject(err);
				}),
				expectState: 'inconclusive',
			},
			{
				name: 'refused',
				fetchImpl: vi.fn<typeof fetch>(() => {
					const err = new Error('connect ECONNREFUSED');
					(err as Error & { code?: string }).code = 'ECONNREFUSED';
					return Promise.reject(err);
				}),
				expectState: 'inconclusive',
			},
		];
		for (const testCase of cases) {
			const server = await createMcpServer({
				env: {
					STONEWRIGHT_MODE: 'auto',
					STONEWRIGHT_WP_URL: 'https://example.test',
					STONEWRIGHT_MCP_URL: 'https://example.test/wp-json/mcp/stonewright',
					WP_API_USERNAME: 'admin',
					WP_API_PASSWORD: 'pw',
				},
				fetchImpl: testCase.fetchImpl,
			});
			const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
			const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
				structuredContent?: {
					mode?: string;
					connected?: boolean;
					endpoint_evidence?: { plugin_route_state?: string; active_url?: string | null };
				};
			};
			expect(status.structuredContent?.mode, testCase.name).not.toBe('direct');
			expect(status.structuredContent?.connected, testCase.name).toBe(false);
			expect(status.structuredContent?.endpoint_evidence?.plugin_route_state, testCase.name).toBe(testCase.expectState);
			expect(status.structuredContent?.endpoint_evidence?.active_url, testCase.name).toBeNull();
		}

		const jsonRpcFetch = vi.fn<typeof fetch>((_input, init) => {
			if (init?.method === 'HEAD') {
				return Promise.resolve(new Response('', { status: 200 }));
			}
			return Promise.resolve(jsonResponse({
				jsonrpc: '2.0',
				id: 1,
				error: { code: -32601, message: 'Method not found' },
			}));
		});
		const rpcServer = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_WP_URL: 'https://example.test',
				STONEWRIGHT_MCP_URL: 'https://example.test/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: jsonRpcFetch,
		});
		const rpcTools = (rpcServer as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const rpcStatus = await rpcTools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				mode?: string;
				connected?: boolean;
				endpoint_evidence?: { plugin_route_state?: string };
			};
		};
		expect(rpcStatus.structuredContent?.mode).not.toBe('direct');
		expect(rpcStatus.structuredContent?.connected).toBe(false);
		expect(rpcStatus.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('present');
	});

	it('keeps 401 and 403 fail-closed without Direct fallback', async () => {
		for (const statusCode of [401, 403]) {
			const server = await createMcpServer({
				env: {
					STONEWRIGHT_MODE: 'auto',
					STONEWRIGHT_WP_URL: 'https://example.test',
					STONEWRIGHT_MCP_URL: 'https://example.test/wp-json/mcp/stonewright',
					WP_API_USERNAME: 'admin',
					WP_API_PASSWORD: 'pw',
				},
				fetchImpl: vi.fn<typeof fetch>(() => Promise.resolve(jsonResponse({
					code: 'rest_forbidden',
					message: 'Sorry, you are not allowed to do that.',
				}, statusCode))),
			});
			const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
			const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
				structuredContent?: {
					mode?: string;
					connected?: boolean;
					error_code?: string | null;
					endpoint_evidence?: { plugin_route_state?: string; plugin_http_status?: number | null };
				};
			};
			expect(status.structuredContent?.mode).not.toBe('direct');
			expect(status.structuredContent?.connected).toBe(false);
			expect(status.structuredContent?.error_code).toBe('auth_error');
			expect(status.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('present');
			expect(status.structuredContent?.endpoint_evidence?.plugin_http_status).toBe(statusCode);
		}
	});

	it('does not mark connected from Direct tool registration without a backend read probe', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
			},
		});
		const names = registeredToolNames(server);
		expect(names).toContain('stonewright-wordpress-mcp-status');
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				mode?: string;
				connection_stage?: string;
				endpoint_evidence?: { active_url?: string | null };
			};
		};
		expect(status.structuredContent?.mode).toBe('direct');
		expect(status.structuredContent?.connected).toBe(false);
		expect(status.structuredContent?.connection_stage).not.toBe('direct-ready');
		expect(status.structuredContent?.endpoint_evidence?.active_url).toBeNull();
	});

	it('failed reconnect keeps last success separate from the latest attempt', async () => {
		const site = 'https://example.test';
		let healthy = true;
		const fetchImpl = vi.fn<typeof fetch>((input, init) => {
			const url = String(input);
			const method = init?.method ?? 'GET';
			if (!healthy) {
				return Promise.resolve(jsonResponse({
					code: 'rest_forbidden',
					message: 'Sorry, you are not allowed to do that.',
				}, 401));
			}
			if (method === 'HEAD' || (method === 'GET' && url.includes('/wp-json') && !url.includes('/mcp/'))) {
				return Promise.resolve(new Response('', { status: 200 }));
			}
			const payload = JSON.parse(String(init?.body ?? '{}')) as { method?: string; id?: number };
			if (payload.method === 'initialize') {
				return Promise.resolve(jsonResponse({
					jsonrpc: '2.0',
					id: payload.id ?? 1,
					result: { protocolVersion: '2025-06-18', instructions: 'ok' },
				}));
			}
			if (payload.method === 'notifications/initialized') {
				return Promise.resolve(new Response('', { status: 202 }));
			}
			if (payload.method === 'tools/list') {
				return Promise.resolve(jsonResponse({
					jsonrpc: '2.0',
					id: payload.id ?? 2,
					result: {
						tools: [
							{ name: 'stonewright-context-bootstrap', inputSchema: { type: 'object', properties: {} } },
							{ name: 'stonewright-task-start', inputSchema: { type: 'object', properties: {} } },
							{ name: 'stonewright-skills-get', inputSchema: { type: 'object', properties: {} } },
						],
					},
				}));
			}
			return Promise.resolve(jsonResponse({ jsonrpc: '2.0', id: payload.id ?? 3, result: {} }));
		});
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: site,
				STONEWRIGHT_MCP_URL: `${site}/wp-json/mcp/stonewright`,
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl,
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const connected = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				endpoint_evidence?: { initialized?: boolean };
				recovery?: { last_success_at?: string | null; remote_calls_available?: boolean; reconnect_attempted?: boolean };
			};
		};
		expect(connected.structuredContent?.connected).toBe(true);
		expect(connected.structuredContent?.endpoint_evidence?.initialized).toBe(true);
		const lastSuccess = connected.structuredContent?.recovery?.last_success_at;
		expect(lastSuccess).toEqual(expect.any(String));

		healthy = false;
		const reconnect = await tools['stonewright-reconnect']?.handler?.({ reason: 'rotate credentials' }) as {
			structuredContent?: { ok?: boolean; prior_registry_preserved?: boolean };
		};
		expect(reconnect.structuredContent?.ok).toBe(false);
		expect(reconnect.structuredContent?.prior_registry_preserved).toBe(true);

		const after = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				connected?: boolean;
				endpoint_evidence?: { initialized?: boolean; plugin_route_state?: string; plugin_http_status?: number | null };
				recovery?: { last_success_at?: string | null; remote_calls_available?: boolean; reconnect_attempted?: boolean };
			};
		};
		expect(after.structuredContent?.connected).toBe(false);
		expect(after.structuredContent?.endpoint_evidence?.initialized).toBe(false);
		expect(after.structuredContent?.endpoint_evidence?.plugin_route_state).toBe('present');
		expect(after.structuredContent?.endpoint_evidence?.plugin_http_status).toBe(401);
		expect(after.structuredContent?.recovery?.last_success_at).toBe(lastSuccess);
		expect(after.structuredContent?.recovery?.reconnect_attempted).toBe(true);
		expect(after.structuredContent?.recovery?.remote_calls_available).toBe(true);
	});
});
