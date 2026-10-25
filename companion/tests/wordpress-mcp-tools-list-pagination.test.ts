import { describe, expect, it } from 'vitest';
import { runDoctorChecks } from '../src/cli/doctor.js';
import { createMcpServer } from '../src/mcp-server.js';
import { PluginTransportError } from '../src/connection/transport-diagnostic.js';
import { WordPressMcpClient, WordPressMcpHttpError, type WordPressMcpConfig } from '../src/wordpress-mcp.js';

// tools/list can be larger than the 1 MiB the companion reads from one body. The plugin
// pages it with MCP cursors; the companion follows nextCursor, keeps the read cap as a
// safety bound, and names a body over the cap instead of reporting "HTTP 200".

interface RpcPayload {
	id?: number;
	method?: string;
	params?: { cursor?: string };
}

type Page = { tools: Array<{ name: string }>; nextCursor?: string };

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const basicConfig: WordPressMcpConfig = {
	url: 'https://example.com/wp-json/mcp/stonewright',
	timeoutMs: 5_000,
	username: 'fixture-admin',
	password: 'fixture-password',
};

/** Answers the handshake and lets `onList` answer every tools/list. */
function mcpServer(onList: (payload: RpcPayload) => Response) {
	const lists: RpcPayload[] = [];
	const fetchImpl: typeof fetch = async (_input, init) => {
		await Promise.resolve();
		const payload = JSON.parse(String(init?.body ?? '{}')) as RpcPayload;
		if (payload.method === 'notifications/initialized') return new Response('', { status: 202 });
		if (payload.method === 'initialize') return json({ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2025-06-18' } });
		lists.push(payload);
		return onList(payload);
	};
	return { lists, fetchImpl };
}

function pagedServer(pages: Record<string, Page>) {
	return mcpServer((payload) => {
		const page = pages[payload.params?.cursor ?? ''];
		if (!page) return json({ jsonrpc: '2.0', id: payload.id, error: { code: -32602, message: 'Invalid cursor' } });
		return json({ jsonrpc: '2.0', id: payload.id, result: page });
	});
}

function names(tools: Array<{ name: string }>): string[] {
	return tools.map((tool) => tool.name);
}

describe('tools/list pagination in the WordPress MCP client', () => {
	it('follows nextCursor until the last page and merges the tools in order', async () => {
		const server = pagedServer({
			'': { tools: [{ name: 'tool-a' }, { name: 'tool-b' }], nextCursor: 'c1' },
			c1: { tools: [{ name: 'tool-c' }], nextCursor: 'c2' },
			c2: { tools: [{ name: 'tool-d' }, { name: 'tool-e' }] },
		});
		const client = new WordPressMcpClient(basicConfig, server.fetchImpl);

		const tools = await client.listTools();

		expect(names(tools)).toEqual(['tool-a', 'tool-b', 'tool-c', 'tool-d', 'tool-e']);
		expect(server.lists.map((payload) => payload.params?.cursor)).toEqual([undefined, 'c1', 'c2']);
		expect(client.lastToolsListPages).toBe(3);
		expect(client.lastToolsListBytes).toBeGreaterThan(0);
	});

	it('keeps working with single-page servers that send no cursor', async () => {
		const server = pagedServer({ '': { tools: [{ name: 'tool-a' }, { name: 'tool-b' }] } });
		const client = new WordPressMcpClient(basicConfig, server.fetchImpl);

		const tools = await client.listTools();

		expect(names(tools)).toEqual(['tool-a', 'tool-b']);
		expect(server.lists).toHaveLength(1);
		expect(server.lists[0]?.params).toEqual({});
		expect(client.lastToolsListPages).toBe(1);
	});

	it('treats an empty nextCursor as the last page', async () => {
		const server = pagedServer({ '': { tools: [{ name: 'tool-a' }], nextCursor: '' } });

		const tools = await new WordPressMcpClient(basicConfig, server.fetchImpl).listTools();

		expect(names(tools)).toEqual(['tool-a']);
		expect(server.lists).toHaveLength(1);
	});

	it('stops with a transport error on a repeated cursor', async () => {
		const server = pagedServer({
			'': { tools: [{ name: 'tool-a' }], nextCursor: 'loop' },
			loop: { tools: [{ name: 'tool-b' }], nextCursor: 'loop' },
		});

		const failure = await new WordPressMcpClient(basicConfig, server.fetchImpl).listTools().catch((error: unknown) => error);

		expect(failure).toBeInstanceOf(PluginTransportError);
		expect((failure as PluginTransportError).message).toBe('tools/list cursor loop during tools_list');
		expect((failure as PluginTransportError).diagnostic.phase).toBe('tools_list');
		expect(server.lists).toHaveLength(2);
	});

	it('stops with a transport error when a server never stops paging', async () => {
		let page = 0;
		const server = mcpServer((payload) => json({
			jsonrpc: '2.0',
			id: payload.id,
			result: { tools: [{ name: `tool-${++page}` }], nextCursor: `cursor-${page}` },
		}));

		const failure = await new WordPressMcpClient(basicConfig, server.fetchImpl).listTools().catch((error: unknown) => error);

		expect(failure).toBeInstanceOf(PluginTransportError);
		expect((failure as PluginTransportError).message).toBe('tools/list cursor loop during tools_list');
		expect(server.lists).toHaveLength(64);
	});

	it('reports a JSON-RPC error on a later page as a failed listing', async () => {
		const server = pagedServer({ '': { tools: [{ name: 'tool-a' }], nextCursor: 'stale' } });

		await expect(new WordPressMcpClient(basicConfig, server.fetchImpl).listTools()).rejects.toThrow('JSON-RPC error during tools_list');
	});

	it('a truncated tools/list body reports the read cap, not HTTP 200', async () => {
		const oversized = JSON.stringify({
			jsonrpc: '2.0',
			id: 1,
			result: { tools: [{ name: 'tool-a', description: 'x'.repeat(1_100_000) }] },
		});
		const server = mcpServer(() => new Response(oversized, { status: 200, headers: { 'content-type': 'application/json' } }));

		const failure = await new WordPressMcpClient(basicConfig, server.fetchImpl).listTools().catch((error: unknown) => error);

		expect(failure).toBeInstanceOf(WordPressMcpHttpError);
		const error = failure as WordPressMcpHttpError;
		expect(error.message).toBe('WordPress MCP response over the 1 MiB read cap during tools_list');
		expect(error.message).not.toContain('HTTP 200');
		expect(error.status).toBe(200);
		expect(error.truncated).toBe(true);
		expect(error.diagnostic).toMatchObject({
			kind: 'http_error',
			phase: 'tools_list',
			safe_cause_code: 'response_truncated',
			http_status: 200,
			retryable: false,
		});
	});
});

describe('tools/list pages and bytes in the doctor', () => {
	const env = {
		PATH: '/usr/bin',
		STONEWRIGHT_WP_URL: 'https://example.test',
		STONEWRIGHT_WP_USERNAME: 'admin',
		STONEWRIGHT_WP_APP_PASSWORD: 'test-doctor-app-password',
	};

	function doctorFetch(onList: (payload: RpcPayload) => Response): typeof fetch {
		return ((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = String(input);
			if (/\/wp-json\/?$/.test(url)) {
				return Promise.resolve(json({ namespaces: ['wp/v2', 'mcp'] }));
			}
			if (url.includes('/users/me')) {
				return Promise.resolve(json({ name: 'Admin' }));
			}
			if (url.includes('/mcp/stonewright')) {
				const payload = JSON.parse(String(init?.body ?? '{}')) as RpcPayload;
				if (payload.method === 'tools/list') return Promise.resolve(onList(payload));
				if (payload.method === 'notifications/initialized') return Promise.resolve(new Response('', { status: 202 }));
				return Promise.resolve(json({ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2025-06-18', serverInfo: { name: 'stonewright' } } }));
			}
			return Promise.resolve(new Response('not found', { status: 404 }));
		}) as typeof fetch;
	}

	it('reports tools/list pages and bytes in the doctor', async () => {
		const pages: Record<string, Page> = {
			'': { tools: [{ name: 'tool-a' }], nextCursor: 'c1' },
			c1: { tools: [{ name: 'tool-b' }] },
		};
		const report = await runDoctorChecks({
			nodeVersion: 'v22.0.0',
			env,
			fetchImpl: doctorFetch((payload) => json({ jsonrpc: '2.0', id: payload.id, result: pages[payload.params?.cursor ?? ''] })),
		});

		const check = report.checks.find((candidate) => candidate.id === 'mcp_tools_list');
		expect(check?.status).toBe('passed');
		expect(check?.tools_list_pages).toBe(2);
		expect(check?.tools_list_bytes).toBeGreaterThan(0);
		expect(check?.detail).toContain('2 tools');
		expect(check?.detail).toContain('2 pages');
		expect(report.ok).toBe(true);
	});

	it('explains a tools/list body over the read cap with the fix line', async () => {
		const oversized = JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: [{ name: 'tool-a', description: 'x'.repeat(1_100_000) }] } });
		const report = await runDoctorChecks({
			nodeVersion: 'v22.0.0',
			env,
			fetchImpl: doctorFetch(() => new Response(oversized, { status: 200, headers: { 'content-type': 'application/json' } })),
		});

		const check = report.checks.find((candidate) => candidate.id === 'mcp_tools_list');
		expect(check?.status).toBe('failed');
		expect(check?.fix).toBe('the plugin answered tools/list in one page over 1 MiB; update the plugin to a release that paginates tools/list');
		expect(check?.detail).not.toContain('HTTP 200');
		expect(report.ok).toBe(false);
	});

	it('does not ask for tools/list when the handshake failed', async () => {
		const report = await runDoctorChecks({
			nodeVersion: 'v22.0.0',
			env,
			fetchImpl: ((input: Parameters<typeof fetch>[0]) => {
				const url = String(input);
				if (/\/wp-json\/?$/.test(url)) return Promise.resolve(json({ namespaces: ['wp/v2'] }));
				if (url.includes('/users/me')) return Promise.resolve(json({ name: 'Admin' }));
				return Promise.resolve(new Response('nope', { status: 500 }));
			}) as typeof fetch,
		});

		expect(report.checks.find((candidate) => candidate.id === 'mcp_tools_list')?.status).toBe('skipped');
	});
});

describe('tools/list pagination in the stonewright-connect-doctor tool', () => {
	type DoctorHandlerResult = {
		structuredContent?: {
			error_code?: string | null;
			next_action?: string;
			diagnosis?: { tools_list_pages?: number | null; tools_list_bytes?: number | null; transport_cause_code?: string | null };
		};
	};

	async function connectDoctor(fetchImpl: typeof fetch): Promise<DoctorHandlerResult> {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MCP_URL: 'https://example.com/wp-json/mcp/stonewright',
				WP_API_USERNAME: 'admin',
				WP_API_PASSWORD: 'pw',
				STONEWRIGHT_MODE: 'plugin',
			},
			fetchImpl,
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (input: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		return await tools['stonewright-connect-doctor']?.handler?.({}) as DoctorHandlerResult;
	}

	it('reports tools/list pages and bytes of a paged surface', async () => {
		const server = pagedServer({
			'': { tools: [{ name: 'stonewright-task-start' }], nextCursor: 'c1' },
			c1: { tools: [{ name: 'stonewright-tool-profile' }] },
		});

		const response = await connectDoctor(server.fetchImpl);

		expect(response.structuredContent?.diagnosis?.tools_list_pages).toBe(2);
		expect(response.structuredContent?.diagnosis?.tools_list_bytes).toBeGreaterThan(0);
	});

	it('names a tools/list body over the read cap and gives the fix', async () => {
		const oversized = JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: [{ name: 'tool-a', description: 'x'.repeat(1_100_000) }] } });
		const server = mcpServer(() => new Response(oversized, { status: 200, headers: { 'content-type': 'application/json' } }));

		const response = await connectDoctor(server.fetchImpl);

		expect(response.structuredContent?.error_code).toBe('tools_list_response_truncated');
		expect(response.structuredContent?.next_action).toBe('the plugin answered tools/list in one page over 1 MiB; update the plugin to a release that paginates tools/list');
		expect(response.structuredContent?.diagnosis?.transport_cause_code).toBe('response_truncated');
	});
});
