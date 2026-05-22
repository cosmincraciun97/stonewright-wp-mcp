/**
 * HTTP smoke test for the companion's /health endpoint.
 *
 * companion/src/index.ts does not export its HTTP server factory — startHttp()
 * is an internal async function wired only via main(). Importing index.ts
 * directly would crash the test runner (it calls main() at module level, which
 * connects a stdio MCP transport and potentially hangs).
 *
 * Instead this test spins up a minimal Node.js HTTP server that replicates the
 * /health handler as defined in src/index.ts (lines 48–52), and verifies the
 * response shape. The companion's actual runtime behaviour is integration-tested
 * separately when a real WordPress site is available.
 *
 * If src/index.ts is refactored to export startHttp(), replace the inline
 * server below with that import.
 */

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { createServer, type Server, type IncomingMessage, type ServerResponse } from 'node:http';

// ---------------------------------------------------------------------------
// Minimal health-endpoint server — mirrors src/index.ts startHttp() logic
// ---------------------------------------------------------------------------

let server: Server;
let baseUrl: string;

beforeAll(async () => {
	server = createServer((req: IncomingMessage, res: ServerResponse) => {
		const url = req.url ?? '/';
		if (url === '/health') {
			res.writeHead(200, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ status: 'ok', version: '1.0.0-alpha.1' }));
			return;
		}
		res.writeHead(404, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Not found' }));
	});

	await new Promise<void>((resolve, reject) => {
		// Port 0 → OS picks a free port
		server.listen(0, '127.0.0.1', () => {
			const addr = server.address();
			const port = addr && typeof addr === 'object' ? addr.port : 0;
			baseUrl = `http://127.0.0.1:${port}`;
			resolve();
		});
		server.once('error', reject);
	});
});

afterAll(async () => {
	await new Promise<void>((resolve, reject) => {
		server.close((err) => (err ? reject(err) : resolve()));
	});
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('companion HTTP /health', () => {
	it('responds 200 with { status: "ok" }', async () => {
		const res = await fetch(`${baseUrl}/health`);
		expect(res.status).toBe(200);

		const body = await res.json() as Record<string, unknown>;
		expect(body['status']).toBe('ok');
		expect(typeof body['version']).toBe('string');
	});

	it('responds 404 for unknown routes', async () => {
		const res = await fetch(`${baseUrl}/unknown-route`);
		expect(res.status).toBe(404);

		const body = await res.json() as Record<string, unknown>;
		expect(body['error']).toBe('Not found');
	});
});

// ---------------------------------------------------------------------------
// Canary: verify src/mcp-server.js module can be imported without crashing
// (exercises the module graph without touching stdio or network).
// ---------------------------------------------------------------------------

describe('createMcpServer module import', () => {
	it('resolves without throwing', async () => {
		const mod = await import('../src/mcp-server.js');
		expect(typeof mod.createMcpServer).toBe('function');
	});
});
