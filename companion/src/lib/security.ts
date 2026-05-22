/**
 * Security helpers: origin allow-list, bearer-token auth, simple rate limiting.
 * Used by the HTTP transport layer — never by the MCP tool handlers directly.
 *
 * Configuration is loaded lazily via {@link loadGuardConfig}. In non-dev mode
 * (the default) the loader THROWS at startup when required env vars are
 * missing — this fixes a previous footgun where missing config silently
 * disabled origin checks and bearer auth. Dev mode is opt-in via
 * `STONEWRIGHT_DEV_INSECURE=1` and prints loud warnings.
 */

import { timingSafeEqual as nodeTimingSafeEqual } from 'node:crypto';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { log } from './log.js';

// ---------------------------------------------------------------------------
// Configuration object — loaded once at startup via loadGuardConfig()
// ---------------------------------------------------------------------------

const DEFAULT_MAX_BODY_BYTES = 5 * 1024 * 1024; // 5 MB

export interface GuardConfig {
	/** When true, missing tokens / origins log warnings instead of throwing. */
	devInsecure: boolean;
	/** Resolved bearer token, or null in dev-insecure mode with no token set. */
	bearerToken: string | null;
	/** Resolved allow-list of Origin header values, or null in dev-insecure mode. */
	allowedOrigins: Set<string> | null;
	/** Max request-body size in bytes. */
	maxBodyBytes: number;
	/** Host to bind the HTTP server to. */
	bindHost: string;
	/** Steady-state requests-per-second per IP. Infinity means rate limiting is disabled. */
	rateLimitRps: number;
	/**
	 * When true, X-Forwarded-For is honoured for IP extraction.
	 * Only set via COMPANION_TRUST_PROXY=1 — never enabled by default because
	 * a public bindHost would allow forged IPs to bypass rate limiting.
	 */
	trustProxy: boolean;
}

/**
 * Builds a {@link GuardConfig} from process.env. Throws when required
 * variables are missing and dev-insecure mode is OFF.
 */
export function loadGuardConfig(env: NodeJS.ProcessEnv = process.env): GuardConfig {
	const devInsecure = env['STONEWRIGHT_DEV_INSECURE'] === '1';

	if (devInsecure) {
		log.warn(
			'STONEWRIGHT_DEV_INSECURE=1 — running with relaxed security checks. ' +
				'Do not use this in production.',
		);
	}

	// Bearer token — required outside dev mode
	const rawToken = (env['COMPANION_BEARER_TOKEN'] ?? '').trim();
	const bearerToken: string | null = rawToken === '' ? null : rawToken;
	if (bearerToken === null) {
		if (!devInsecure) {
			throw new Error(
				'COMPANION_BEARER_TOKEN is required to start the companion HTTP server. ' +
					'Set it, or pass STONEWRIGHT_DEV_INSECURE=1 if you know what you are doing.',
			);
		}
		log.warn('COMPANION_BEARER_TOKEN is not set — bearer auth is DISABLED (dev mode only).');
	}

	// Allowed origins — required outside dev mode
	const rawOrigins = env['COMPANION_ALLOWED_ORIGINS'] ?? '';
	const originList = rawOrigins
		.split(',')
		.map(o => o.trim())
		.filter(Boolean);
	const allowedOrigins: Set<string> | null = originList.length > 0 ? new Set(originList) : null;
	if (allowedOrigins === null) {
		if (!devInsecure) {
			throw new Error(
				'COMPANION_ALLOWED_ORIGINS is required to start the companion HTTP server. ' +
					'Provide a comma-separated list of allowed Origin header values, or set ' +
					'STONEWRIGHT_DEV_INSECURE=1 to skip the check (dev only).',
			);
		}
		log.warn(
			'COMPANION_ALLOWED_ORIGINS is not set — every Origin is allowed (dev mode only).',
		);
	}

	const rawMaxBody = Number(env['COMPANION_MAX_BODY_BYTES'] ?? DEFAULT_MAX_BODY_BYTES);
	const maxBodyBytes =
		Number.isFinite(rawMaxBody) && rawMaxBody > 0 ? Math.floor(rawMaxBody) : DEFAULT_MAX_BODY_BYTES;

	const bindHost = (env['COMPANION_BIND_HOST'] ?? '127.0.0.1').trim() || '127.0.0.1';

	const rawRps = Number(env['COMPANION_RATE_LIMIT_RPS'] ?? 20);
	// COMPANION_RATE_LIMIT_RPS=0 is treated as "disabled" (Infinity tokens).
	// Any negative or non-finite value falls back to 20.
	const rateLimitRps = rawRps === 0 ? Infinity : (Number.isFinite(rawRps) && rawRps > 0 ? rawRps : 20);

	// Only honour X-Forwarded-For when an explicit opt-in is set.
	// Defaulting to false prevents forged-IP attacks when bindHost=0.0.0.0.
	const trustProxy = env['COMPANION_TRUST_PROXY'] === '1';

	return {
		devInsecure,
		bearerToken,
		allowedOrigins,
		maxBodyBytes,
		bindHost,
		rateLimitRps,
		trustProxy,
	};
}

