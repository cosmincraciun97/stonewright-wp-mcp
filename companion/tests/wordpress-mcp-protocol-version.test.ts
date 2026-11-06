import { describe, expect, it } from 'vitest';
import { WordPressMcpClient, type WordPressMcpConfig } from '../src/wordpress-mcp.js';
import { WordPressMcpHandshakeError } from '../src/wordpress-mcp-http.js';

// Streamable HTTP rules that stricter MCP adapters enforce (protocol 2025-06-18 and later):
// every request after initialize carries MCP-Protocol-Version with the negotiated version,
// and a 404 on a request that carried an Mcp-Session-Id means the server ended that session
// before running the request, so the client opens a new session and may send it again.

interface RpcPayload {
	id?: number;
	method?: string;
	params?: Record<string, unknown>;
}

interface Seen {
	method: string;
	session: string | null;
	protocol: string | null;
}

interface StrictServerOptions {
	/** The protocol version the server answers initialize with. */
	negotiated?: string;
	/** false: a server that issues no Mcp-Session-Id. */
	sessions?: boolean;
	/** Answers a request that passed the session and version checks; undefined falls through. */
	onCall?: (seen: Seen, payload: RpcPayload) => Response | undefined;
}

function json(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json', ...headers } });
}

function sessionEnded(id: number | undefined): Response {
	return json({ jsonrpc: '2.0', id: id ?? null, error: { code: -32001, message: 'Session not found' } }, 404);
}

/** A server that behaves like an MCP adapter enforcing the 2025-06-18 HTTP rules. */
function strictServer(options: StrictServerOptions = {}) {
	const negotiated = options.negotiated ?? '2025-06-18';
	const live = new Set<string>();
	const seen: Seen[] = [];
	let minted = 0;

	const fetchImpl: typeof fetch = async (_input, init) => {
		await Promise.resolve();
		const headers = new Headers(init?.headers);
		const payload = JSON.parse(String(init?.body ?? '{}')) as RpcPayload;
		const entry: Seen = {
			method: payload.method ?? '',
			session: headers.get('mcp-session-id'),
			protocol: headers.get('mcp-protocol-version'),
		};
		seen.push(entry);

		if (payload.method === 'initialize') {
			const extra: Record<string, string> = {};
			if (options.sessions !== false) {
				minted += 1;
				const id = `session-${minted}`;
				live.add(id);
				extra['mcp-session-id'] = id;
			}
			return json({
				jsonrpc: '2.0',
				id: payload.id,
				result: {
					protocolVersion: negotiated,
					capabilities: { tools: {} },
					serverInfo: { name: 'Stonewright', version: 'fixture' },
				},
			}, 200, extra);
		}

		if (options.sessions !== false && (entry.session === null || !live.has(entry.session))) {
			return sessionEnded(payload.id);
		}

		if (entry.protocol !== negotiated) {
			// A rejected notification is answered with an empty 400; a request gets a JSON-RPC error.
			return payload.id === undefined
				? new Response(null, { status: 400 })
				: json({
					jsonrpc: '2.0',
					id: payload.id,
					error: { code: -32600, message: `MCP-Protocol-Version header is required for a ${negotiated} session` },
				}, 400);
		}

		const custom = options.onCall?.(entry, payload);
		if (custom) return custom;
		if (payload.method === 'notifications/initialized') return new Response(null, { status: 202 });
		if (payload.method === 'tools/list') {
			return json({ jsonrpc: '2.0', id: payload.id, result: { tools: [{ name: 'stonewright-ping', inputSchema: { type: 'object' } }] } });
		}
		return json({ jsonrpc: '2.0', id: payload.id, result: { content: [{ type: 'text', text: 'ok' }] } });
	};

	return {
		seen,
		fetchImpl,
		end: (id: string) => {
			live.delete(id);
		},
		methods: () => seen.map((entry) => entry.method),
	};
}

const config: WordPressMcpConfig = {
	url: 'https://example.com/wp-json/mcp/stonewright',
	timeoutMs: 5_000,
	username: 'fixture-admin',
	password: 'fixture-password',
};

