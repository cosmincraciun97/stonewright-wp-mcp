/**
 * Unit tests for security primitives in companion/src/lib/security.ts.
 *
 * Startup-time checks (`loadGuardConfig` throwing when env is missing) are
 * exercised by exporting the loader and invoking it with controlled env
 * fixtures, instead of trying to assert on `process.exit`.
 */

import { describe, it, expect } from 'vitest';
import {
	buildHttpGuard,
	constantTimeEqual,
	evictStaleBuckets,
	extractBearer,
	isBearerValid,
	isOriginAllowed,
	loadGuardConfig,
} from '../src/lib/security.js';
import type { IncomingMessage, ServerResponse } from 'node:http';

// ---------------------------------------------------------------------------
// isBearerValid + constantTimeEqual
// ---------------------------------------------------------------------------

describe('isBearerValid', () => {
	it('accepts a matching token', () => {
		expect(isBearerValid('secret-token', 'secret-token')).toBe(true);
	});

	it('rejects a wrong token', () => {
		expect(isBearerValid('wrong', 'secret-token')).toBe(false);
	});

	it('rejects null', () => {
		expect(isBearerValid(null, 'secret-token')).toBe(false);
	});

	it('accepts anything when expected is null (dev-insecure mode)', () => {
		expect(isBearerValid(null, null)).toBe(true);
		expect(isBearerValid('anything', null)).toBe(true);
	});
});

describe('constantTimeEqual', () => {
	it('returns true for identical strings', () => {
		expect(constantTimeEqual('abc', 'abc')).toBe(true);
	});

	it('returns false for different equal-length strings', () => {
		expect(constantTimeEqual('abc', 'abd')).toBe(false);
	});

	it('returns false for length-mismatched strings without early exit', () => {
		// The previous implementation short-circuited on length mismatch which
		// leaked the secret length. Here we just assert correctness; the
		// timing property is enforced by the implementation using
		// crypto.timingSafeEqual under the hood.
		expect(constantTimeEqual('short', 'much-longer-string')).toBe(false);
		expect(constantTimeEqual('', 'x')).toBe(false);
		expect(constantTimeEqual('xxxxxxxxxx', 'x')).toBe(false);
	});

	it('compares empty strings as equal', () => {
		expect(constantTimeEqual('', '')).toBe(true);
	});
});

// ---------------------------------------------------------------------------
// isOriginAllowed
// ---------------------------------------------------------------------------

describe('isOriginAllowed', () => {
	it('returns true for any origin when allow-list is null (dev-insecure)', () => {
		expect(isOriginAllowed('https://anything.example', null)).toBe(true);
		expect(isOriginAllowed(undefined, null)).toBe(true);
	});

	it('checks membership when allow-list is provided', () => {
		const allow = new Set(['https://app.example']);
		expect(isOriginAllowed('https://app.example', allow)).toBe(true);
		expect(isOriginAllowed('https://evil.example', allow)).toBe(false);
		expect(isOriginAllowed(undefined, allow)).toBe(true);
	});
});

// ---------------------------------------------------------------------------
// extractBearer
// ---------------------------------------------------------------------------

describe('extractBearer', () => {
	function fakeReq(auth: string): IncomingMessage {
		return { headers: { authorization: auth } } as unknown as IncomingMessage;
	}

	it('extracts token from valid Bearer header', () => {
		expect(extractBearer(fakeReq('Bearer my-token'))).toBe('my-token');
	});

	it('returns null for missing header', () => {
		expect(extractBearer(fakeReq(''))).toBeNull();
	});

	it('returns null for Basic auth', () => {
		expect(extractBearer(fakeReq('Basic dXNlcjpwYXNz'))).toBeNull();
	});
});

// ---------------------------------------------------------------------------
// loadGuardConfig — startup-time enforcement
// ---------------------------------------------------------------------------

