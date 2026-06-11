import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { startHttp } from '../src/index.js';

describe('WP-CLI HTTP endpoints', () => {
	let server: Awaited<ReturnType<typeof startHttp>>;
	let baseUrl: string;
	const bearer = 'wp-cli-http-token';
	const origin = 'http://localhost.test';

	beforeAll(async () => {
		process.env['COMPANION_BEARER_TOKEN'] = bearer;
		process.env['COMPANION_ALLOWED_ORIGINS'] = origin;
		process.env['COMPANION_BIND_HOST'] = '127.0.0.1';
		process.env['COMPANION_RATE_LIMIT_RPS'] = '0';

		server = await startHttp(0);
		const addr = server.address();
		if (!addr || typeof addr !== 'object') throw new Error('HTTP server did not return an address');
		baseUrl = `http://127.0.0.1:${addr.port}`;
	});

	afterAll(async () => {
		await server.close();
		delete process.env['COMPANION_BEARER_TOKEN'];
		delete process.env['COMPANION_ALLOWED_ORIGINS'];
		delete process.env['COMPANION_BIND_HOST'];
		delete process.env['COMPANION_RATE_LIMIT_RPS'];
	});

	it('rejects blocked WP-CLI commands before spawning a process', async () => {
		const res = await fetch(`${baseUrl}/wp-cli/run`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${bearer}`,
				Origin: origin,
			},
			body: JSON.stringify({ command: ['eval', 'echo 1;'] }),
		});

		expect(res.status).toBe(400);
		const body = await res.json() as Record<string, unknown>;
		expect(String(body['error'])).toMatch(/blocked/i);
	});

	it('rejects blocked WP-CLI commands in batch requests before spawning a process', async () => {
		const res = await fetch(`${baseUrl}/wp-cli/batch`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Authorization: `Bearer ${bearer}`,
				Origin: origin,
			},
			body: JSON.stringify({ commands: [['eval', 'echo 1;'], ['post', 'list']] }),
		});

		expect(res.status).toBe(400);
		const body = await res.json() as Record<string, unknown>;
		expect(String(body['error'])).toMatch(/blocked/i);
	});
});
