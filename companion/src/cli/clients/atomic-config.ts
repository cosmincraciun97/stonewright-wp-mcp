import {
	chmodSync,
	closeSync,
	copyFileSync,
	existsSync,
	fsyncSync,
	mkdirSync,
	openSync,
	readFileSync,
	renameSync,
	statSync,
	unlinkSync,
	writeSync,
} from 'node:fs';
import { dirname } from 'node:path';
import { ClientConfigError } from './types.js';

export function readTextFile(path: string): string | null {
	if (!existsSync(path)) return null;
	return readFileSync(path, 'utf8');
}

export function backupFile(path: string): string | null {
	if (!existsSync(path)) return null;
	const backupPath = `${path}.bak.${new Date().toISOString().replace(/[:.]/g, '-')}`;
	copyFileSync(path, backupPath);
	try {
		const mode = statSync(path).mode & 0o777;
		chmodSync(backupPath, mode);
	} catch {
		// ignore
	}
	return backupPath;
}

export function restoreBackup(backupPath: string, targetPath: string): void {
	copyFileSync(backupPath, targetPath);
}

export interface FileSnapshot {
	existed: boolean;
	contents: string | null;
	mode: number | null;
}

function snapshotsMatch(left: FileSnapshot, right: FileSnapshot): boolean {
	return left.existed === right.existed && left.contents === right.contents;
}