describe('loadGuardConfig', () => {
	it('throws when COMPANION_BEARER_TOKEN is missing (non-dev)', () => {
		expect(() =>
			loadGuardConfig({
				COMPANION_ALLOWED_ORIGINS: 'https://app.example',
			} as NodeJS.ProcessEnv),
		).toThrow(/COMPANION_BEARER_TOKEN/);
	});

	it('throws when COMPANION_ALLOWED_ORIGINS is missing (non-dev)', () => {
		expect(() =>
			loadGuardConfig({
				COMPANION_BEARER_TOKEN: 'x',
			} as NodeJS.ProcessEnv),
		).toThrow(/COMPANION_ALLOWED_ORIGINS/);
	});

	it('accepts missing token + origins when STONEWRIGHT_DEV_INSECURE=1', () => {
		const config = loadGuardConfig({
			STONEWRIGHT_DEV_INSECURE: '1',
		} as NodeJS.ProcessEnv);
		expect(config.devInsecure).toBe(true);
		expect(config.bearerToken).toBeNull();
		expect(config.allowedOrigins).toBeNull();
	});

	it('defaults bindHost to 127.0.0.1', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
		} as NodeJS.ProcessEnv);
		expect(config.bindHost).toBe('127.0.0.1');
	});

	it('honours an explicit COMPANION_BIND_HOST override', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
			COMPANION_BIND_HOST: '0.0.0.0',
		} as NodeJS.ProcessEnv);
		expect(config.bindHost).toBe('0.0.0.0');
	});

	it('defaults maxBodyBytes to 5 MB', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
		} as NodeJS.ProcessEnv);
		expect(config.maxBodyBytes).toBe(5 * 1024 * 1024);
	});

	it('parses an explicit COMPANION_MAX_BODY_BYTES override', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
			COMPANION_MAX_BODY_BYTES: '1024',
		} as NodeJS.ProcessEnv);
		expect(config.maxBodyBytes).toBe(1024);
	});

	it('parses a comma-separated origin list into a Set', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://a.example, https://b.example ',
		} as NodeJS.ProcessEnv);
		expect(config.allowedOrigins?.has('https://a.example')).toBe(true);
		expect(config.allowedOrigins?.has('https://b.example')).toBe(true);
		expect(config.allowedOrigins?.size).toBe(2);
	});

	it('treats COMPANION_RATE_LIMIT_RPS=0 as disabled (Infinity)', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
			COMPANION_RATE_LIMIT_RPS: '0',
		} as NodeJS.ProcessEnv);
		expect(config.rateLimitRps).toBe(Infinity);
	});

	it('defaults trustProxy to false', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
		} as NodeJS.ProcessEnv);
		expect(config.trustProxy).toBe(false);
	});

	it('sets trustProxy=true when COMPANION_TRUST_PROXY=1', () => {
		const config = loadGuardConfig({
			COMPANION_BEARER_TOKEN: 'x',
			COMPANION_ALLOWED_ORIGINS: 'https://app.example',
			COMPANION_TRUST_PROXY: '1',
		} as NodeJS.ProcessEnv);
		expect(config.trustProxy).toBe(true);
	});
});

// ---------------------------------------------------------------------------
// Rate-limit bucket eviction (item 1)
// ---------------------------------------------------------------------------

describe('evictStaleBuckets', () => {
	it('does not evict when below threshold', () => {
		const buckets = new Map<string, { tokens: number; lastRefill: number }>();
		const now = Date.now();
		// Fill 100 entries (well below 9000 threshold)
		for (let i = 0; i < 100; i++) {
			buckets.set(`10.0.${Math.floor(i / 254)}.${i % 254}`, { tokens: 10, lastRefill: now - 400_000 });
		}
		evictStaleBuckets(buckets, now);
		// Eviction is skipped entirely when size < BUCKET_EVICT_THRESHOLD (9000)
		expect(buckets.size).toBe(100);
	});

	it('evicts stale buckets when at or above threshold', () => {
		const buckets = new Map<string, { tokens: number; lastRefill: number }>();
		const now = Date.now();
		const staleTs = now - 400_000; // 400 s ago — older than 300 s threshold
		// Insert 9001 entries, all stale
		for (let i = 0; i < 9001; i++) {
			buckets.set(`192.168.${Math.floor(i / 254)}.${i % 254}:${i}`, { tokens: 5, lastRefill: staleTs });
		}
		expect(buckets.size).toBe(9001);
		evictStaleBuckets(buckets, now);
		// All entries are stale → all should be removed
		expect(buckets.size).toBe(0);
	});

	it('retains fresh buckets when evicting at threshold', () => {
		const buckets = new Map<string, { tokens: number; lastRefill: number }>();
		const now = Date.now();
		const staleTs = now - 400_000;
		const freshTs = now - 1_000; // 1 s ago — fresh
		// 9000 stale + 1 fresh = 9001 total
		for (let i = 0; i < 9000; i++) {
			buckets.set(`stale-${i}`, { tokens: 5, lastRefill: staleTs });
		}
		buckets.set('fresh-ip', { tokens: 5, lastRefill: freshTs });
		evictStaleBuckets(buckets, now);
		expect(buckets.has('fresh-ip')).toBe(true);
		expect(buckets.size).toBe(1);
	});

	it('evicts at the exact threshold boundary (size === 9000)', () => {
		// Boundary check: BUCKET_EVICT_THRESHOLD is 9000. evictStaleBuckets uses
		// `size < THRESHOLD` for the early-return guard, so size === 9000 must
		// trigger pruning. Without this test a regression of `<=` could silently
		// disable eviction at the exact boundary.
		const buckets = new Map<string, { tokens: number; lastRefill: number }>();
		const now = Date.now();
		const staleTs = now - 400_000;
		for (let i = 0; i < 9000; i++) {
			buckets.set(`boundary-${i}`, { tokens: 5, lastRefill: staleTs });
		}
		expect(buckets.size).toBe(9000);
		evictStaleBuckets(buckets, now);
		expect(buckets.size).toBe(0);
	});
});

