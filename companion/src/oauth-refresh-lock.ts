/**
 * Cross-process exclusive lease for OAuth refresh, keyed by the token-store path.
 * Lock file stores only PID, owner id, process-start marker, and expiry — never credentials.
 */

import { randomUUID } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, renameSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export interface OAuthRefreshLease {
	release(): Promise<void>;
}

interface LockPayload {
	pid: number;
	owner: string;
	startedAt: number;
	expiresAt: number;
}

const DEFAULT_LOCK_TTL_MS = 30_000;
const RENEW_INTERVAL_MS = 10_000;

export class OAuthRefreshLock {
	constructor(
		private readonly tokenStorePath: string,
		private readonly now: () => number = Date.now,
	) {}

	private lockPath(): string {
		return `${this.tokenStorePath}.refresh.lock`;
	}

	async acquire(timeoutMs: number): Promise<OAuthRefreshLease> {
		const deadline = this.now() + Math.max(1, timeoutMs);
		const path = this.lockPath();
		mkdirSync(dirname(path), { recursive: true, mode: 0o700 });

		while (this.now() < deadline) {
			try {
				const fd = openSync(path, 'wx');
				const owner = randomUUID();
				const payload: LockPayload = {
					pid: process.pid,
					owner,
					startedAt: this.now(),
					expiresAt: this.now() + DEFAULT_LOCK_TTL_MS,
				};
				writeFileSync(fd, `${JSON.stringify(payload)}\n`, { encoding: 'utf8' });
				closeSync(fd);
				const timer = setInterval(() => {
					this.renew(path, owner);
				}, RENEW_INTERVAL_MS);
				timer.unref?.();
				return {
					release: () => {
						clearInterval(timer);
						this.unlinkIfOwner(path, owner);
						return Promise.resolve();
					},
				};
			} catch (error) {
				const code = (error as NodeJS.ErrnoException).code;
				if (code !== 'EEXIST') throw error;
				this.tryTakeoverStale(path);
				await new Promise((resolve) => setTimeout(resolve, 25));
			}
		}
		throw new Error('OAuth refresh lock acquisition timed out.');
	}

	private readPayload(path: string): LockPayload | null {
		try {
			const raw: unknown = JSON.parse(readFileSync(path, 'utf8'));
			if (
				raw &&
				typeof raw === 'object' &&
				typeof (raw as LockPayload).pid === 'number' &&
				typeof (raw as LockPayload).owner === 'string' &&
				typeof (raw as LockPayload).expiresAt === 'number'
			) {
				return raw as LockPayload;
			}
		} catch {
			// Unreadable or partially written lock — treat as absent.
		}
		return null;
	}

	/**
	 * Extend the lease while the refresh is still running (slow token endpoint
	 * or a Retry-After sleep can exceed the base TTL).
	 */
	private renew(path: string, owner: string): void {
		const payload = this.readPayload(path);
		if (!payload || payload.owner !== owner) return;
		payload.expiresAt = this.now() + DEFAULT_LOCK_TTL_MS;
		const temporary = `${path}.renew.${process.pid}`;
		try {
			writeFileSync(temporary, `${JSON.stringify(payload)}\n`, { encoding: 'utf8', mode: 0o600 });
			renameSync(temporary, path);
		} catch {
			try {
				unlinkSync(temporary);
			} catch {
				// Temp file already gone.
			}
		}
	}

	/** Only the owner that wrote the lock may delete it (fences expired-lease takeovers). */
	private unlinkIfOwner(path: string, owner: string): void {
		const payload = this.readPayload(path);
		if (!payload || payload.owner !== owner) return;
		try {
			unlinkSync(path);
		} catch (error) {
			if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
		}
	}

	private tryTakeoverStale(path: string): void {
		if (!existsSync(path)) return;
		const payload = this.readPayload(path);
		const stale = !payload || payload.expiresAt <= this.now() || !isProcessAlive(payload.pid);
		if (!stale) return;

		const stalePath = `${path}.stale.${process.pid}`;
		try {
			renameSync(path, stalePath);
			unlinkSync(stalePath);
		} catch {
			// Another process won the race.
		}
	}
}

function isProcessAlive(pid: number): boolean {
	if (!Number.isInteger(pid) || pid <= 0) return false;
	try {
		process.kill(pid, 0);
		return true;
	} catch (error) {
		return (error as NodeJS.ErrnoException).code === 'EPERM';
	}
}

/** Exported for tests — never print lock paths in model-visible output. */
export function oauthRefreshLockPathFor(tokenStorePath: string): string {
	return `${tokenStorePath}.refresh.lock`;
}

export function assertRegularOwnedFile(path: string): void {
	if (!existsSync(path)) return;
	const stats = statSync(path);
	if (stats.isSymbolicLink?.() || !stats.isFile()) {
		throw new Error('OAuth token store must be a regular file.');
	}
	const mode = stats.mode & 0o777;
	if ((mode & 0o077) !== 0) {
		throw new Error('OAuth token store has group/world permissions.');
	}
}
