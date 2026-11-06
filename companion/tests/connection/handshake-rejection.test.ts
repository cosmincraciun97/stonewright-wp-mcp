import { describe, expect, it, vi } from 'vitest';
import { createMcpServer } from '../../src/mcp-server.js';

// A plugin route that answers initialize but cannot finish the MCP handshake works and is
// authenticated: the status and the doctor must say the handshake failed (and where to look),
// never that the plugin is missing.

type ToolHandlers = Record<string, { handler?: (input: unknown) => Promise<unknown> }>;

interface StatusContent {
	connected?: boolean;
	error_code?: string | null;
	next_action?: string | null;
	error?: { message?: string } | null;
}

function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });
}

/** The plugin route is present (401 to a bare probe); `answer` handles the handshake POSTs. */
function pluginRoute(answer: (payload: { id?: number; method?: string }) => Response): typeof fetch {
	return vi.fn((input: string | URL | Request, init?: RequestInit) => {
		const url = String(input);
		if (!url.includes('/mcp/')) {
			return Promise.resolve(jsonResponse({ namespaces: ['mcp', 'stonewright/v1'] }));
		}
		if ((init?.method ?? 'GET') !== 'POST') {
			return Promise.resolve(new Response('', { status: 401 }));
		}
		return Promise.resolve(answer(JSON.parse(String(init?.body ?? '{}')) as { id?: number; method?: string }));
	}) as unknown as typeof fetch;
}

async function startPluginOnly(fetchImpl: typeof fetch): Promise<ToolHandlers> {
	const server = await createMcpServer({
		env: {
			STONEWRIGHT_MODE: 'plugin',
			STONEWRIGHT_WP_URL: 'https://example.test',
			WP_API_USERNAME: 'admin',
			WP_API_PASSWORD: 'pw',
		},
		fetchImpl,
	});
	return (server as { _registeredTools?: ToolHandlers })._registeredTools ?? {};
}

async function structured(tools: ToolHandlers, name: string): Promise<StatusContent> {
	const response = await tools[name]?.handler?.({}) as { structuredContent?: StatusContent };
	return response.structuredContent ?? {};
}

describe('a plugin route that refuses the MCP handshake', () => {
	it('reports plugin_handshake_rejected and points at the MCP runtime, not at a missing plugin', async () => {
		const tools = await startPluginOnly(pluginRoute((payload) => (payload.method === 'initialize'
			? jsonResponse({ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2025-06-18' } }, 200, { 'mcp-session-id': 'session-1' })
			: new Response(null, { status: 400 }))));

		const status = await structured(tools, 'stonewright-wordpress-mcp-status');
		const doctor = await structured(tools, 'stonewright-connect-doctor');

		expect(status.connected).toBe(false);
		expect(status.error_code).toBe('plugin_handshake_rejected');
		expect(status.error?.message).toContain('refused notifications/initialized');
		expect(status.next_action).toContain('MCP runtime');
		expect(doctor.error_code).toBe('plugin_handshake_rejected');
		expect(doctor.next_action).toContain('MCP runtime');
		expect(doctor.next_action).not.toContain('Install/enable');
	});

	it('reports protocol_version_unsupported when the route picks a protocol the companion does not speak', async () => {
		const tools = await startPluginOnly(pluginRoute((payload) => jsonResponse(
			{ jsonrpc: '2.0', id: payload.id, result: { protocolVersion: '2099-01-01' } },
			200,
			{ 'mcp-session-id': 'session-1' },
		)));

		const status = await structured(tools, 'stonewright-wordpress-mcp-status');
		const doctor = await structured(tools, 'stonewright-connect-doctor');

		expect(status.error_code).toBe('protocol_version_unsupported');
		expect(status.error?.message).toContain('2099-01-01');
		expect(status.next_action).toContain('Update the companion');
		expect(doctor.error_code).toBe('protocol_version_unsupported');
		expect(doctor.next_action).not.toContain('Install/enable');
	});
});