function sleepSync(ms: number): void {
	Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

/** Serialize writes to one concrete client config without locking unrelated configs. */
export function withConfigLock<T>(path: string, fn: () => T, timeoutMs = 10_000): T {
	const lockPath = `${path}.lock`;
	mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
	const start = Date.now();
	while (Date.now() - start <= timeoutMs) {
		try {
			const fd = openSync(lockPath, 'wx', 0o600);
			try {
				writeSync(fd, `${process.pid}\n`);
				return fn();
			} finally {
				closeSync(fd);
				try {
					unlinkSync(lockPath);
				} catch {
					// best effort; the owning transaction is already ending
				}
			}
		} catch (err) {
			if ((err as NodeJS.ErrnoException).code !== 'EEXIST') throw err;
			sleepSync(25);
		}
	}
	throw new ClientConfigError('config_lock_timeout', 'config_lock_timeout: timed out waiting for the client config lock.');
}

/** Capture an exact client-config state for a cross-resource transaction rollback. */
export function snapshotFile(path: string): FileSnapshot {
	if (!existsSync(path)) return { existed: false, contents: null, mode: null };
	let mode: number | null = null;
	try {
		mode = statSync(path).mode & 0o777;
	} catch {
		mode = null;
	}
	return { existed: true, contents: readFileSync(path, 'utf8'), mode };
}

/** Restore an exact pre-transaction state, including deleting a newly-created file. */
export function restoreFileSnapshot(path: string, snapshot: FileSnapshot, expectedCurrent: FileSnapshot): void {
	withConfigLock(path, () => {
		if (!snapshotsMatch(snapshotFile(path), expectedCurrent)) {
			throw new ClientConfigError('config_rollback_conflict', 'config_rollback_conflict: a newer client config edit was preserved.');
		}
		if (!snapshot.existed) {
			if (existsSync(path)) unlinkSync(path);
			return;
		}
		atomicWriteText(path, snapshot.contents ?? '', snapshot.mode ?? 0o600, { expectedCurrent });
		if (snapshot.mode !== null && process.platform !== 'win32') chmodSync(path, snapshot.mode);
	});
}

/**
 * Atomic write preserving mode when the file already exists.
 */
export function atomicWriteText(
	path: string,
	contents: string,
	mode = 0o600,
	options: { expectedCurrent?: FileSnapshot; validateCandidate?: (path: string) => void } = {},
): void {
	const dir = dirname(path);
	mkdirSync(dir, { recursive: true, mode: 0o700 });

	let existingMode: number | null = null;
	if (existsSync(path)) {
		try {
			existingMode = statSync(path).mode & 0o777;
		} catch {
			existingMode = null;
		}
	}

	const tmp = `${path}.${process.pid}.${Date.now()}.tmp`;
	const fd = openSync(tmp, 'w', existingMode ?? mode);
	try {
		writeSync(fd, contents);
		fsyncSync(fd);
	} finally {
		closeSync(fd);
	}
	if (existingMode !== null) {
		try {
			chmodSync(tmp, existingMode);
		} catch {
			// ignore
		}
	}
	try {
		options.validateCandidate?.(tmp);
		if (options.expectedCurrent && !snapshotsMatch(snapshotFile(path), options.expectedCurrent)) {
			throw new ClientConfigError(
				'config_concurrent_modification',
				'config_concurrent_modification: the client config changed before commit; the newer edit was preserved.',
			);
		}
		renameSync(tmp, path);
	} catch (err) {
		try {
			if (existsSync(tmp)) unlinkSync(tmp);
		} catch {
			// best effort temporary-file cleanup
		}
		throw err;
	}
	if (existingMode !== null) {
		try {
			chmodSync(path, existingMode);
		} catch {
			// ignore
		}
	} else if (process.platform !== 'win32') {
		try {
			chmodSync(path, mode);
		} catch {
			// ignore
		}
	}
}

export function redactedDiff(before: string | null, after: string, _label: string): string {
	const a = (before ?? '').split('\n');
	const b = after.split('\n');
	const max = Math.max(a.length, b.length);
	let changes = 0;
	for (let i = 0; i < max; i++) {
		const left = a[i];
		const right = b[i];
		if (left === right) continue;
		changes += 1;
	}
	if (changes === 0) {
		return 'Client configuration unchanged; contents withheld.';
	}
	return `Client configuration updated (${changes} changed line positions); contents withheld.`;
}

/**
 * Write with backup + validate + rollback on failure.
 */
export function writeWithRollback(args: {
	path: string;
	expectedContents?: string | null;
	nextContents: string;
	validate: (path: string) => void;
}): { backupPath: string | null; changed: boolean; diff: string } {
	return withConfigLock(args.path, () => {
		const beforeSnapshot = snapshotFile(args.path);
		const before = beforeSnapshot.contents;
		if ('expectedContents' in args && before !== args.expectedContents) {
			throw new ClientConfigError(
				'config_concurrent_modification',
				'config_concurrent_modification: the client config changed after it was read; the newer edit was preserved.',
			);
		}
		const changed = before !== args.nextContents;
		const backupPath = backupFile(args.path);
		try {
			atomicWriteText(args.path, args.nextContents, 0o600, {
				expectedCurrent: beforeSnapshot,
				validateCandidate: args.validate,
			});
			args.validate(args.path);
			return {
				backupPath,
				changed,
				diff: redactedDiff(before, args.nextContents, args.path),
			};
		} catch (err) {
			const writtenSnapshot: FileSnapshot = {
				existed: true,
				contents: args.nextContents,
				mode: null,
			};
			const ownsCurrentWrite = snapshotsMatch(snapshotFile(args.path), writtenSnapshot);
			let rolledBack = false;
			if (ownsCurrentWrite) {
				try {
					if (before !== null) {
						atomicWriteText(args.path, before, beforeSnapshot.mode ?? 0o600, {
							expectedCurrent: writtenSnapshot,
						});
						if (beforeSnapshot.mode !== null && process.platform !== 'win32') {
							chmodSync(args.path, beforeSnapshot.mode);
						}
					} else if (snapshotsMatch(snapshotFile(args.path), writtenSnapshot)) {
						unlinkSync(args.path);
					}
					rolledBack = true;
				} catch {
					// A non-cooperating writer won the rollback race. Preserve it.
				}
			}
			if (err instanceof ClientConfigError && err.code === 'config_concurrent_modification') throw err;
			const detail = err instanceof Error ? err.message : String(err);
			throw new ClientConfigError(
				'config_write_failed',
				`Client config write failed${rolledBack ? ' and was rolled back' : '; a newer edit was preserved'}: ${detail}`,
			);
		}
	});
}
