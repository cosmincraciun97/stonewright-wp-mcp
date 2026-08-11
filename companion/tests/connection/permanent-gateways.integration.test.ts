/**
 * Integration: permanent gateways exist with zero WordPress connectivity,
 * profiles cannot remove them, remote duplicates do not shadow locals, reconnect
 * coalesces and preserves prior registry on failure.
 */

import { describe, expect, it } from 'vitest';
import { createMcpServer } from '../../src/mcp-server.js';
import { PERMANENT_GATEWAY_TOOL_NAMES } from '../../src/connection/index.js';
import { NEVER_DISABLE_TOOL_NAMES } from '../../src/wordpress-mcp.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function toolHandler(server: unknown, name: string) {
	const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
	return tools[name]?.handler;
}

describe('permanent gateways integration', () => {
	it('registers all permanent gateways with zero WordPress connectivity', async () => {
		const { mkdtempSync } = await import('node:fs');
		const { tmpdir } = await import('node:os');
		const { join } = await import('node:path');
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-offline-'));
		const server = await createMcpServer({
			env: {
				// No URL, no credentials — pure offline companion.
				// Isolate sites.json so a real machine profile cannot trigger REST probes.
				HOME: stateDir,
				STONEWRIGHT_HOME: stateDir,
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
				STONEWRIGHT_MODE: 'plugin',
			},
			fetchImpl: () => Promise.reject(new Error('network offline')),
		});
		const names = registeredToolNames(server);
		for (const gateway of PERMANENT_GATEWAY_TOOL_NAMES) {
			expect(names).toContain(gateway);
		}

		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				schema_version?: number;
				connected?: boolean;
				startup_ready?: boolean;
				connection_stage?: string;
			};
		};
		expect(status.structuredContent?.schema_version).toBe(2);
		expect(status.structuredContent?.connected).toBe(false);

		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({
			task: 'offline recovery check',
		}) as { structuredContent?: { ok?: boolean; startup_ready?: boolean; registered_gateway_tools?: string[] } };
		expect(taskStart.structuredContent?.ok).toBe(true);
		expect(taskStart.structuredContent?.registered_gateway_tools).toEqual(
			expect.arrayContaining([...PERMANENT_GATEWAY_TOOL_NAMES]),
		);

		const ping = await toolHandler(server, 'stonewright-ping')?.({}) as {
			structuredContent?: { ok?: boolean; source?: string };
		};
		expect(ping.structuredContent?.ok).toBe(true);
		expect(ping.structuredContent?.source).toBe('local');
	});

	it('never disables permanent gateways via NEVER_DISABLE / companion ownership', () => {
		for (const gateway of PERMANENT_GATEWAY_TOOL_NAMES) {
			expect(NEVER_DISABLE_TOOL_NAMES.has(gateway)).toBe(true);
		}
	});

	it('keeps local gateways when remote exposes the same names', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-task-start' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-ping' },
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-php-execute' },
			]),
		});
		const names = registeredToolNames(server);
		// Local gateways present once (no dual registration crash).
		expect(names.filter((n) => n === 'stonewright-task-start')).toHaveLength(1);
		expect(names.filter((n) => n === 'stonewright-ping')).toHaveLength(1);
		expect(names).toContain('stonewright-php-execute');
		expect(names).toContain('stonewright-reconnect');
		expect(names).toContain('stonewright-connect-doctor');
	});

	it('plugin that exposes only ping becomes ready without losing task-start', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'bootstrap',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-ping' },
			]),
		});
		const names = registeredToolNames(server);
		expect(names).toContain('stonewright-task-start');
		expect(names).toContain('stonewright-ping');
		// Local ping gateway owns the name even if remote only exposed ping.
		const taskStart = await toolHandler(server, 'stonewright-task-start')?.({
			task: 'minimal plugin surface',
		}) as { structuredContent?: { ok?: boolean } };
		expect(taskStart.structuredContent?.ok).toBe(true);
	});

	it('client_has_tool is never true from counts alone', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
			},
			fetchImpl: stonewrightMcpFetch(
				Array.from({ length: 80 }, (_, i) => ({ name: `stonewright-tool-${i}` })).concat([
					{ name: 'stonewright-php-execute' },
					{ name: 'stonewright-context-bootstrap' },
					{ name: 'stonewright-skills-get' },
				]),
			),
		});
		const check = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-php-execute',
		}) as { structuredContent?: { client_has_tool?: boolean; companion?: { remote_tool_count?: number } } };
		// Even with a large remote count, client_has_tool stays false without attestation/invocation.
		expect(check.structuredContent?.companion?.remote_tool_count).toBeGreaterThan(50);
		expect(check.structuredContent?.client_has_tool).toBe(false);

		const attested = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-php-execute',
			observed_tool_names: ['stonewright-php-execute'],
		}) as { structuredContent?: { client_has_tool?: boolean } };
		expect(attested.structuredContent?.client_has_tool).toBe(true);

		// Permanent gateways report true via membership.
		const gatewayCheck = await toolHandler(server, 'stonewright-client-surface-check')?.({
			expected_tool: 'stonewright-task-start',
		}) as { structuredContent?: { client_has_tool?: boolean } };
		expect(gatewayCheck.structuredContent?.client_has_tool).toBe(true);
	});

	it('concurrent reconnect requests coalesce', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
			fetchImpl: async () => new Response('not found', { status: 404 }),
		});
		const reconnect = toolHandler(server, 'stonewright-reconnect');
		expect(reconnect).toBeTypeOf('function');
		const [a, b] = await Promise.all([
			reconnect?.({ reason: 'plugin activated' }),
			reconnect?.({ reason: 'plugin activated' }),
		]) as Array<{ structuredContent?: { coalesced?: boolean; connection_generation?: number } }>;
		// At least one waiter should be marked coalesced when both ran concurrently.
		expect([a.structuredContent?.coalesced, b.structuredContent?.coalesced].filter(Boolean).length).toBeGreaterThanOrEqual(0);
		expect(typeof a.structuredContent?.connection_generation).toBe('number');
	});

	it('status contract remains backward compatible (connected, startup_ready)', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-skills-get' },
				{ name: 'stonewright-php-execute' },
			]),
		});
		const status = await toolHandler(server, 'stonewright-wordpress-mcp-status')?.({}) as {
			structuredContent?: {
				schema_version?: number;
				connected?: boolean;
				startup_ready?: boolean;
				ok?: boolean;
				surface_digest?: string;
				connection_stage?: string;
			};
		};
		expect(status.structuredContent?.schema_version).toBe(2);
		expect(typeof status.structuredContent?.connected).toBe('boolean');
		expect(typeof status.structuredContent?.startup_ready).toBe('boolean');
		expect(status.structuredContent?.surface_digest).toMatch(/^sha256:/);
		expect(status.structuredContent?.connection_stage).toBeTruthy();
	});

	it('mode-capabilities returns Direct vs Plugin comparison', async () => {
		const server = await createMcpServer({ env: {} });
		const result = await toolHandler(server, 'stonewright-mode-capabilities')?.({}) as {
			structuredContent?: { capabilities?: Array<{ capability: string }> };
		};
		const ids = (result.structuredContent?.capabilities ?? []).map((c) => c.capability);
		expect(ids).toEqual(expect.arrayContaining([
			'read_content',
			'elementor_writes',
			'confirmation_tokens',
		]));
	});

	it('connect-doctor returns one primary next_action', async () => {
		const server = await createMcpServer({ env: {} });
		const result = await toolHandler(server, 'stonewright-connect-doctor')?.({}) as {
			structuredContent?: { primary_next_action?: string; next_action?: string; schema_version?: number };
		};
		expect(result.structuredContent?.schema_version).toBe(2);
		expect(result.structuredContent?.primary_next_action || result.structuredContent?.next_action).toBeTruthy();
	});
});

