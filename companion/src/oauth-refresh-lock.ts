/**
 * Cross-process exclusive lease for OAuth refresh, keyed by the token-store path.
 * Lock file stores only PID, process-start marker, and expiry — never credentials.
 */

import { closeSync, existsSync, mkdirSync, openSync, readFileSync, renameSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname } from 'node:path';

export interface OAuthRefreshLease {
	release(): Promise<void>;
}

interface LockPayload {
	pid: number;
	startedAt: number;
	expiresAt: number;
}

const DEFAULT_LOCK_TTL_MS = 30_000;

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
				const payload: LockPayload = {
					pid: process.pid,
					startedAt: this.now(),
					expiresAt: this.now() + DEFAULT_LOCK_TTL_MS,
				};
				writeFileSync(fd, `${JSON.stringify(payload)}\n`, { encoding: 'utf8' });
				closeSync(fd);
				return {
					release: async () => {
						try {
							unlinkSync(path);
						} catch (error) {
							if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error;
						}
					},
				};
			} catch (error) {
				const code = (error as NodeJS.ErrnoException).code;
				if (code !== 'EEXIST') throw error;
				await this.tryTakeoverStale(path);
				await new Promise((resolve) => setTimeout(resolve, 25));
			}
		}
		throw new Error('OAuth refresh lock acquisition timed out.');
	}

	private async tryTakeoverStale(path: string): Promise<void> {
		if (!existsSync(path)) return;
		let payload: LockPayload | null = null;
		try {
			const raw: unknown = JSON.parse(readFileSync(path, 'utf8'));
			if (
				raw &&
				typeof raw === 'object' &&
				typeof (raw as LockPayload).pid === 'number' &&
				typeof (raw as LockPayload).expiresAt === 'number'
			) {
				payload = raw as LockPayload;
			}
		} catch {
			payload = null;
		}

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
