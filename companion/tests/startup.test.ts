import { describe, expect, it } from 'vitest';
import { startOptionalHttpFromEnv } from '../src/index.js';

describe('companion startup', () => {
	it('ignores PORT by default so stdio MCP startup is not blocked by a stale .env port', async () => {
		const error = new Error('listen EADDRINUSE: address already in use 127.0.0.1:8765') as Error & { code?: string };
		error.code = 'EADDRINUSE';

		const result = await startOptionalHttpFromEnv(
			{ PORT: '8765' },
			() => Promise.reject(error),
		);

		expect(result).toMatchObject({
			requested: false,
			started: false,
			port: null,
		});
		expect(result.error).toBeUndefined();
	});

	it('starts optional HTTP only when explicitly enabled', async () => {
		const result = await startOptionalHttpFromEnv(
			{ PORT: '8765', STONEWRIGHT_HTTP_ENABLE: '1' },
			() => Promise.resolve({
				close: () => Promise.resolve(),
				address: () => null,
				config: {
					bearerToken: '',
					allowedOrigins: [],
					devInsecure: true,
					bindHost: '127.0.0.1',
					maxBodyBytes: 1024,
				},
			}),
		);

		expect(result).toMatchObject({
			requested: true,
			started: true,
			port: 8765,
		});
	});

	it('still throws optional HTTP startup errors when explicitly required', async () => {
		await expect(startOptionalHttpFromEnv(
			{ PORT: '8765', STONEWRIGHT_HTTP_REQUIRED: '1' },
			() => Promise.reject(new Error('required bridge failed')),
		)).rejects.toThrow('required bridge failed');
	});
});