// ---------------------------------------------------------------------------
// Origin validation
// ---------------------------------------------------------------------------

export function isOriginAllowed(
	origin: string | undefined,
	allowedOrigins: Set<string> | null,
): boolean {
	if (allowedOrigins === null) return true; // dev-insecure mode only
	if (!origin) return false;
	return allowedOrigins.has(origin);
}

function makeOriginMiddleware(config: GuardConfig): Middleware {
	return (req, res, next) => {
		const origin = req.headers['origin'];
		if (!isOriginAllowed(origin, config.allowedOrigins)) {
			log.warn('Origin rejected', { origin });
			res.writeHead(403, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ error: 'Origin not allowed' }));
			return;
		}
		if (origin) {
			res.setHeader('Access-Control-Allow-Origin', origin);
			res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
			res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
		}
		if (req.method === 'OPTIONS') {
			res.writeHead(204);
			res.end();
			return;
		}
		next();
	};
}

// ---------------------------------------------------------------------------
// Bearer token auth
// ---------------------------------------------------------------------------

export function extractBearer(req: IncomingMessage): string | null {
	const auth = req.headers['authorization'] ?? '';
	const match = /^Bearer\s+(.+)$/i.exec(auth);
	return match ? (match[1] ?? null) : null;
}

/**
 * Constant-time string equality backed by {@link crypto.timingSafeEqual}.
 *
 * `crypto.timingSafeEqual` requires equal-length buffers, so length-mismatch
 * inputs are padded to a common length BEFORE the comparison and the result
 * is ANDed with a separate length check. This avoids the early-return timing
 * leak in the previous hand-rolled implementation.
 */
export function constantTimeEqual(a: string, b: string): boolean {
	const ab = Buffer.from(a, 'utf8');
	const bb = Buffer.from(b, 'utf8');
	// The minimum of 1 ensures we never call timingSafeEqual with two zero-length
	// Buffers — crypto.timingSafeEqual throws if either buffer is empty.
	const len = Math.max(ab.length, bb.length, 1);
	const ap = Buffer.alloc(len);
	const bp = Buffer.alloc(len);
	ab.copy(ap);
	bb.copy(bp);
	const equal = nodeTimingSafeEqual(ap, bp);
	// Final length check folded in via boolean AND — both sides always evaluated.
	const sameLen = ab.length === bb.length;
	return equal && sameLen;
}

export function isBearerValid(
	token: string | null,
	expected: string | null,
): boolean {
	if (expected === null) {
		// Dev-insecure mode: no token configured → accept everything. The
		// loud warning was already printed at startup.
		return true;
	}
	if (!token) return false;
	return constantTimeEqual(token, expected);
}