describe('MCP-Protocol-Version after initialize', () => {
	it('sends the negotiated version on every request after initialize, and not on initialize', async () => {
		const server = strictServer();

		const tools = await new WordPressMcpClient(config, server.fetchImpl).listTools();

		expect(tools.map((tool) => tool.name)).toEqual(['stonewright-ping']);
		expect(server.seen.map((entry) => [entry.method, entry.protocol])).toEqual([
			['initialize', null],
			['notifications/initialized', '2025-06-18'],
			['tools/list', '2025-06-18'],
		]);
	});

	it('uses the version the server chose when it differs from the one the companion asked for', async () => {
		const server = strictServer({ negotiated: '2025-03-26' });

		await new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-ping', {});

		expect(server.seen.slice(1).map((entry) => entry.protocol)).toEqual(['2025-03-26', '2025-03-26']);
	});

	it('stops with a clear handshake error when the server picks a version the companion does not support', async () => {
		const server = strictServer({ negotiated: '2099-01-01' });

		const failure = new WordPressMcpClient(config, server.fetchImpl).listTools();

		await expect(failure).rejects.toBeInstanceOf(WordPressMcpHandshakeError);
		await expect(failure).rejects.toMatchObject({ step: 'protocol_version', negotiatedVersion: '2099-01-01' });
		await expect(failure).rejects.toThrow(/2099-01-01/);
		expect(server.methods()).toEqual(['initialize']);
	});

	it('reports a refused notifications/initialized as a handshake error, not as a missing plugin', async () => {
		const server = strictServer({
			onCall: (entry) => (entry.method === 'notifications/initialized' ? new Response(null, { status: 400 }) : undefined),
		});

		const failure = new WordPressMcpClient(config, server.fetchImpl).listTools();

		await expect(failure).rejects.toBeInstanceOf(WordPressMcpHandshakeError);
		await expect(failure).rejects.toMatchObject({ step: 'initialized_notification', status: 400 });
		expect(server.methods()).toEqual(['initialize', 'notifications/initialized']);
	});
});

describe('a session the server ended', () => {
	it('opens a new session and sends the refused request once more', async () => {
		const server = strictServer();
		const client = new WordPressMcpClient(config, server.fetchImpl);
		await client.listTools();
		server.end('session-1');

		await expect(client.callTool('stonewright-ping', {})).resolves.toBeDefined();

		expect(server.seen.map((entry) => [entry.method, entry.session])).toEqual([
			['initialize', null],
			['notifications/initialized', 'session-1'],
			['tools/list', 'session-1'],
			['tools/call', 'session-1'],
			['initialize', null],
			['notifications/initialized', 'session-2'],
			['tools/call', 'session-2'],
		]);
	});

	it('shares one new session between requests that found the old one ended at the same time', async () => {
		const server = strictServer();
		const client = new WordPressMcpClient(config, server.fetchImpl);
		await client.listTools();
		server.end('session-1');

		await Promise.all([client.callTool('stonewright-ping', {}), client.callTool('stonewright-ping', {})]);

		expect(server.methods().filter((method) => method === 'initialize')).toHaveLength(2);
		expect(server.seen.filter((entry) => entry.method === 'tools/call').map((entry) => entry.session)).toEqual([
			'session-1',
			'session-1',
			'session-2',
			'session-2',
		]);
	});

	it('does not send a request a third time when the new session refuses it too', async () => {
		const server = strictServer({
			onCall: (entry, payload) => (entry.method === 'tools/call' ? sessionEnded(payload.id) : undefined),
		});

		await expect(new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-ping', {})).rejects.toThrow();

		expect(server.methods().filter((method) => method === 'tools/call')).toHaveLength(2);
		expect(server.methods().filter((method) => method === 'initialize')).toHaveLength(2);
	});

	it('does not treat a missing route (rest_no_route) as an ended session', async () => {
		const server = strictServer({
			onCall: (entry) => (entry.method === 'tools/call'
				? json({ code: 'rest_no_route', message: 'No route was found matching the URL and request method.' }, 404)
				: undefined),
		});

		await expect(new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-ping', {})).rejects.toThrow();

		expect(server.methods()).toEqual(['initialize', 'notifications/initialized', 'tools/call']);
	});

	it('does not resend after a 404 on a request that carried no session', async () => {
		const server = strictServer({
			sessions: false,
			onCall: (entry, payload) => (entry.method === 'tools/call' ? sessionEnded(payload.id) : undefined),
		});

		await expect(new WordPressMcpClient(config, server.fetchImpl).callTool('stonewright-ping', {})).rejects.toThrow();

		expect(server.methods()).toEqual(['initialize', 'notifications/initialized', 'tools/call']);
	});
});
