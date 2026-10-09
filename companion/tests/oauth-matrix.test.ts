/**
 * Deterministic OAuth / proxy client matrix for companion token lifecycle.
 *
 * Covers discovery-adjacent resource binding in refresh bodies, refresh
 * rotation/replay, terminal reauth, JSON error shapes, and transient vs
 * terminal classification. Prefer these over live browser OAuth for CI.
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { existsSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { createOAuthTestDirectory, OAUTH_WARMUP_TIMEOUT_MS, warmUpOAuthStore } from './helpers/windows-oauth-acl.js';
import {
	LOST_RESPONSE_RETRY_DEADLINE_MS,
	LOST_RESPONSE_RETRY_LATEST_START_MS,
	OAuthReauthRequiredError,
	OAuthTokenManager,
	OAuthTokenStore,
	OAuthTransientError,
	type OAuthTokenSet,
	type OAuthTokenSetV2,
} from '../src/oauth-token-manager.js';

// Windows: build and start the native ACL helper before the first test, not inside it.
beforeAll(warmUpOAuthStore, OAUTH_WARMUP_TIMEOUT_MS);

function makeResponse(payload: Record<string, unknown> | string, status = 200, headers: Record<string, string> = {}): Response {
	const body = typeof payload === 'string' ? payload : JSON.stringify(payload);
	const contentType = typeof payload === 'string' ? 'text/plain' : 'application/json';
	return new Response(body, { status, headers: { 'content-type': contentType, ...headers } });
}

function expiredTokens(): OAuthTokenSet {
	return { accessToken: 'example-old-access', refreshToken: 'example-old-refresh', expiresAt: 0, tokenType: 'Bearer' };
}

function withStore(run: (store: OAuthTokenStore, path: string) => Promise<void>): Promise<void> {
	const directory = createOAuthTestDirectory('stonewright-oauth-matrix-');
	const path = join(directory, 'tokens.json');
	const store = new OAuthTokenStore(path);
	return run(store, path).finally(() => {
		rmSync(directory, { recursive: true, force: true });
	});
}

describe('OAuth matrix — terminal reauth JSON errors', { timeout: 30_000 }, () => {
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

describe('OAuth matrix — refresh rotation and replay', { timeout: 30_000 }, () => {
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
			expect(store.load()).toMatchObject({
				version: 2,
				accessToken: 'example-rotated-access',
				refreshToken: 'example-rotated-refresh',
				expiresAt: now + 90_000,
				clientId: 'client-example',
				resource: 'https://example.test/wp-json/mcp/stonewright-oauth',
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

describe('OAuth matrix — JSON / non-JSON error bodies', { timeout: 30_000 }, () => {
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
					return makeResponse({ error: 'temporarily_unavailable' }, 429, { 'retry-after': '3', 'x-stonewright-refresh-consumed': '0' });
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
					return makeResponse({ error: 'temporarily_unavailable' }, 503, { 'x-stonewright-refresh-consumed': '0' });
				}, 'https://example.test/oauth/token', 'client-example'),
			).rejects.toBeInstanceOf(OAuthTransientError);

			await expect(
				manager.getAccessToken(async () => {
					await Promise.resolve();
					calls += 1;
					return makeResponse({ error: 'temporarily_unavailable' }, 503, { 'x-stonewright-refresh-consumed': '0' });
				}, 'https://example.test/oauth/token', 'client-example'),
			).rejects.toBeInstanceOf(OAuthTransientError);

			expect(calls).toBe(1);
			expect(store.load()?.refreshToken).toBe('example-old-refresh');
		});
	});
});

describe('OAuth matrix — refreshAfterUnauthorized terminal latch', { timeout: 30_000 }, () => {
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

describe('OAuth matrix — lost refresh response, one bounded retry', { timeout: 30_000 }, () => {
	const endpoint = 'https://example.test/oauth/token';
	const start = 1_700_000_000_000;

	afterEach(() => {
		vi.restoreAllMocks();
	});

	function expiredV2(): OAuthTokenSetV2 {
		return {
			version: 2,
			accessToken: 'example-old-access',
			refreshToken: 'example-old-refresh',
			expiresAt: 0,
			refreshExpiresAt: null,
			clientId: 'client-example',
			resource: '',
			generation: 4,
			updatedAt: 0,
			tokenType: 'Bearer',
		};
	}

	function connectionReset(): Error {
		return Object.assign(new TypeError('fetch failed'), { cause: Object.assign(new Error('read ECONNRESET'), { code: 'ECONNRESET' }) });
	}

	function timeout(): Error {
		return Object.assign(new Error('The operation was aborted due to timeout'), { name: 'TimeoutError' });
	}

	function duplicateDelivery(): Response {
		return makeResponse({ access_token: 'example-duplicate-access', refresh_token: 'example-duplicate-refresh', expires_in: 3600, token_type: 'Bearer' }, 200, { 'x-stonewright-refresh-consumed': '0' });
	}

	/** A manager on a clock that only moves when the manager sleeps or a request takes time. */
	function managerOn(store: OAuthTokenStore): { manager: OAuthTokenManager; advance: (ms: number) => void; waits: number[] } {
		let clock = start;
		const waits: number[] = [];
		const manager = new OAuthTokenManager(store, {
			baseBackoffMs: 250,
			random: () => 0,
			now: () => clock,
			sleep: ms => {
				waits.push(ms);
				clock += ms;
				return Promise.resolve();
			},
		});
		return { manager, advance: ms => { clock += ms; }, waits };
	}

	for (const [name, failure] of [['connection reset', connectionReset], ['timeout', timeout]] as const) {
		it(`keeps the connection when a lost response (${name}) is followed by a 200 duplicate`, async () => {
			await withStore(async (store, path) => {
				store.save(expiredV2());
				const { manager, waits } = managerOn(store);
				const timeouts = vi.spyOn(AbortSignal, 'timeout');
				const bodies: string[] = [];
				const signals: Array<AbortSignal | null | undefined> = [];
				const fetchImpl: typeof fetch = async (_input, init) => {
					await Promise.resolve();
					bodies.push(String(init?.body ?? ''));
					signals.push(init?.signal);
					if (bodies.length === 1) throw failure();
					expect(existsSync(`${path}.refresh.lock`)).toBe(true);
					return duplicateDelivery();
				};

				await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).resolves.toBe('example-duplicate-access');

				expect(bodies).toHaveLength(2);
				expect(new URLSearchParams(bodies[1]).get('refresh_token')).toBe('example-old-refresh');
				expect(bodies[1]).toBe(bodies[0]);
				expect(waits).toEqual([250]);
				expect(signals[0]).toBeUndefined();
				expect(signals[1]).toBeInstanceOf(AbortSignal);
				expect(timeouts).toHaveBeenCalledTimes(1);
				expect(timeouts).toHaveBeenCalledWith(LOST_RESPONSE_RETRY_DEADLINE_MS - 250);
				expect(store.load()).toMatchObject({ generation: 5, accessToken: 'example-duplicate-access', refreshToken: 'example-duplicate-refresh' });
				// Not latched: the next call uses the stored access token without a request.
				await expect(manager.getAccessToken(() => Promise.reject(new Error('unexpected request')), endpoint, 'client-example')).resolves.toBe('example-duplicate-access');
			});
		});
	}

	it('latches when a lost response is followed by invalid_grant', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager } = managerOn(store);
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) throw connectionReset();
				return makeResponse({ error: 'invalid_grant', reason: 'refresh_token_revoked' }, 400);
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ code: 'reauthentication_required', reasonCode: 'refresh_token_revoked' });
			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toBeInstanceOf(OAuthReauthRequiredError);

			expect(calls).toBe(2);
			expect(store.load()).toBeNull();
		});
	});

	it('latches refresh_outcome_unknown after two lost responses and sends no third request', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager } = managerOn(store);
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				throw calls === 1 ? connectionReset() : timeout();
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });
			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });

			expect(calls).toBe(2);
			expect(store.load()).toMatchObject({ generation: 4, refreshToken: 'example-old-refresh' });
		});
	});

	it('latches when the retry cannot connect either', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager } = managerOn(store);
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				throw calls === 1 ? connectionReset() : Object.assign(new TypeError('fetch failed'), { cause: { code: 'ECONNREFUSED' } });
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });
			expect(calls).toBe(2);
		});
	});

	it('latches when the retry is answered with a temporary failure', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager } = managerOn(store);
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) throw timeout();
				return makeResponse({ error: 'temporarily_unavailable' }, 429, { 'x-stonewright-refresh-consumed': '0', 'retry-after': '1' });
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });
			expect(calls).toBe(2);
		});
	});

	for (const headers of [{}, { 'x-stonewright-refresh-consumed': '1' }]) {
		it(`does not retry a received 5xx (${JSON.stringify(headers)})`, async () => {
			await withStore(async store => {
				store.save(expiredV2());
				const { manager, waits } = managerOn(store);
				let calls = 0;
				const fetchImpl: typeof fetch = async () => {
					await Promise.resolve();
					calls += 1;
					return makeResponse({ error: 'server_error' }, 500, headers);
				};

				await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });

				expect(calls).toBe(1);
				expect(waits).toEqual([]);
			});
		});
	}

	it('leaves a request refused before it could reach the server to the existing pre-connect retry', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager } = managerOn(store);
			const timeouts = vi.spyOn(AbortSignal, 'timeout');
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) throw Object.assign(new TypeError('fetch failed'), { cause: { code: 'ENOTFOUND' } });
				return duplicateDelivery();
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).resolves.toBe('example-duplicate-access');
			expect(timeouts).not.toHaveBeenCalled();
		});
	});

	it('skips the retry when it could not start within the time bound', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager, advance } = managerOn(store);
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				advance(LOST_RESPONSE_RETRY_LATEST_START_MS - 100);
				throw timeout();
			};

			// 29.9 s plus the 250 ms backoff would start the retry after the bound.
			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).rejects.toMatchObject({ reasonCode: 'refresh_outcome_unknown' });

			expect(calls).toBe(1);
		});
	});

	it('retries when the first request failed just inside the time bound and ends the retry by the deadline', async () => {
		await withStore(async store => {
			store.save(expiredV2());
			const { manager, advance } = managerOn(store);
			const timeouts = vi.spyOn(AbortSignal, 'timeout');
			let calls = 0;
			const fetchImpl: typeof fetch = async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) {
					advance(LOST_RESPONSE_RETRY_LATEST_START_MS - 250);
					throw timeout();
				}
				return duplicateDelivery();
			};

			await expect(manager.getAccessToken(fetchImpl, endpoint, 'client-example')).resolves.toBe('example-duplicate-access');

			expect(calls).toBe(2);
			// The retry started 30 s after the first send; its signal ends 20 s later, 50 s after the first send.
			expect(timeouts).toHaveBeenCalledWith(LOST_RESPONSE_RETRY_DEADLINE_MS - LOST_RESPONSE_RETRY_LATEST_START_MS);
		});
	});

	it('keeps the bounds well inside the server duplicate window', () => {
		expect(LOST_RESPONSE_RETRY_LATEST_START_MS).toBe(30_000);
		expect(LOST_RESPONSE_RETRY_DEADLINE_MS).toBe(50_000);
		expect(LOST_RESPONSE_RETRY_DEADLINE_MS).toBeLessThan(60_000);
	});
});
