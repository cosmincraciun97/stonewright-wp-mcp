import { describe, expect, it } from 'vitest';
import { mkdirSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	OAuthReauthRequiredError,
	OAuthTokenManager,
	OAuthTokenStore,
	OAuthTransientError,
	type OAuthTokenSet,
} from '../src/oauth-token-manager.js';

function makeResponse(payload: Record<string, unknown>, status = 200, headers: Record<string, string> = {}): Response {
	return new Response(JSON.stringify(payload), { status, headers: { 'content-type': 'application/json', ...headers } });
}

function makeStore(): { directory: string; path: string; store: OAuthTokenStore } {
	const directory = mkdtempSync(join(tmpdir(), 'stonewright-oauth-'));
	const path = join(directory, 'nested', 'oauth.json');
	return { directory, path, store: new OAuthTokenStore(path) };
}

function expiredTokens(): OAuthTokenSet {
	return { accessToken: 'old-access', refreshToken: 'old-refresh', expiresAt: 0, tokenType: 'Bearer' };
}

class FailingTokenStore extends OAuthTokenStore {
	private token: OAuthTokenSet | null = expiredTokens();
	clearCalled = false;

	constructor() {
		super(join(tmpdir(), 'stonewright-failing-oauth.json'));
	}

	override load(): OAuthTokenSet | null {
		return this.token;
	}

	override save(_tokenSet: OAuthTokenSet): void {
		throw new Error('disk full');
	}

	override clear(): void {
		this.clearCalled = true;
		this.token = null;
	}
}

class UnclearableTokenStore extends OAuthTokenStore {
	constructor(private readonly token: OAuthTokenSet) {
		super(join(tmpdir(), 'stonewright-unclearable-oauth.json'));
	}

	override load(): OAuthTokenSet | null {
		return this.token;
	}

	override clear(): void {
		throw new Error('read only');
	}
}

