/**
 * HTTP body-size limit smoke test.
 *
 * Boots the real companion HTTP server (via the exported `startHttp`) with
 * a small body limit, POSTs a body that exceeds the limit, and asserts the
 * server responds 413 instead of buffering forever. We also POST a small
 * body to confirm the limit only kicks in on oversized requests.
 */

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { startHttp } from '../src/index.js';

describe('HTTP body limit', () => {
	let server: Awaited<ReturnType<typeof startHttp>>;
	let baseUrl: string;
	let bearer: string;
	let origin: string;

	beforeAll(async () => {
		bearer = 'test-bearer-' + Math.random().toString(36).slice(2);
		origin = 'http://localhost.test';

		process.env['COMPANION_BEARER_TOKEN'] = bearer;
		process.env['COMPANION_ALLOWED_ORIGINS'] = origin;
		process.env['COMPANION_MAX_BODY_BYTES'] = '1024'; // 1 KB
		process.env['COMPANION_BIND_HOST'] = '127.0.0.1';

		server = await startHttp(0);
		const addr = server.address();
		if (addr && typeof addr === 'object') {
			baseUrl = `http://127.0.0.1:${addr.port}`;
		} else {
			throw new Error('HTTP server did not return a port');
		}
	});

	afterAll(async () => {
		await server.close();
		delete process.env['COMPANION_BEARER_TOKEN'];
		delete process.env['COMPANION_ALLOWED_ORIGINS'];
		delete process.env['COMPANION_MAX_BODY_BYTES'];
		delete process.env['COMPANION_BIND_HOST'];
	});

	it('rejects POST /mcp bodies larger than the limit with 413', async () => {
		const payload = 'a'.repeat(4096); // 4 KB ≫ 1 KB cap
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${bearer}`,
				Origin: origin,
				Accept: 'application/json',
			},
			body: payload,
		});

		expect(res.status).toBe(413);
		const body = await res.json() as Record<string, unknown>;
		expect(body['error']).toBe('Payload too large');
		expect(body['max_bytes']).toBe(1024);
	});

	it('accepts requests under the limit (auth pipeline still runs)', async () => {
		// A small malformed body should NOT be rejected with 413. We don't
		// care whether MCP accepts the message — just that the body-limit
		// step did not fire. Invalid JSON triggers a 400 from the entry-point
		// JSON parse step, which is fine.
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${bearer}`,
				Origin: origin,
				Accept: 'application/json',
			},
			body: '{}', // empty JSON object — well below 1 KB
		});

		expect(res.status).not.toBe(413);
	});

	it('serves /health without auth even at startup', async () => {
		const res = await fetch(`${baseUrl}/health`);
		expect(res.status).toBe(200);
	});

	it('rejects mismatched origin with 403', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${bearer}`,
				Origin: 'http://evil.example',
				Accept: 'application/json',
			},
			body: '{}',
		});
		expect(res.status).toBe(403);
	});

	it('rejects missing bearer with 401', async () => {
		const res = await fetch(`${baseUrl}/mcp`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Origin: origin,
				Accept: 'application/json',
			},
			body: '{}',
		});
		expect(res.status).toBe(401);
	});
});
