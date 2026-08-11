/**
 * Deterministic OAuth / proxy client matrix for companion token lifecycle.
 *
 * Covers discovery-adjacent resource binding in refresh bodies, refresh
 * rotation/replay, terminal reauth, JSON error shapes, and transient vs
 * terminal classification. Prefer these over live browser OAuth for CI.
 */
import { describe, expect, it } from 'vitest';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	OAuthReauthRequiredError,
	OAuthTokenManager,
	OAuthTokenStore,
	OAuthTransientError,
	type OAuthTokenSet,
} from '../src/oauth-token-manager.js';

function makeResponse(payload: Record<string, unknown> | string, status = 200, headers: Record<string, string> = {}): Response {
	const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
	const contentType = typeof payload === 'string' ? 'text/plain' : 'application/json';
	return new Response(body, { status, headers: { 'content-type': contentType, ...headers } });
}

function expiredTokens(): OAuthTokenSet {
	return { accessToken: 'example-old-access', refreshToken: 'example-old-refresh', expiresAt: 0, tokenType: 'Bearer' };
}

function withStore(run: (store: OAuthTokenStore, path: string) => Promise<void>): Promise<void> {
	const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-matrix-'));
	const path = join(directory, 'tokens.json');
	const store = new OAuthTokenStore(path);
	return run(store, path).finally(() => {
		rmSync(directory, { recursive: true, force: true });
	});
}

describe('OAuth matrix — terminal reauth JSON errors', () => {
	const terminalErrors = [
		{ error: 'invalid_grant', reason: 'refresh_token_revoked' },
		{ error: 'invalid_grant', reason: 'refresh_token_expired' },
		{ error: 'invalid_grant', reason: 'refresh_token_invalid' },
		{ error: 'invalid_client' },
		{ error: 'unauthorized_client' },
	] as const;

	for (const payload of terminalErrors) {
		it(`clears state and latches reauth for ${payload.error}${payload.reason ? `/${payload.reason}` : ''}`, async () => {
			await withStore(async store => {
				store.save(expiredTokens());
				let calls = 0;
				const manager = new OAuthTokenManager(store);
				const fetchImpl: typeof fetch = async () => {
					await Promise.resolve();
					calls += 1;
					return makeResponse({ ...payload }, 400);
				};

				await expect(
					manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example', 'https://example.test/wp-json/mcp/stonewright-oauth'),
				).rejects.toBeInstanceOf(OAuthReauthRequiredError);

				await expect(
					manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example'),
				).rejects.toMatchObject({ code: 'reauthentication_required' });

				expect(calls).toBe(1);
				expect(store.load()).toBeNull();
			});
		});
	}
});

describe('OAuth matrix — refresh rotation and replay', () => {
	it('requires a rotated refresh token distinct from the previous value', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			const manager = new OAuthTokenManager(store);
			await expect(
				manager.getAccessToken(
					() => Promise.resolve(makeResponse({
						access_token: 'example-next-access',
						refresh_token: 'example-old-refresh',
						expires_in: 300,
					})),
					'https://example.test/oauth/token',
					'client-example',
				),
			).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			expect(store.load()).toBeNull();
		});
	});

	it('persists rotated tokens and sends resource on refresh', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			const now = 1_700_000_000_000;
			const manager = new OAuthTokenManager(store, { now: () => now, random: () => 0 });
			const bodies: string[] = [];
			const token = await manager.getAccessToken(
				async (_input, init) => {
					await Promise.resolve();
					bodies.push(String(init?.body ?? ''));
					return makeResponse({
						access_token: 'example-rotated-access',
						refresh_token: 'example-rotated-refresh',
						expires_in: 90,
						token_type: 'Bearer',
					});
				},
				'https://example.test/oauth/token',
				'client-example',
				'https://example.test/wp-json/mcp/stonewright-oauth',
			);

			expect(token).toBe('example-rotated-access');
			const params = new URLSearchParams(bodies[0]);
			expect(params.get('grant_type')).toBe('refresh_token');
			expect(params.get('resource')).toBe('https://example.test/wp-json/mcp/stonewright-oauth');
			expect(params.get('refresh_token')).toBe('example-old-refresh');
			expect(store.load()).toEqual({
				accessToken: 'example-rotated-access',
				refreshToken: 'example-rotated-refresh',
				expiresAt: now + 90_000,
				tokenType: 'Bearer',
			});
		});
	});

	it('treats missing refresh_token in a 200 body as terminal reauth', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			const manager = new OAuthTokenManager(store);
			await expect(
				manager.getAccessToken(
					() => Promise.resolve(makeResponse({ access_token: 'example-only-access', expires_in: 60 })),
					'https://example.test/oauth/token',
					'client-example',
				),
			).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			expect(store.load()).toBeNull();
		});
	});
});