describe('OAuth token manager', () => {
	it('persists rotated tokens atomically with least-privilege permissions', () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			expect(fixture.store.load()).toEqual(expiredTokens());
			expect(statSync(fixture.path).mode & 0o777).toBe(0o600);
			expect(readFileSync(fixture.path, 'utf8')).not.toContain('client_secret');
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('rotates the refresh token and uses the injected clock for expiry', async () => {
		const fixture = makeStore();
		const now = 1_700_000_000_000;
		try {
			fixture.store.save(expiredTokens());
			const manager = new OAuthTokenManager(fixture.store, { now: () => now, random: () => 0 });
			const token = await manager.getAccessToken(
				async (_input, init) => {
					await Promise.resolve();
					expect(init?.method).toBe('POST');
					expect(String(init?.body)).toContain('grant_type=refresh_token');
					expect(new URLSearchParams(String(init?.body)).get('resource')).toBe('https://example.test/mcp');
					return makeResponse({ access_token: 'example-access', refresh_token: 'example-refresh', expires_in: 120, token_type: 'Bearer' });
				},
				'https://example.test/oauth/token',
				'client-example',
				'https://example.test/mcp',
			);
			expect(token).toBe('example-access');
			expect(fixture.store.load()).toEqual({ accessToken: 'example-access', refreshToken: 'example-refresh', expiresAt: now + 120_000, tokenType: 'Bearer' });
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('shares one refresh request between concurrent callers', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			let calls = 0;
			let release!: (response: Response) => void;
			const pending = new Promise<Response>(resolve => { release = resolve; });
			const fetchImpl: typeof fetch = () => {
				calls += 1;
				return pending;
			};
			const manager = new OAuthTokenManager(fixture.store, { now: () => 1_700_000_000_000 });
			const first = manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example');
			const second = manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example');
			expect(calls).toBe(1);
			release(makeResponse({ access_token: 'example-shared-access', refresh_token: 'example-shared-refresh', expires_in: 300 }));
			expect(await Promise.all([first, second])).toEqual(['example-shared-access', 'example-shared-access']);
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('clears terminal invalid_grant state and requires reauthorization without retrying', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			let calls = 0;
			const manager = new OAuthTokenManager(fixture.store);
			await expect(manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				return makeResponse({ error: 'invalid_grant' }, 400);
			}, 'https://example.test/oauth/token', 'client-example')).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			expect(calls).toBe(1);
			expect(fixture.store.load()).toBeNull();
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('latches terminal reauthorization when durable token deletion fails', async () => {
		const manager = new OAuthTokenManager(new UnclearableTokenStore(expiredTokens()));
		let calls = 0;
		const fetchImpl: typeof fetch = async () => {
			await Promise.resolve();
			calls += 1;
			return makeResponse({ error: 'invalid_grant', reason: 'refresh_token_revoked' }, 400);
		};

		await expect(manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example'))
			.rejects.toMatchObject({ code: 'reauthentication_required' });
		await expect(manager.getAccessToken(fetchImpl, 'https://example.test/oauth/token', 'client-example'))
			.rejects.toBeInstanceOf(OAuthReauthRequiredError);
		expect(calls).toBe(1);
	});

	it('clears rotated state when durable storage fails', async () => {
		const store = new FailingTokenStore();
		const manager = new OAuthTokenManager(store);
		await expect(manager.getAccessToken(
			() => Promise.resolve(makeResponse({ access_token: 'example-rotated-access', refresh_token: 'example-rotated-refresh', expires_in: 300 })),
			'https://example.test/oauth/token',
			'client-example',
		)).rejects.toBeInstanceOf(OAuthReauthRequiredError);
		expect(store.clearCalled).toBe(true);
		expect(store.load()).toBeNull();
	});

	it('clears durable state when a successful refresh response is malformed', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			const manager = new OAuthTokenManager(fixture.store);
			await expect(manager.getAccessToken(
				() => Promise.resolve(makeResponse({ refresh_token: 'fixture-rotated-but-unusable', expires_in: 300 })),
				'https://example.test/oauth/token',
				'client-example',
			)).rejects.toBeInstanceOf(OAuthReauthRequiredError);
			expect(fixture.store.load()).toBeNull();
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('rejects a successful response that omits or replays the old refresh token', async () => {
		for (const payload of [
			{ access_token: 'example-access', expires_in: 300 },
			{ access_token: 'example-access', refresh_token: 'old-refresh', expires_in: 300 },
		]) {
			const fixture = makeStore();
			try {
				fixture.store.save(expiredTokens());
				const manager = new OAuthTokenManager(fixture.store);
				await expect(manager.getAccessToken(
					() => Promise.resolve(makeResponse(payload)),
					'https://example.test/oauth/token',
					'client-example',
				)).rejects.toBeInstanceOf(OAuthReauthRequiredError);
				expect(fixture.store.load()).toBeNull();
			} finally {
				rmSync(fixture.directory, { recursive: true, force: true });
			}
		}
	});

	it('honors Retry-After and opens a circuit after repeated transient failures', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			const waits: number[] = [];
			let calls = 0;
			const manager = new OAuthTokenManager(fixture.store, {
				maxAttempts: 2,
				baseBackoffMs: 5,
				circuitFailureThreshold: 1,
				circuitOpenMs: 10_000,
				now: () => 1_700_000_000_000,
					sleep: milliseconds => { waits.push(milliseconds); return Promise.resolve(); },
				random: () => 0,
			});
			await expect(manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				return makeResponse({ error: 'temporarily_unavailable' }, 429, { 'retry-after': '2' });
			}, 'https://example.test/oauth/token', 'client-example')).rejects.toBeInstanceOf(OAuthTransientError);
			expect(calls).toBe(2);
			expect(waits).toEqual([2_000]);
			await expect(manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				return makeResponse({}, 503);
			}, 'https://example.test/oauth/token', 'client-example')).rejects.toBeInstanceOf(OAuthTransientError);
			expect(calls).toBe(2);
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('retries OAuth temporarily_unavailable even when returned with HTTP 400', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			const waits: number[] = [];
			let calls = 0;
			const manager = new OAuthTokenManager(fixture.store, {
				maxAttempts: 2,
				baseBackoffMs: 10,
					sleep: milliseconds => { waits.push(milliseconds); return Promise.resolve(); },
				random: () => 0,
			});
			const token = await manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) return makeResponse({ error: 'temporarily_unavailable' }, 400, { 'retry-after': '1' });
				return makeResponse({ access_token: 'example-recovered', refresh_token: 'example-rotated', expires_in: 60 });
			}, 'https://example.test/oauth/token', 'client-example');

			expect(token).toBe('example-recovered');
			expect(calls).toBe(2);
			expect(waits).toEqual([1_000]);
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('retries network failures with bounded backoff', async () => {
		const fixture = makeStore();
		try {
			fixture.store.save(expiredTokens());
			const waits: number[] = [];
			let calls = 0;
			const manager = new OAuthTokenManager(fixture.store, {
				maxAttempts: 2,
				baseBackoffMs: 10,
					sleep: milliseconds => { waits.push(milliseconds); return Promise.resolve(); },
				random: () => 0,
			});
			const token = await manager.getAccessToken(async () => {
				await Promise.resolve();
				calls += 1;
				if (calls === 1) throw new Error('network down');
				return makeResponse({ access_token: 'example-recovered', refresh_token: 'example-recovered-refresh', expires_in: 60 });
			}, 'https://example.test/oauth/token', 'client-example');
			expect(token).toBe('example-recovered');
			expect(waits).toEqual([10]);
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});

	it('ignores malformed persisted state instead of using it', () => {
		const fixture = makeStore();
		try {
			mkdirSync(join(fixture.directory, 'nested'), { recursive: true });
			writeFileSync(fixture.path, '{not-json');
			expect(fixture.store.load()).toBeNull();
		} finally {
			rmSync(fixture.directory, { recursive: true, force: true });
		}
	});
});
