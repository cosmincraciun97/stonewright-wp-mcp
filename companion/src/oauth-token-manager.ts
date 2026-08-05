import { chmodSync, existsSync, mkdirSync, readFileSync, renameSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export interface OAuthTokenSet {
	accessToken: string;
	refreshToken: string;
	expiresAt: number;
	tokenType?: string;
}

export class OAuthReauthRequiredError extends Error {
	readonly code = 'reauthentication_required';

	constructor(message = 'OAuth authorization is required again.') {
		super(message);
		this.name = 'OAuthReauthRequiredError';
	}
}

export class OAuthTransientError extends Error {
	readonly code = 'oauth_transient_failure';
	readonly retryAfterMs: number;

	constructor(message: string, retryAfterMs: number) {
		super(message);
		this.name = 'OAuthTransientError';
		this.retryAfterMs = Math.max(0, Math.min(86_400_000, Math.floor(retryAfterMs)));
	}
}

/** Atomic, least-privilege persistence for the companion's OAuth token set. */
export class OAuthTokenStore {
	constructor(private readonly filePath: string) {}

	load(): OAuthTokenSet | null {
		if (!existsSync(this.filePath)) return null;
		try {
			const raw: unknown = JSON.parse(readFileSync(this.filePath, 'utf8'));
			if (!isTokenSet(raw)) return null;
			return raw;
		} catch {
			return null;
		}
	}

	save(tokenSet: OAuthTokenSet): void {
		if (!isTokenSet(tokenSet)) throw new Error('Refusing to persist an invalid OAuth token set.');
		const directory = dirname(this.filePath);
		mkdirSync(directory, { recursive: true, mode: 0o700 });
		const temporaryPath = `${this.filePath}.${process.pid}.tmp`;
		writeFileSync(temporaryPath, `${JSON.stringify(tokenSet)}\n`, { encoding: 'utf8', mode: 0o600 });
		chmodSync(temporaryPath, 0o600);
		renameSync(temporaryPath, this.filePath);
		chmodSync(this.filePath, 0o600);
	}

	clear(): void {
		try {
			unlinkSync(this.filePath);
		} catch (error) {
			if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
		}
	}
}

export interface OAuthTokenManagerOptions {
	maxAttempts?: number;
	baseBackoffMs?: number;
	circuitFailureThreshold?: number;
	circuitOpenMs?: number;
	now?: () => number;
	sleep?: (milliseconds: number) => Promise<void>;
	random?: () => number;
}

/**
 * Refreshes OAuth tokens once per process, with replay-safe terminal handling.
 * Concurrent callers share the same promise; invalid_grant clears local state
 * immediately and never enters the retry loop.
 */
export class OAuthTokenManager {
	private refreshInFlight: Promise<string> | null = null;
	private reauthenticationRequired = false;
	private consecutiveFailures = 0;
	private circuitOpenUntil = 0;
	private readonly maxAttempts: number;
	private readonly baseBackoffMs: number;
	private readonly circuitFailureThreshold: number;
	private readonly circuitOpenMs: number;
	private readonly now: () => number;
	private readonly sleep: (milliseconds: number) => Promise<void>;
	private readonly random: () => number;

	constructor(
		private readonly store: OAuthTokenStore,
		options: OAuthTokenManagerOptions = {},
	) {
		this.maxAttempts = Math.max(1, Math.min(3, options.maxAttempts ?? 3));
		this.baseBackoffMs = Math.max(1, Math.min(60_000, options.baseBackoffMs ?? 250));
		this.circuitFailureThreshold = Math.max(1, Math.min(10, options.circuitFailureThreshold ?? 3));
		this.circuitOpenMs = Math.max(1_000, Math.min(300_000, options.circuitOpenMs ?? 30_000));
		this.now = options.now ?? Date.now;
		this.sleep = options.sleep ?? ((milliseconds: number) => new Promise(resolve => setTimeout(resolve, milliseconds)));
		this.random = options.random ?? Math.random;
	}

	async getAccessToken(fetchImpl: typeof fetch, tokenEndpoint: string, clientId: string, resource = ''): Promise<string> {
		if (this.reauthenticationRequired) throw new OAuthReauthRequiredError();
		const current = this.store.load();
		if (current && current.expiresAt > this.now() + 30_000) return current.accessToken;
		if (this.circuitOpenUntil > this.now()) {
			throw new OAuthTransientError('OAuth refresh circuit is open.', this.circuitOpenUntil - this.now());
		}
		if (this.refreshInFlight) return this.refreshInFlight;
		this.refreshInFlight = this.refresh(fetchImpl, tokenEndpoint, clientId, resource).finally(() => {
			this.refreshInFlight = null;
		});
		return this.refreshInFlight;
	}

	/**
	 * Refresh after a protected resource rejected the token. If another caller
	 * already replaced that exact token, reuse the replacement instead of
	 * rotating the refresh token a second time.
	 */
	async refreshAfterUnauthorized(
		fetchImpl: typeof fetch,
		tokenEndpoint: string,
		clientId: string,
		rejectedAccessToken: string,
		resource = '',
	): Promise<string> {
		if (this.reauthenticationRequired) throw new OAuthReauthRequiredError();
		const current = this.store.load();
		if (current && current.accessToken !== rejectedAccessToken && current.expiresAt > this.now() + 30_000) {
			return current.accessToken;
		}
		if (this.circuitOpenUntil > this.now()) {
			throw new OAuthTransientError('OAuth refresh circuit is open.', this.circuitOpenUntil - this.now());
		}
		if (this.refreshInFlight) return this.refreshInFlight;
		this.refreshInFlight = this.refresh(fetchImpl, tokenEndpoint, clientId, resource).finally(() => {
			this.refreshInFlight = null;
		});
		return this.refreshInFlight;
	}

	private async refresh(fetchImpl: typeof fetch, tokenEndpoint: string, clientId: string, resource: string): Promise<string> {
		const current = this.store.load();
		if (!current?.refreshToken) {
			throw this.requireReauthentication();
		}

		let lastTransient: OAuthTransientError | null = null;
		for (let attempt = 0; attempt < this.maxAttempts; attempt += 1) {
			let response: Response;
			try {
				response = await fetchImpl(tokenEndpoint, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json', 'Cache-Control': 'no-store' },
					body: new URLSearchParams({
						grant_type: 'refresh_token',
						refresh_token: current.refreshToken,
						client_id: clientId,
						...(resource ? { resource } : {}),
					}).toString(),
				});
			} catch (error) {
				const message = error instanceof Error ? error.message : 'OAuth refresh request failed.';
				lastTransient = new OAuthTransientError(message, this.backoff(attempt));
				if (attempt + 1 < this.maxAttempts) await this.sleep(lastTransient.retryAfterMs);
				continue;
			}
			const payload = await readJson(response);
			if (response.ok) {
				let next: OAuthTokenSet;
				try {
					next = tokenSetFromResponse(payload, current, this.now);
				} catch {
					// A nominally successful response may already have consumed and
					// rotated the refresh token. Keeping the previous durable token
					// would replay a credential the server may have revoked.
					try {
						this.store.clear();
					} catch {
						// The durable state is unusable either way; reauthorization is
						// the only replay-safe recovery path.
					}
					throw this.requireReauthentication('OAuth refresh returned an invalid token set; authorization must be completed again.');
				}
				try {
					this.store.save(next);
				} catch {
					// A rotated refresh token must never remain in memory while the
					// durable replacement failed. Force a clean reauthorization path.
					try {
						this.store.clear();
					} catch {
						// The durable state is already considered unusable.
					}
					throw this.requireReauthentication('OAuth token storage failed; authorization must be completed again.');
				}
				this.consecutiveFailures = 0;
				this.circuitOpenUntil = 0;
				return next.accessToken;
			}
			if (payload.error === 'invalid_grant' || payload.error === 'invalid_client' || payload.error === 'unauthorized_client') {
				try {
					this.store.clear();
				} catch {
					// Reauthorization remains the only safe recovery path.
				}
				throw this.requireReauthentication('OAuth refresh was rejected; authorization must be completed again.');
			}
			if (!isTransientStatus(response.status) && payload.error !== 'temporarily_unavailable' && payload.error !== 'rate_limited') {
				throw new Error(`OAuth refresh failed with HTTP ${response.status}.`);
			}
			const serverDelay = retryAfterMilliseconds(response.headers.get('retry-after'), this.now());
			const retryAfterMs = serverDelay === null
				? this.backoff(attempt)
				: Math.min(86_400_000, serverDelay + this.jitter());
			lastTransient = new OAuthTransientError(`OAuth refresh temporarily failed with HTTP ${response.status}.`, retryAfterMs);
			if (attempt + 1 < this.maxAttempts) await this.sleep(retryAfterMs);
		}

		this.consecutiveFailures += 1;
		if (this.consecutiveFailures >= this.circuitFailureThreshold) {
			this.circuitOpenUntil = this.now() + this.circuitOpenMs;
		}
		throw lastTransient ?? new OAuthTransientError('OAuth refresh temporarily failed.', this.baseBackoffMs);
	}

	private backoff(attempt: number): number {
		const exponential = this.baseBackoffMs * 2 ** attempt;
		return Math.floor(exponential + this.jitter());
	}

	private jitter(): number {
		return this.random() * Math.min(this.baseBackoffMs, 1000);
	}

	private requireReauthentication(message?: string): OAuthReauthRequiredError {
		this.reauthenticationRequired = true;
		return new OAuthReauthRequiredError(message);
	}
}