function makeAuthMiddleware(config: GuardConfig): Middleware {
	return (req, res, next) => {
		const token = extractBearer(req);
		if (!isBearerValid(token, config.bearerToken)) {
			log.warn('Auth rejected', { path: req.url });
			res.writeHead(401, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ error: 'Unauthorized' }));
			return;
		}
		next();
	};
}

// ---------------------------------------------------------------------------
// Simple in-memory rate limiter (token-bucket per remote IP)
// ---------------------------------------------------------------------------

interface Bucket {
	tokens: number;
	lastRefill: number;
}

/**
 * Extract the remote IP for rate-limiting.
 *
 * X-Forwarded-For is only honoured when COMPANION_TRUST_PROXY=1 is explicitly
 * set. Trusting it by default would let any client forge a different IP and
 * bypass per-IP rate limiting when the companion binds to 0.0.0.0. The config
 * field `trustProxy` is set by {@link loadGuardConfig}.
 *
 * When trustProxy is on we read the leftmost entry of X-Forwarded-For because
 * that is the original client IP per RFC 7239 / the de-facto XFF convention:
 * each hop appends its observed peer, so the leftmost is the furthest from us.
 * This deliberately trusts the entire upstream chain — operators MUST only
 * enable trustProxy when sitting behind a known reverse proxy that strips any
 * incoming XFF header from external clients.
 */
function getIp(req: IncomingMessage, trustProxy: boolean): string {
	if (trustProxy) {
		const forwarded = req.headers['x-forwarded-for'];
		if (typeof forwarded === 'string') return forwarded.split(',')[0]?.trim() ?? 'unknown';
	}
	return req.socket.remoteAddress ?? 'unknown';
}

const BUCKET_MAP_MAX = 10_000;
// Eviction kicks in at 90% capacity so we have headroom for fresh inserts
// while the stale-prune sweep runs. Derived from BUCKET_MAP_MAX to keep the
// two constants in lockstep if the cap is ever changed.
const BUCKET_EVICT_THRESHOLD = Math.floor(BUCKET_MAP_MAX * 0.9);
const BUCKET_STALE_MS = 5 * 60 * 1000; // 5 minutes

/**
 * Prune stale buckets when the map is over 90% full (>=BUCKET_EVICT_THRESHOLD).
 * A bucket is stale when its lastRefill timestamp is older than BUCKET_STALE_MS.
 * This prevents unbounded memory growth (DoS via unique IPs).
 *
 * @internal Exported for testing only.
 */
export function evictStaleBuckets(buckets: Map<string, Bucket>, now: number): void {
	if (buckets.size < BUCKET_EVICT_THRESHOLD) return;
	for (const [ip, bucket] of buckets) {
		if (now - bucket.lastRefill >= BUCKET_STALE_MS) {
			buckets.delete(ip);
		}
	}
}

function makeRateLimitMiddleware(config: GuardConfig): Middleware {
	const buckets = new Map<string, Bucket>();
	const burst = config.rateLimitRps * 3;
	return (req, res, next) => {
		const ip = getIp(req, config.trustProxy);
		const now = Date.now();

		// Evict stale buckets before inserting when near the cap.
		evictStaleBuckets(buckets, now);

		// Hard cap: if we are still at the maximum after eviction, reject the
		// oldest entry to prevent unbounded growth. (Eviction alone may not
		// reclaim enough slots if all buckets are recently active.)
		if (buckets.size >= BUCKET_MAP_MAX && !buckets.has(ip)) {
			const firstKey = buckets.keys().next().value;
			if (firstKey !== undefined) buckets.delete(firstKey);
		}

		let bucket = buckets.get(ip);
		if (!bucket) {
			bucket = { tokens: burst, lastRefill: now };
			buckets.set(ip, bucket);
		}
		const elapsed = (now - bucket.lastRefill) / 1000;
		bucket.tokens = Math.min(burst, bucket.tokens + elapsed * config.rateLimitRps);
		bucket.lastRefill = now;

		if (bucket.tokens < 1) {
			log.warn('Rate limit exceeded', { ip });
			res.writeHead(429, { 'Content-Type': 'application/json', 'Retry-After': '1' });
			res.end(JSON.stringify({ error: 'Too many requests' }));
			return;
		}
		bucket.tokens -= 1;
		next();
	};
}

