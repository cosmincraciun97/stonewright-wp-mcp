import { chmodSync, existsSync, fsyncSync, lstatSync, mkdirSync, openSync, readFileSync, renameSync, unlinkSync, writeFileSync, closeSync } from 'node:fs';
import { dirname } from 'node:path';
import { classifyTransportFailure } from './connection/transport-diagnostic.js';
import { OAuthRefreshLock } from './oauth-refresh-lock.js';

/** Legacy in-memory / on-disk V1 shape (migrated after successful refresh). */
export interface OAuthTokenSet {
	accessToken: string;
	refreshToken: string;
	expiresAt: number;
	tokenType?: string;
}

export interface OAuthTokenSetV2 {
	version: 2;
	accessToken: string;
	refreshToken: string;
	expiresAt: number;
	refreshExpiresAt: number | null;
	clientId: string;
	resource: string;
	generation: number;
	updatedAt: number;
	tokenType?: string;
}

export type DurableOAuthTokenSet = OAuthTokenSet | OAuthTokenSetV2;

export class OAuthReauthRequiredError extends Error {
	readonly code = 'reauthentication_required';

	constructor(
		readonly reasonCode: string = 'reauthentication_required',
		message = 'OAuth authorization is required again.',
	) {
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

	load(): DurableOAuthTokenSet | null {
		if (!existsSync(this.filePath)) return null;
		try {
			const link = lstatSync(this.filePath);
			if (link.isSymbolicLink()) {
				throw new Error('OAuth token store must not be a symlink.');
			}
			if (!link.isFile()) return null;
			const mode = link.mode & 0o777;
			if ((mode & 0o077) !== 0) return null;
			const raw: unknown = JSON.parse(readFileSync(this.filePath, 'utf8'));
			if (isTokenSetV2(raw)) return raw;
			if (isTokenSet(raw)) return raw;
			return null;
		} catch (error) {
			if (error instanceof Error && /symlink/i.test(error.message)) throw error;
			return null;
		}
	}

	save(tokenSet: DurableOAuthTokenSet): void {
		if (!isTokenSetV2(tokenSet) && !isTokenSet(tokenSet)) {
			throw new Error('Refusing to persist an invalid OAuth token set.');
		}
		const directory = dirname(this.filePath);
		mkdirSync(directory, { recursive: true, mode: 0o700 });
		try {
			chmodSync(directory, 0o700);
		} catch {
			// Best-effort directory mode on platforms that reject chmod.
		}
		const temporaryPath = `${this.filePath}.${process.pid}.tmp`;
		writeFileSync(temporaryPath, `${JSON.stringify(tokenSet)}\n`, { encoding: 'utf8', mode: 0o600 });
		chmodSync(temporaryPath, 0o600);
		const fd = openSync(temporaryPath, 'r');
		try {
			fsyncSync(fd);
		} finally {
			closeSync(fd);
		}
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

	path(): string {
		return this.filePath;
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
	lockTimeoutMs?: number;
}

/**
 * Refreshes OAuth tokens with cross-process locking and consumption-aware retry.
 */
export class OAuthTokenManager {
	private refreshInFlight: Promise<string> | null = null;
	private reauthenticationRequired = false;
	private lastReasonCode: string | null = null;
	private consecutiveFailures = 0;
	private circuitOpenUntil = 0;
	private readonly maxAttempts: number;
	private readonly baseBackoffMs: number;
	private readonly circuitFailureThreshold: number;
	private readonly circuitOpenMs: number;
	private readonly lockTimeoutMs: number;
	private readonly now: () => number;
	private readonly sleep: (milliseconds: number) => Promise<void>;
	private readonly random: () => number;
	private readonly lock: OAuthRefreshLock;

	constructor(
		private readonly store: OAuthTokenStore,
		options: OAuthTokenManagerOptions = {},
	) {
		this.maxAttempts = Math.max(1, Math.min(3, options.maxAttempts ?? 2));
		this.baseBackoffMs = Math.max(1, Math.min(60_000, options.baseBackoffMs ?? 250));
		this.circuitFailureThreshold = Math.max(1, Math.min(10, options.circuitFailureThreshold ?? 3));
		this.circuitOpenMs = Math.max(1_000, Math.min(300_000, options.circuitOpenMs ?? 30_000));
		this.lockTimeoutMs = Math.max(100, Math.min(60_000, options.lockTimeoutMs ?? 5_000));
		this.now = options.now ?? Date.now;
		this.sleep = options.sleep ?? ((milliseconds: number) => new Promise(resolve => setTimeout(resolve, milliseconds)));
		this.random = options.random ?? Math.random;
		this.lock = new OAuthRefreshLock(store.path(), this.now);
	}

	async getAccessToken(fetchImpl: typeof fetch, tokenEndpoint: string, clientId: string, resource = ''): Promise<string> {
		if (this.reauthenticationRequired) {
			throw new OAuthReauthRequiredError(this.lastReasonCode ?? 'reauthentication_required');
		}
		const current = this.store.load();
		if (current && current.expiresAt > this.now() + 30_000) {
			this.assertBinding(current, clientId, resource);
			return current.accessToken;
		}
		if (this.circuitOpenUntil > this.now()) {
			throw new OAuthTransientError('OAuth refresh circuit is open.', this.circuitOpenUntil - this.now());
		}
		if (this.refreshInFlight) return this.refreshInFlight;
		this.refreshInFlight = this.refreshWithLock(fetchImpl, tokenEndpoint, clientId, resource).finally(() => {
			this.refreshInFlight = null;
		});
		return this.refreshInFlight;
	}

	async refreshAfterUnauthorized(
		fetchImpl: typeof fetch,
		tokenEndpoint: string,
		clientId: string,
		rejectedAccessToken: string,
		resource = '',
	): Promise<string> {
		if (this.reauthenticationRequired) {
			throw new OAuthReauthRequiredError(this.lastReasonCode ?? 'reauthentication_required');
		}
		const current = this.store.load();
		if (current && current.accessToken !== rejectedAccessToken && current.expiresAt > this.now() + 30_000) {
			this.assertBinding(current, clientId, resource);
			return current.accessToken;
		}
		if (this.circuitOpenUntil > this.now()) {
			throw new OAuthTransientError('OAuth refresh circuit is open.', this.circuitOpenUntil - this.now());
		}
		if (this.refreshInFlight) return this.refreshInFlight;
		this.refreshInFlight = this.refreshWithLock(
			fetchImpl,
			tokenEndpoint,
			clientId,
			resource,
			rejectedAccessToken,
		).finally(() => {
			this.refreshInFlight = null;
		});
		return this.refreshInFlight;
	}

	private async refreshWithLock(
		fetchImpl: typeof fetch,
		tokenEndpoint: string,
		clientId: string,
		resource: string,
		rejectedAccessToken?: string,
	): Promise<string> {
		const lease = await this.lock.acquire(this.lockTimeoutMs);
		try {
			// Another process may have refreshed while we waited.
			const reloaded = this.store.load();
			if (
				reloaded &&
				reloaded.expiresAt > this.now() + 30_000 &&
				(!rejectedAccessToken || reloaded.accessToken !== rejectedAccessToken)
			) {
				this.assertBinding(reloaded, clientId, resource);
				return reloaded.accessToken;
			}
			return await this.refresh(fetchImpl, tokenEndpoint, clientId, resource);
		} finally {
			await lease.release();
		}
	}

	private async refresh(fetchImpl: typeof fetch, tokenEndpoint: string, clientId: string, resource: string): Promise<string> {
		const current = this.store.load();
		if (!current?.refreshToken) {
			throw this.requireReauthentication('missing_refresh_token');
		}
		this.assertBinding(current, clientId, resource);

		let lastTransient: OAuthTransientError | null = null;
		let attempts = 0;
		const maxAttempts = this.maxAttempts;

		while (attempts < maxAttempts) {
			attempts += 1;
			const startedAt = this.now();
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
				const diagnostic = classifyTransportFailure(error, {
					phase: 'tool_call',
					attempt: attempts,
					startedAt,
					now: this.now,
				});
				const preConnect =
					diagnostic.kind === 'dns_error' ||
					diagnostic.kind === 'tls_error' ||
					diagnostic.kind === 'connection_refused' ||
					diagnostic.kind === 'unknown_transport_error';
				if (diagnostic.kind === 'connection_reset' || diagnostic.kind === 'timeout') {
					throw this.requireReauthentication('refresh_outcome_unknown');
				}
				if (preConnect && attempts < maxAttempts) {
					lastTransient = new OAuthTransientError(
						error instanceof Error ? error.message : `OAuth refresh transport failure (${diagnostic.safe_cause_code ?? diagnostic.kind}).`,
						this.backoff(attempts - 1),
					);
					await this.sleep(lastTransient.retryAfterMs);
					continue;
				}
				throw lastTransient ?? new OAuthTransientError(
					error instanceof Error ? error.message : 'OAuth refresh request failed.',
					this.backoff(attempts - 1),
				);
			}

			const consumedHeader = response.headers.get('x-stonewright-refresh-consumed');
			const payload = await readJson(response);

			if (response.ok) {
				let next: OAuthTokenSetV2;
				try {
					next = tokenSetV2FromResponse(payload, current, clientId, resource, this.now);
				} catch {
					try {
						this.store.clear();
					} catch {
						// unusable
					}
					throw this.requireReauthentication('refresh_outcome_unknown');
				}
				try {
					this.store.save(next);
				} catch {
					try {
						this.store.clear();
					} catch {
						// unusable
					}
					throw this.requireReauthentication('token_storage_failed');
				}
				this.consecutiveFailures = 0;
				this.circuitOpenUntil = 0;
				return next.accessToken;
			}

			if (payload.error === 'invalid_grant' || payload.error === 'invalid_client' || payload.error === 'unauthorized_client') {
				try {
					this.store.clear();
				} catch {
					// reauth only
				}
				const reason = typeof payload.reason === 'string' && payload.reason
					? payload.reason
					: payload.error === 'invalid_client'
						? 'invalid_client'
						: 'invalid_grant';
				throw this.requireReauthentication(reason);
			}

			const canRetryTransient =
				consumedHeader === '0' &&
				(isTransientStatus(response.status) || payload.error === 'temporarily_unavailable' || payload.error === 'rate_limited');

			if (canRetryTransient && attempts < maxAttempts) {
				const serverDelay = retryAfterMilliseconds(response.headers.get('retry-after'), this.now());
				const retryAfterMs = serverDelay === null
					? this.backoff(attempts - 1)
					: Math.min(86_400_000, serverDelay + this.jitter());
				lastTransient = new OAuthTransientError(`OAuth refresh temporarily failed with HTTP ${response.status}.`, retryAfterMs);
				await this.sleep(retryAfterMs);
				continue;
			}

			if (consumedHeader !== '0' && (isTransientStatus(response.status) || response.status >= 500)) {
				throw this.requireReauthentication('refresh_outcome_unknown');
			}

			if (!isTransientStatus(response.status) && payload.error !== 'temporarily_unavailable' && payload.error !== 'rate_limited') {
				throw new Error(`OAuth refresh failed with HTTP ${response.status}.`);
			}

			lastTransient = new OAuthTransientError(`OAuth refresh temporarily failed with HTTP ${response.status}.`, this.backoff(attempts - 1));
			break;
		}

		this.consecutiveFailures += 1;
		if (this.consecutiveFailures >= this.circuitFailureThreshold) {
			this.circuitOpenUntil = this.now() + this.circuitOpenMs;
		}
		throw lastTransient ?? new OAuthTransientError('OAuth refresh temporarily failed.', this.baseBackoffMs);
	}

	private assertBinding(token: DurableOAuthTokenSet, clientId: string, resource: string): void {
		if (!isTokenSetV2(token)) return;
		if (token.clientId !== clientId || token.resource !== resource) {
			try {
				this.store.clear();
			} catch {
				// reauth
			}
			throw this.requireReauthentication('resource_or_client_mismatch');
		}
	}

	private backoff(attempt: number): number {
		const exponential = this.baseBackoffMs * 2 ** attempt;
		return Math.floor(exponential + this.jitter());
	}

	private jitter(): number {
		return this.random() * Math.min(this.baseBackoffMs, 1000);
	}

	private requireReauthentication(reasonCode: string, message?: string): OAuthReauthRequiredError {
		this.reauthenticationRequired = true;
		this.lastReasonCode = reasonCode;
		return new OAuthReauthRequiredError(reasonCode, message ?? 'OAuth authorization is required again.');
	}
}

function isTokenSet(value: unknown): value is OAuthTokenSet {
	if (!value || typeof value !== 'object') return false;
	const candidate = value as Partial<OAuthTokenSet>;
	return typeof candidate.accessToken === 'string' && candidate.accessToken.length > 0 &&
		typeof candidate.refreshToken === 'string' && candidate.refreshToken.length > 0 &&
		typeof candidate.expiresAt === 'number' && Number.isFinite(candidate.expiresAt) &&
		(candidate as { version?: unknown }).version !== 2;
}

function isTokenSetV2(value: unknown): value is OAuthTokenSetV2 {
	if (!value || typeof value !== 'object') return false;
	const candidate = value as Partial<OAuthTokenSetV2>;
	return candidate.version === 2 &&
		typeof candidate.accessToken === 'string' && candidate.accessToken.length > 0 &&
		typeof candidate.refreshToken === 'string' && candidate.refreshToken.length > 0 &&
		typeof candidate.expiresAt === 'number' && Number.isFinite(candidate.expiresAt) &&
		typeof candidate.clientId === 'string' &&
		typeof candidate.resource === 'string' &&
		typeof candidate.generation === 'number' &&
		typeof candidate.updatedAt === 'number' &&
		(candidate.refreshExpiresAt === null || typeof candidate.refreshExpiresAt === 'number');
}

function tokenSetV2FromResponse(
	payload: Record<string, unknown>,
	current: DurableOAuthTokenSet,
	clientId: string,
	resource: string,
	now: () => number,
): OAuthTokenSetV2 {
	const accessToken = typeof payload.access_token === 'string' ? payload.access_token : '';
	if (!accessToken) throw new Error('OAuth refresh response did not contain an access token.');
	const refreshToken = typeof payload.refresh_token === 'string' ? payload.refresh_token : '';
	if (!refreshToken || refreshToken === current.refreshToken) {
		throw new Error('OAuth refresh response did not rotate the refresh token.');
	}
	const expiresIn = typeof payload.expires_in === 'number' && Number.isFinite(payload.expires_in) ? Math.max(1, payload.expires_in) : 3600;
	const refreshExpiresIn = typeof payload.refresh_token_expires_in === 'number' && Number.isFinite(payload.refresh_token_expires_in)
		? Math.max(1, payload.refresh_token_expires_in)
		: null;
	const generation = isTokenSetV2(current) ? current.generation + 1 : 1;
	const next: OAuthTokenSetV2 = {
		version: 2,
		accessToken,
		refreshToken,
		expiresAt: now() + expiresIn * 1000,
		refreshExpiresAt: refreshExpiresIn === null ? (isTokenSetV2(current) ? current.refreshExpiresAt : null) : now() + refreshExpiresIn * 1000,
		clientId,
		resource,
		generation,
		updatedAt: now(),
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
