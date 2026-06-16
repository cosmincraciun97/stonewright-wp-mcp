import { describe, expect, it } from 'vitest';
import { startOptionalHttpFromEnv } from '../src/index.js';

describe('companion startup', () => {
	it('keeps stdio alive when optional HTTP port is already in use', async () => {
		const error = new Error('listen EADDRINUSE: address already in use 127.0.0.1:8765') as Error & { code?: string };
		error.code = 'EADDRINUSE';

		const result = await startOptionalHttpFromEnv(
			{ PORT: '8765' },
			async () => {
				throw error;
			},
		);

		expect(result).toMatchObject({
			requested: true,
			started: false,
			port: 8765,
			error: 'listen EADDRINUSE: address already in use 127.0.0.1:8765',
		});
	});

	it('still throws optional HTTP startup errors when explicitly required', async () => {
		await expect(startOptionalHttpFromEnv(
			{ PORT: '8765', STONEWRIGHT_HTTP_REQUIRED: '1' },
			async () => {
				throw new Error('required bridge failed');
			},
		)).rejects.toThrow('required bridge failed');
	});
});
