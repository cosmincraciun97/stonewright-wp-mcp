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
		expect(info.version).toBe('1.0.0-alpha.35');
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

	it('returns compact text content for MCP tool responses', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_WP_CLI_BIN: 'missing-stonewright-wp',
			},
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const tool = tools['stonewright-wp-cli-status'];
		expect(tool?.handler).toBeTypeOf('function');

		const response = await tool.handler?.({ cwd: process.cwd() }) as { content: Array<{ text: string }> };

		expect(response.content[0]?.text).not.toContain('\n  "');
		expect(response.content[0]?.text).toMatch(/^\{"ok":/);
	});

	it('registers proxied WordPress MCP tools when endpoint env is configured', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{
					name: 'stonewright-context-bootstrap',
					description: 'Bootstrap agent context.',
					inputSchema: { type: 'object', properties: { task: { type: 'string' } } },
				},
			]),
		});

		expect(registeredToolNames(server)).toContain('stonewright-context-bootstrap');
		expect(registeredPromptNames(server)).toContain('stonewright-skill-figma-quality-rules');
	});

	it('keeps local setup and WP-CLI tools available when the WordPress MCP endpoint fails', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: () => Promise.reject(new Error('network down')),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wp-cli-status',
			'stonewright-wp-cli-batch-run',
			'stonewright-wordpress-mcp-status',
		]));
		expect(names).not.toContain('stonewright-context-bootstrap');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				ok?: boolean;
				connected?: boolean;
				error?: { message?: string };
				recovery?: string[];
			};
		};

		expect(response.structuredContent?.ok).toBe(false);
		expect(response.structuredContent?.connected).toBe(false);
		expect(response.structuredContent?.error?.message).toContain('network down');
		expect(response.structuredContent?.recovery).toContain('Verify STONEWRIGHT_WP_URL or STONEWRIGHT_MCP_URL points to /wp-json/mcp/stonewright.');
	});

	it('keeps local tools available when WordPress MCP config resolution fails', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_WP_URL: 'not a url',
			},
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-setup-profile',
			'stonewright-wordpress-mcp-status',
			'stonewright-wp-cli-status',
		]));
		expect(names).not.toContain('stonewright-context-bootstrap');

		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const response = await tools['stonewright-wordpress-mcp-status']?.handler?.({}) as {
			structuredContent?: {
				ok?: boolean;
				configured?: boolean;
				connected?: boolean;
				error?: { message?: string };
			};
		};

		expect(response.structuredContent?.ok).toBe(false);
		expect(response.structuredContent?.configured).toBe(true);
		expect(response.structuredContent?.connected).toBe(false);
		expect(response.structuredContent?.error?.message).toMatch(/Invalid URL|Invalid WordPress MCP URL/);
	});

	it('filters proxied WordPress MCP tools to the configured compact profile before registration', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor-design',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-elementor-v3-batch-mutate' },
				{ name: 'stonewright-wp-cli-batch-run' },
				{ name: 'stonewright-sandbox-write' },
				{ name: 'stonewright-memory-list' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-design-implementation-contract',
			'stonewright-elementor-v3-build-page-from-spec',
			'stonewright-elementor-v3-batch-mutate',
			'stonewright-wp-cli-batch-run',
		]));
		expect(names).not.toContain('stonewright-sandbox-write');
		expect(names).not.toContain('stonewright-memory-list');
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});

	it('keeps design fast-path tools in the essential proxied profile', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-security-create-one-time-link' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-media-list' },
				{ name: 'stonewright-media-upload' },
				{ name: 'stonewright-media-upload-batch' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-content-bulk-upsert-posts' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-security-create-one-time-link',
			'stonewright-design-implementation-contract',
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-elementor-v3-build-page-from-spec',
			'stonewright-content-bulk-upsert-posts',
		]));
		expect(names).not.toContain('stonewright-media-upload');
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});

	it('keeps media discovery visible in Elementor and content-model proxied profiles', async () => {
		const tools = [
			{ name: 'stonewright-context-bootstrap' },
			{ name: 'stonewright-workflow-preflight' },
			{ name: 'stonewright-tool-profile' },
			{ name: 'stonewright-media-list' },
			{ name: 'stonewright-media-upload-batch' },
			{ name: 'stonewright-content-bulk-upsert-posts' },
			{ name: 'stonewright-elementor-v3-build-page-from-spec' },
			{ name: 'stonewright-experimental-heavy-tool' },
		];

		const elementorServer = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'elementor-design',
			},
			fetchImpl: stonewrightMcpFetch(tools),
		});
		const contentServer = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MCP_TOOL_PROFILE: 'content-model',
			},
			fetchImpl: stonewrightMcpFetch(tools),
		});

		expect(registeredToolNames(elementorServer)).toEqual(expect.arrayContaining([
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-elementor-v3-build-page-from-spec',
		]));
		expect(registeredToolNames(contentServer)).toEqual(expect.arrayContaining([
			'stonewright-media-list',
			'stonewright-media-upload-batch',
			'stonewright-content-bulk-upsert-posts',
		]));
	});

	it('defaults proxied WordPress MCP tools to the essential profile', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
			},
			fetchImpl: stonewrightMcpFetch([
				{ name: 'stonewright-context-bootstrap' },
				{ name: 'stonewright-workflow-preflight' },
				{ name: 'stonewright-tool-profile' },
				{ name: 'stonewright-design-implementation-contract' },
				{ name: 'stonewright-elementor-v3-build-page-from-spec' },
				{ name: 'stonewright-experimental-heavy-tool' },
			]),
		});

		const names = registeredToolNames(server);

		expect(names).toEqual(expect.arrayContaining([
			'stonewright-context-bootstrap',
			'stonewright-workflow-preflight',
			'stonewright-tool-profile',
			'stonewright-design-implementation-contract',
			'stonewright-elementor-v3-build-page-from-spec',
		]));
		expect(names).not.toContain('stonewright-experimental-heavy-tool');
	});
});

function registeredToolNames(server: unknown): string[] {
	return Object.keys((server as { _registeredTools?: Record<string, unknown> })._registeredTools ?? {});
}

function registeredPromptNames(server: unknown): string[] {
	return Object.keys((server as { _registeredPrompts?: Record<string, unknown> })._registeredPrompts ?? {});
}

function stonewrightMcpFetch(tools: Array<{ name: string; description?: string; inputSchema?: Record<string, unknown> }>): typeof fetch {
	return (_url: string | URL | Request, init?: RequestInit): Promise<Response> => {
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
						tools: tools.map((tool) => ({
							description: 'Proxied Stonewright test tool.',
							inputSchema: { type: 'object', properties: {} },
							...tool,
						})),
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
}
