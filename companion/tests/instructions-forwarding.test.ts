/**
 * Task 4.1: companion proxy forwards plugin initialize.instructions.
 */
import { describe, it, expect } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';
import { WordPressMcpClient, mergeServerInstructions } from '../src/wordpress-mcp.js';

describe('mergeServerInstructions', () => {
	it('appends remote plugin instructions under a separator', () => {
		const merged = mergeServerInstructions('companion base', 'Plugin: call stonewright-task-start first.');
		expect(merged).toContain('companion base');
		expect(merged).toContain('--- WordPress plugin instructions ---');
		expect(merged).toContain('stonewright-task-start');
	});

	it('falls back to companion text when remote is empty', () => {
		expect(mergeServerInstructions('companion only', '')).toBe('companion only');
		expect(mergeServerInstructions('companion only', '   ')).toBe('companion only');
	});
});

describe('WordPressMcpClient remoteInstructions', () => {
	it('captures initialize.instructions', async () => {
		const client = new WordPressMcpClient(
			{
				url: 'https://example.com/wp-json/mcp/stonewright',
				timeoutMs: 5_000,
				username: 'admin',
				password: 'pw',
			},
			(_url, init) => {
				const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string };
				if (body.method === 'initialize') {
					return Promise.resolve(
						new Response(
							JSON.stringify({
								jsonrpc: '2.0',
								id: 1,
								result: {
									protocolVersion: '2025-06-18',
									instructions:
										'Stonewright fast start:\n- First call MCP tool stonewright-task-start with task, surface, and intent.',
								},
							}),
							{
								headers: { 'mcp-session-id': 'sess-1', 'content-type': 'application/json' },
							},
						),
					);
				}
				if (body.method === 'notifications/initialized') {
					return Promise.resolve(new Response('', { status: 202 }));
				}
				return Promise.resolve(
					new Response(JSON.stringify({ jsonrpc: '2.0', id: 2, result: { tools: [] } }), {
						headers: { 'content-type': 'application/json' },
					}),
				);
			},
		);

		await client.listTools();
		expect(client.remoteInstructions).toContain('stonewright-task-start');
	});
});

describe('createMcpServer forwards plugin instructions', () => {
	it('includes remote initialize.instructions in companion handshake instructions', async () => {
		const pluginInstructions =
			'Stonewright fast start:\n- First call MCP tool stonewright-task-start with task, surface, and intent.';

		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'bootstrap',
			},
			fetchImpl: (_url, init) => {
				const url = String(_url);
				if (!init?.body && (init?.method === 'HEAD' || init?.method === 'GET' || !init?.method)) {
					return Promise.resolve(new Response('', { status: 200 }));
				}
				if (url.includes('/skills')) {
					return Promise.resolve(
						new Response(JSON.stringify({ skills: [] }), {
							headers: { 'content-type': 'application/json' },
						}),
					);
				}
				const body = JSON.parse(String(init?.body ?? '{}')) as { method?: string; params?: { name?: string } };
				if (body.method === 'initialize') {
					return Promise.resolve(
						new Response(
							JSON.stringify({
								jsonrpc: '2.0',
								id: 1,
								result: {
									protocolVersion: '2025-06-18',
									instructions: pluginInstructions,
								},
							}),
							{
								headers: { 'mcp-session-id': 'session-fwd', 'content-type': 'application/json' },
							},
						),
					);
				}
				if (body.method === 'notifications/initialized') {
					return Promise.resolve(new Response('', { status: 202 }));
				}
				if (body.method === 'tools/list') {
					return Promise.resolve(
						new Response(
							JSON.stringify({
								jsonrpc: '2.0',
								id: 2,
								result: {
									tools: [
										{ name: 'stonewright-context-bootstrap', inputSchema: { type: 'object', properties: {} } },
										{ name: 'stonewright-task-start', inputSchema: { type: 'object', properties: {} } },
										{ name: 'stonewright-skills-get', inputSchema: { type: 'object', properties: {} } },
										{ name: 'stonewright-tool-profile', inputSchema: { type: 'object', properties: {} } },
										{ name: 'stonewright-php-execute', inputSchema: { type: 'object', properties: {} } },
									],
								},
							}),
							{ headers: { 'content-type': 'application/json' } },
						),
					);
				}
				if (body.method === 'tools/call' && body.params?.name === 'stonewright-tool-profile') {
					return Promise.resolve(
						new Response(
							JSON.stringify({
								jsonrpc: '2.0',
								id: 3,
								result: {
									content: [
										{
											type: 'text',
											text: JSON.stringify({
												ok: true,
												tools: [
													'stonewright-context-bootstrap',
													'stonewright-task-start',
													'stonewright-skills-get',
													'stonewright-tool-profile',
													'stonewright-php-execute',
												],
												mcp_surface: 'bootstrap',
											}),
										},
									],
									structuredContent: {
										ok: true,
										tools: [
											'stonewright-context-bootstrap',
											'stonewright-task-start',
											'stonewright-skills-get',
											'stonewright-tool-profile',
											'stonewright-php-execute',
										],
										mcp_surface: 'bootstrap',
									},
								},
							}),
							{ headers: { 'content-type': 'application/json' } },
						),
					);
				}
				return Promise.resolve(
					new Response(JSON.stringify({ jsonrpc: '2.0', id: 9, result: {} }), {
						headers: { 'content-type': 'application/json' },
					}),
				);
			},
		});

		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const instructions = (server as any).server._instructions as string | undefined;
		expect(instructions).toBeTruthy();
		expect(instructions).toContain('stonewright-setup-profile');
		expect(instructions).toContain('--- WordPress plugin instructions ---');
		expect(instructions).toContain('First call MCP tool stonewright-task-start');
	});

	it('keeps companion-only instructions when WordPress is unreachable', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'plugin',
				STONEWRIGHT_MCP_URL: 'https://down.example/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: () => Promise.reject(new Error('ECONNREFUSED')),
		});

		// eslint-disable-next-line @typescript-eslint/no-explicit-any, @typescript-eslint/no-unsafe-member-access -- SDK internals
		const instructions = (server as any).server._instructions as string | undefined;
		expect(instructions).toContain('stonewright-setup-profile');
		expect(instructions).not.toContain('--- WordPress plugin instructions ---');
	});
});
