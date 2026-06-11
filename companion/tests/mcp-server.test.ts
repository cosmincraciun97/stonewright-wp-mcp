/**
 * Smoke test: verify the MCP server registers the expected tools.
 * Tool handlers are covered by the runner tests.
 */

import { describe, it, expect } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';

describe('createMcpServer', () => {
	it('returns an McpServer instance without throwing', async () => {
		await expect(createMcpServer()).resolves.toBeTruthy();
	});

	it('creates a server with the correct name', async () => {
		const server = await createMcpServer();
		// _serverInfo lives on the inner Server instance (SDK internal).
		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const info = (server as any).server._serverInfo as { name: string; version: string };
		expect(info.name).toBe('stonewright-companion');
		expect(info.version).toBe('1.0.0-alpha.1');
	});

	it('registers WP-CLI tools', async () => {
		const server = await createMcpServer();
		const toolNames = registeredToolNames(server);

		expect(toolNames).toEqual(
			expect.arrayContaining([
				'companion_wp_cli_status',
				'companion_wp_cli_run',
				'companion_wp_cli_batch_run',
				'companion_wp_cli_discover',
				'companion_wp_cli_install',
				'stonewright-wp-cli-status',
				'stonewright-wp-cli-run',
				'stonewright-wp-cli-batch-run',
				'stonewright-wp-cli-discover',
				'stonewright-wp-cli-install',
			]),
		);
	});

	it('registers proxied WordPress MCP tools when endpoint env is configured', async () => {
		const fetchImpl = (_url: string | URL | Request, init?: RequestInit): Promise<Response> => {
			const url = String(_url);
			if (url.endsWith('/wp-json/stonewright/v1/skills?mode=prompt&enabled_only=1')) {
				return Promise.resolve(
					new Response(JSON.stringify({
						skills: [
							{
								slug: 'figma-quality-rules',
								title: 'Figma Quality Rules',
								description: 'Use after Figma import.',
								content: '# Figma Quality Rules\n\nBuild one section at a time.',
							},
						],
					}), { headers: { 'content-type': 'application/json' } }),
				);
			}

			const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string };
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
							tools: [
								{
									name: 'stonewright-context-bootstrap',
									description: 'Bootstrap agent context.',
									inputSchema: { type: 'object', properties: { task: { type: 'string' } } },
								},
							],
						},
					}), { headers: { 'content-type': 'application/json' } }),
				);
			}
			return Promise.resolve(
				new Response(JSON.stringify({ jsonrpc: '2.0', id: 3, result: {} }), {
					headers: { 'content-type': 'application/json' },
				}),
			);
		};

		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl,
		});

		expect(registeredToolNames(server)).toContain('stonewright-context-bootstrap');
		expect(registeredPromptNames(server)).toContain('stonewright-skill-figma-quality-rules');
	});
});

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function registeredPromptNames(server: unknown): string[] {
	return Object.keys((server as { _registeredPrompts?: Record<string, unknown> })._registeredPrompts ?? {});
}