// ---------------------------------------------------------------------------
// Body size limit — used by routes that buffer the request body.
// ---------------------------------------------------------------------------

/**
 * Reads the request body into a Buffer, aborting with a 413 response when
 * the total size exceeds {@link GuardConfig.maxBodyBytes}. Returns null when
 * the response has already been written (i.e. limit exceeded) so callers can
 * short-circuit without writing again.
 */
export function readBodyWithLimit(
	req: IncomingMessage,
	res: ServerResponse,
	maxBytes: number,
): Promise<Buffer | null> {
	return new Promise((resolve, reject) => {
		const chunks: Buffer[] = [];
		let received = 0;
		let aborted = false;

		// Cheap pre-check on Content-Length if present.
		const cl = Number(req.headers['content-length']);
		if (Number.isFinite(cl) && cl > maxBytes) {
			respondPayloadTooLarge(res, maxBytes);
			req.resume(); // drain
			aborted = true;
			resolve(null);
			return;
		}

		req.on('data', (chunk: Buffer) => {
			if (aborted) return;
			received += chunk.length;
			if (received > maxBytes) {
				aborted = true;
				respondPayloadTooLarge(res, maxBytes);
				// Tear down the connection so the client knows to stop sending.
				req.destroy();
				resolve(null);
				return;
			}
			chunks.push(chunk);
		});
		req.on('end', () => {
			if (aborted) return;
			resolve(Buffer.concat(chunks));
		});
		req.on('error', (err: Error) => {
			if (aborted) return;
			reject(err);
		});
	});
}

function respondPayloadTooLarge(res: ServerResponse, maxBytes: number): void {
	if (res.headersSent) return;
	res.writeHead(413, { 'Content-Type': 'application/json' });
	res.end(JSON.stringify({ error: 'Payload too large', max_bytes: maxBytes }));
}

// ---------------------------------------------------------------------------
// Compose middleware chains
// ---------------------------------------------------------------------------

type Middleware = (req: IncomingMessage, res: ServerResponse, next: () => void) => void;

function composeMiddleware(
	...fns: Middleware[]
): (req: IncomingMessage, res: ServerResponse) => Promise<boolean> {
	return (req, res) =>
		new Promise<boolean>((resolve) => {
			let i = 0;
			function step(): void {
				if (i >= fns.length) {
					resolve(true);
					return;
				}
				const fn = fns[i++];
				if (!fn) {
					resolve(true);
					return;
				}
				fn(req, res, step);
			}
			res.once('close', () => resolve(false));
			step();
		});
}

/**
 * Build the HTTP guard pipeline for the given config.
 *
 * Pipeline order: [rate-limit →] origin → bearer auth.
 *
 * Rate-limit middleware is omitted entirely when COMPANION_RATE_LIMIT_RPS=0
 * (i.e. rateLimitRps === Infinity). This makes the "disabled" path explicit
 * rather than relying on token math producing Infinity buckets.
 */
export function buildHttpGuard(
	config: GuardConfig,
): (req: IncomingMessage, res: ServerResponse) => Promise<boolean> {
	const middlewares: Middleware[] = [];
	// COMPANION_RATE_LIMIT_RPS=0 → rateLimitRps === Infinity → skip rate limiting.
	if (Number.isFinite(config.rateLimitRps)) {
		middlewares.push(makeRateLimitMiddleware(config));
	}
	middlewares.push(makeOriginMiddleware(config), makeAuthMiddleware(config));
	return composeMiddleware(...middlewares);
}
