import { describe, expect, it, vi } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';
import { resolveRuntimeMode, probePluginEndpoint, pluginMcpEndpoint } from '../src/direct/mode.js';
import { buildSetupProfile } from '../src/setup-profile.js';
import { DIRECT_TOOL_NAMES } from '../src/direct/registry.js';

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

describe('direct startup auto-detect', () => {
	it('forces direct mode when STONEWRIGHT_MODE=direct', async () => {
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
			},
			fetchImpl: vi.fn(async () => new Response('', { status: 200 })),
		});
		expect(result.mode).toBe('direct');
		expect(result.reason).toMatch(/direct/i);
	});

	it('forces plugin mode when STONEWRIGHT_MODE=plugin', async () => {
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_WP_URL: 'https://example.com',
			},
			fetchImpl: vi.fn(async () => new Response('', { status: 404 })),
		});
		expect(result.mode).toBe('plugin');
	});

	it('auto-detects direct when plugin MCP endpoint returns 404', async () => {
		const endpoint = pluginMcpEndpoint('https://example.com');
		const fetchImpl = vi.fn(async (input: RequestInfo | URL) => {
			expect(String(input)).toBe(endpoint);
			return new Response('', { status: 404 });
		});
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_WP_URL: 'https://example.com',
			},
			fetchImpl,
		});
		expect(result.mode).toBe('direct');
		expect(result.pluginEndpointStatus).toBe(404);
	});

	it('auto-detects plugin when endpoint responds 200', async () => {
		const fetchImpl = vi.fn(async () => new Response('', { status: 200 }));
		const result = await resolveRuntimeMode({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_WP_URL: 'https://example.com',
			},
			fetchImpl,
		});
		expect(result.mode).toBe('plugin');
		expect(result.pluginEndpointStatus).toBe(200);
	});

	it('treats 401 on endpoint as plugin present', async () => {
		const probe = await probePluginEndpoint(
			'https://example.com/wp-json/mcp/stonewright',
			vi.fn(async () => new Response('', { status: 401 })),
		);
		expect(probe.present).toBe(true);
	});

	it('registers Direct tools when mode=direct', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
			fetchImpl: vi.fn(async () => new Response('', { status: 200 })),
		});
		const names = registeredToolNames(server);
		expect(names).toEqual(expect.arrayContaining([
			'stonewright-site-discover',
			'stonewright-content-list',
			'stonewright-menu-list',
			'stonewright-plugin-list',
			'stonewright-gutenberg-compose',
			'stonewright-wp-cli-status',
			'stonewright-setup-profile',
		]));
		expect(names).not.toContain('stonewright-context-bootstrap');
		expect(names.filter((n) => DIRECT_TOOL_NAMES.includes(n as typeof DIRECT_TOOL_NAMES[number])).length)
			.toBe(DIRECT_TOOL_NAMES.length);

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const status = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				mode?: string;
				direct_tool_count?: number;
				ok?: boolean;
				unavailable_plugin_capabilities?: Array<{ id: string }>;
			};
		};
		expect(status.structuredContent?.mode).toBe('direct');
		expect(status.structuredContent?.ok).toBe(true);
		expect(status.structuredContent?.direct_tool_count).toBeGreaterThanOrEqual(35);
		expect(status.structuredContent?.unavailable_plugin_capabilities?.some((c) => c.id === 'php-execute')).toBe(true);
	});

	it('keeps plugin proxy tools when auto probe finds the endpoint', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'auto',
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: async (input, init) => {
				const url = String(input);
				if (!init?.body && (init?.method === 'HEAD' || init?.method === 'GET' || !init?.method)) {
					return new Response('', { status: 200 });
				}
				const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string };
				if (body.method === 'initialize') {
					return new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { protocolVersion: '2025-06-18' } }), {
						headers: { 'mcp-session-id': 's1', 'content-type': 'application/json' },
					});
				}
				if (body.method === 'notifications/initialized') {
					return new Response('', { status: 202 });
				}
				if (body.method === 'tools/list') {
					return new Response(JSON.stringify({
						jsonrpc: '2.0',
						id: 2,
						result: {
							tools: [
								{ name: 'stonewright-context-bootstrap', inputSchema: { type: 'object', properties: {} } },
								{ name: 'stonewright-task-start', inputSchema: { type: 'object', properties: {} } },
								{ name: 'stonewright-skills-get', inputSchema: { type: 'object', properties: {} } },
							],
						},
					}), { headers: { 'content-type': 'application/json' } });
				}
				if (url.includes('/skills')) {
					return new Response(JSON.stringify({ skills: [] }), {
						headers: { 'content-type': 'application/json' },
					});
				}
				return new Response(JSON.stringify({ jsonrpc: '2.0', id: 3, result: {} }), {
					headers: { 'content-type': 'application/json' },
				});
			},
		});

		const names = registeredToolNames(server);
		expect(names).toContain('stonewright-context-bootstrap');
		expect(names).toContain('stonewright-wp-cli-status');
		expect(names).not.toContain('stonewright-site-discover');
	});

	it('setup-profile reports mode direct and unavailable capabilities', () => {
		const profile = buildSetupProfile(
			{
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_WP_URL: 'https://example.com',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
			'darwin',
			{ mode: 'direct', mode_reason: 'forced' },
		);
		expect(profile.mode).toBe('direct');
		expect(profile.first_calls[0]).toBe('stonewright-site-discover');
		expect(profile.unavailable?.some((c) => c.id === 'elementor-engine')).toBe(true);
		expect(profile.direct_tool_count).toBeGreaterThanOrEqual(35);
		expect(profile.notes.some((n) => n.includes('STONEWRIGHT_MODE'))).toBe(true);
	});
});
