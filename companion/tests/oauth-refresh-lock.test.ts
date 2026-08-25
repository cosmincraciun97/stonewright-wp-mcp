import { describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { OAuthRefreshLock } from '../src/oauth-refresh-lock.js';
import { OAuthTokenManager, OAuthTokenStore, type OAuthTokenSet } from '../src/oauth-token-manager.js';

function expiredTokens(): OAuthTokenSet {
	return { accessToken: 'old-access', refreshToken: 'old-refresh', expiresAt: 0, tokenType: 'Bearer' };
}

function makeResponse(payload: Record<string, unknown>, status = 200, headers: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(payload), { status, headers: { 'content-type': 'application/json', ...headers } });
}

describe('OAuthRefreshLock', () => {
	it('allows one refresh across two managers sharing a store', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-lock-'));
		try {
			const path = join(directory, 'tokens.json');
			const storeA = new OAuthTokenStore(path);
			const storeB = new OAuthTokenStore(path);
			storeA.save(expiredTokens());
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await new Promise((r) => setTimeout(r, 40));
				calls += 1;
				return makeResponse({
					access_token: 'shared-access',
					refresh_token: 'shared-refresh',
					expires_in: 3600,
					refresh_token_expires_in: 1_209_600,
				});
			};
			const managerA = new OAuthTokenManager(storeA, { random: () => 0 });
			const managerB = new OAuthTokenManager(storeB, { random: () => 0 });
			const [left, right] = await Promise.all([
				managerA.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-a', 'https://example.test/mcp'),
				managerB.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-a', 'https://example.test/mcp'),
			]);
			expect(calls).toBe(1);
			expect(left).toBe(right);
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});

	it('refuses a symlink token store on load', () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-symlink-'));
		try {
			const real = join(directory, 'real.json');
			const link = join(directory, 'link.json');
			writeFileSync(real, JSON.stringify(expiredTokens()));
			try {
				// eslint-disable-next-line @typescript-eslint/no-require-imports
				require('node:fs').symlinkSync(real, link);
			} catch {
				// platforms without symlink support
				return;
			}
			const store = new OAuthTokenStore(link);
			expect(() => store.load()).toThrow(/symlink/i);
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});

describe('consumption-aware refresh', () => {
	it('retries HTTP 503 only when X-Stonewright-Refresh-Consumed is 0', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-consumed-'));
		try {
			const path = join(directory, 'tokens.json');
			const store = new OAuthTokenStore(path);
			store.save(expiredTokens());
			let calls = 0;
			const manager = new OAuthTokenManager(store, { random: () => 0, baseBackoffMs: 1, sleep: async () => undefined });
			const token = await manager.getAccessToken(
				async () => {
					calls += 1;
					if (calls === 1) {
						return makeResponse({ error: 'temporarily_unavailable' }, 503, {
							'X-Stonewright-Refresh-Consumed': '0',
							'Retry-After': '0',
						});
					}
					return makeResponse({
						access_token: 'new-access',
						refresh_token: 'new-refresh',
						expires_in: 60,
					});
				},
				'https://example.test/oauth/token',
				'client-a',
				'https://example.test/mcp',
			);
			expect(token).toBe('new-access');
			expect(calls).toBe(2);
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});

	it('does not retry ECONNRESET', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-reset-'));
		try {
			const path = join(directory, 'tokens.json');
			const store = new OAuthTokenStore(path);
			store.save(expiredTokens());
			const manager = new OAuthTokenManager(store, { random: () => 0 });
			await expect(
				manager.getAccessToken(
					async () => {
						throw Object.assign(new TypeError('fetch failed'), { cause: { code: 'ECONNRESET' } });
					},
					'https://example.test/oauth/token',
					'client-a',
					'https://example.test/mcp',
				),
			).rejects.toMatchObject({ code: 'reauthentication_required', reasonCode: 'refresh_outcome_unknown' });
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});

describe('OAuthRefreshLock unit', () => {
	it('acquires and releases a lease', async () => {
		const directory = mkdtempSync(join(tmpdir(), 'stonewright-lock-unit-'));
		try {
			const lock = new OAuthRefreshLock(join(directory, 'tokens.json'));
			const lease = await lock.acquire(1_000);
			await lease.release();
		} finally {
			rmSync(directory, { recursive: true, force: true });
		}
	});
});