function isTokenSet(value: unknown): value is OAuthTokenSet {
	if (!value || typeof value !== 'object') return false;
	const candidate = value as Partial<OAuthTokenSet>;
	return typeof candidate.accessToken === 'string' && candidate.accessToken.length > 0 &&
		typeof candidate.refreshToken === 'string' && candidate.refreshToken.length > 0 &&
		typeof candidate.expiresAt === 'number' && Number.isFinite(candidate.expiresAt);
}

function tokenSetFromResponse(payload: Record<string, unknown>, current: OAuthTokenSet, now: () => number): OAuthTokenSet {
	const accessToken = typeof payload.access_token === 'string' ? payload.access_token : '';
	if (!accessToken) throw new Error('OAuth refresh response did not contain an access token.');
	const refreshToken = typeof payload.refresh_token === 'string' ? payload.refresh_token : '';
	if (!refreshToken || refreshToken === current.refreshToken) {
		throw new Error('OAuth refresh response did not rotate the refresh token.');
	}
	const expiresIn = typeof payload.expires_in === 'number' && Number.isFinite(payload.expires_in) ? Math.max(1, payload.expires_in) : 3600;
	const next: OAuthTokenSet = {
		accessToken,
		refreshToken,
		expiresAt: now() + expiresIn * 1000,
	};
	const tokenType = typeof payload.token_type === 'string' ? payload.token_type : current.tokenType;
	if (tokenType) next.tokenType = tokenType;
	return next;
}

async function readJson(response: Response): Promise<Record<string, unknown>> {
	try {
		const value: unknown = await response.json();
		return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
	} catch {
		return {};
	}
}

function isTransientStatus(status: number): boolean {
	return status === 408 || status === 425 || status === 429 || status >= 500;
}

function retryAfterMilliseconds(value: string | null, now: number): number | null {
	if (!value) return null;
	const seconds = Number(value);
	if (Number.isFinite(seconds) && seconds >= 0) return Math.min(86_400_000, Math.floor(seconds * 1000));
	const date = Date.parse(value);
	return Number.isFinite(date) ? Math.max(0, Math.min(86_400_000, date - now)) : null;
}