describe('OAuth matrix — JSON / non-JSON error bodies', () => {
	it('treats non-JSON 400 bodies as non-terminal HTTP failures', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			const manager = new OAuthTokenManager(store, { maxAttempts: 1, random: () => 0 });
			await expect(
				manager.getAccessToken(
					() => Promise.resolve(makeResponse('<html>bad gateway</html>', 400)),
					'https://example.test/oauth/token',
					'client-example',
				),
			).rejects.toThrow(/OAuth refresh failed with HTTP 400/);
			// Non-terminal: durable state is retained for a later attempt.
			expect(store.load()?.refreshToken).toBe('example-old-refresh');
		});
	});

	it('honors temporarily_unavailable JSON with Retry-After as transient', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			const waits: number[] = [];
			let calls = 0;
			const manager = new OAuthTokenManager(store, {
				maxAttempts: 2,
				baseBackoffMs: 5,
				circuitFailureThreshold: 5,
				now: () => 1_700_000_000_000,
				sleep: ms => {
					waits.push(ms);
					return Promise.resolve();
				},
				random: () => 0,
			});

			const token = await manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) {
					return makeResponse({ error: 'temporarily_unavailable' }, 429, { 'retry-after': '3' });
				}
				return makeResponse({
					access_token: 'example-recovered-access',
					refresh_token: 'example-recovered-refresh',
					expires_in: 120,
				});
			}, 'https://example.test/oauth/token', 'client-example');

			expect(token).toBe('example-recovered-access');
			expect(calls).toBe(2);
			expect(waits).toEqual([3_000]);
		});
	});

	it('opens the circuit after repeated transient failures without clearing tokens', async () => {
		await withStore(async store => {
			store.save(expiredTokens());
			let calls = 0;
			const manager = new OAuthTokenManager(store, {
				maxAttempts: 1,
				baseBackoffMs: 5,
				circuitFailureThreshold: 1,
				circuitOpenMs: 60_000,
				now: () => 1_700_000_000_000,
				random: () => 0,
			});

			await expect(
				manager.getAccessToken(async () => {
					await Promise.resolve();
					calls += 1;
					return makeResponse({ error: 'temporarily_unavailable' }, 503);
				}, 'https://example.test/oauth/token', 'client-example'),
			).rejects.toBeInstanceOf(OAuthTransientError);

			await expect(
				manager.getAccessToken(async () => {
					await Promise.resolve();
					calls += 1;
					return makeResponse({ error: 'temporarily_unavailable' }, 503);
				}, 'https://example.test/oauth/token', 'client-example'),
			).rejects.toBeInstanceOf(OAuthTransientError);

			expect(calls).toBe(1);
			expect(store.load()?.refreshToken).toBe('example-old-refresh');
		});
	});
});

describe('OAuth matrix — refreshAfterUnauthorized terminal latch', () => {
	it('does not re-hit the token endpoint after terminal reauth', async () => {
		await withStore(async store => {
			store.save({
				accessToken: 'rejected-access',
				refreshToken: 'revoked-refresh',
				expiresAt: Date.now() + 300_000,
			});
			let calls = 0;
			const manager = new OAuthTokenManager(store);
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				return makeResponse({ error: 'invalid_grant', reason: 'refresh_token_revoked' }, 400);
			};

			await expect(
				manager.refreshAfterUnauthorized(
					fetchImpl,
					'https://example.test/oauth/token',
					'client-example',
					'rejected-access',
					'https://example.test/wp-json/mcp/stonewright-oauth',
				),
			).rejects.toBeInstanceOf(OAuthReauthRequiredError);

			await expect(
				manager.refreshAfterUnauthorized(
					fetchImpl,
					'https://example.test/oauth/token',
					'client-example',
					'rejected-access',
				),
			).rejects.toBeInstanceOf(OAuthReauthRequiredError);

			expect(calls).toBe(1);
			expect(store.load()).toBeNull();
		});
	});
});