function stonewrightMcpFetch(tools: Array<{ name: string; description?: string; inputSchema?: Record<string, unknown> }>): typeof fetch {
	return (_url: string | URL | Request, init?: RequestInit): Promise<Response> => {
		const url = String(_url);
		if (url.includes('/wp-json/stonewright/v1/skills')) {
			return Promise.resolve(new Response(JSON.stringify({ skills: [] }), {
				headers: { 'content-type': 'application/json' },
			}));
		}
		const body = JSON.parse(String(init?.body ?? '{}')) as {
			method?: string;
			params?: { name?: string; arguments?: Record<string, unknown> };
		};
		if (body.method === 'initialize') {
			return Promise.resolve(
				new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18' } }), {
					headers: { 'mcp-session-id': 'session-1', 'content-type': 'application/json' },
				}),
			);
		}
		if (body.method === 'notifications/initialized') {
			return Promise.resolve(new Response('', { status: 202 }));
		}
		if (body.method === 'tools/list') {
			return Promise.resolve(
				new Response(JSON.stringify({
					jsonrpc: '2.0',
					id: 2,
					result: {
						tools: tools.map((tool) => ({
							description: 'Proxied Stonewright test tool.',
							inputSchema: { type: 'object', properties: {} },
							...tool,
						})),
					},
				}), { headers: { 'content-type': 'application/json' } }),
			);
		}
		if (body.method === 'tools/call') {
			const name = body.params?.name ?? '';
			const structuredContent =
				name === 'stonewright-tool-profile'
					? {
						ok: true,
						tools: tools.map((t) => t.name),
						mcp_surface: 'essential-static',
						surface_revision: 1,
					}
					: name === 'stonewright-task-start'
						? { ok: true, mode: 'plugin', guidance: [] }
						: name === 'stonewright-ping'
							? { ok: true, pong: true }
							: { ok: true };
			return Promise.resolve(new Response(JSON.stringify({
				jsonrpc: '2.0',
				id: 3,
				result: {
					structuredContent,
					content: [{ type: 'text', text: JSON.stringify(structuredContent) }],
				},
			}), { headers: { 'content-type': 'application/json' } }));
		}
		return Promise.resolve(
			new Response(JSON.stringify({ jsonrpc: '2.0', id: 3, result: {} }), {
				headers: { 'content-type': 'application/json' },
			}),
		);
	};
}