// ---------------------------------------------------------------------------
// getIp / X-Forwarded-For trust proxy (item 2)
// ---------------------------------------------------------------------------

/**
 * Build a minimal fake IncomingMessage sufficient for the rate-limit
 * middleware. The close event is never emitted so composeMiddleware resolves
 * via the step chain instead of the close listener.
 */
function makeReq(remoteAddress: string, xff?: string): IncomingMessage {
	const headers: Record<string, string | undefined> = {
		// dev-insecure: origin check passes when origin is absent (null allowedOrigins)
		origin: undefined,
		authorization: undefined,
	};
	if (xff !== undefined) headers['x-forwarded-for'] = xff;
	return {
		headers,
		socket: { remoteAddress },
		method: 'GET',
		url: '/',
	} as unknown as IncomingMessage;
}

function makeRes(): ServerResponse & { statusCode: number } {
	let _status = 200;
	const listeners: Record<string, Array<() => void>> = {};
	const res = {
		get statusCode() { return _status; },
		headersSent: false,
		writeHead(status: number) { _status = status; },
		end() {
			// Fire 'close' synchronously so composeMiddleware's res.once('close')
			// listener can resolve the promise when a middleware short-circuits
			// (e.g. rate-limit writes 429 and returns without calling next()).
			const fns = listeners['close'] ?? [];
			for (const fn of fns) fn();
		},
		setHeader() { /* noop */ },
		once(event: string, fn: () => void) {
			(listeners[event] ??= []).push(fn);
			return res;
		},
	};
	return res as unknown as ServerResponse & { statusCode: number };
}

describe('rate-limit middleware X-Forwarded-For behavior', () => {
	const devEnv = { STONEWRIGHT_DEV_INSECURE: '1' } as NodeJS.ProcessEnv;

	it('ignores X-Forwarded-For by default (trustProxy=false)', async () => {
		// With trustProxy=false, all requests share the socket IP bucket.
		// RPS=1 → burst=3. After 3+1 requests from socket '127.0.0.1' (each
		// with a DIFFERENT XFF header), the bucket should empty and trigger 429.
		const config = loadGuardConfig({ ...devEnv, COMPANION_RATE_LIMIT_RPS: '1' } as NodeJS.ProcessEnv);
		expect(config.trustProxy).toBe(false);
		const guard = buildHttpGuard(config);

		const burst = config.rateLimitRps * 3; // 3
		let limited = false;
		for (let i = 0; i <= burst + 1; i++) {
			const req = makeReq('127.0.0.1', `10.0.0.${i}`); // different XFF each time
			const res = makeRes();
			await guard(req, res);
			if (res.statusCode === 429) { limited = true; break; }
		}
		// Without XFF trust, all requests share socket IP → same bucket → rate-limited
		expect(limited).toBe(true);
	}, 10_000);

	it('uses X-Forwarded-For when trustProxy=true', async () => {
		// With trustProxy=true each unique XFF IP gets its own fresh bucket.
		// Even after burst+1 requests from the same socket, no single XFF IP
		// exhausts its bucket.
		const config = loadGuardConfig({
			...devEnv,
			COMPANION_RATE_LIMIT_RPS: '1',
			COMPANION_TRUST_PROXY: '1',
		} as NodeJS.ProcessEnv);
		expect(config.trustProxy).toBe(true);
		const guard = buildHttpGuard(config);

		const burst = config.rateLimitRps * 3; // 3
		let anyLimited = false;
		for (let i = 0; i <= burst + 1; i++) {
			// Each request uses a unique XFF IP → unique bucket → never exhausted.
			const req = makeReq('127.0.0.1', `10.0.${Math.floor(i / 254)}.${i % 254}`);
			const res = makeRes();
			await guard(req, res);
			if (res.statusCode === 429) { anyLimited = true; break; }
		}
		// Each XFF IP has its own fresh bucket → none should be rate-limited
		expect(anyLimited).toBe(false);
	}, 10_000);
});
