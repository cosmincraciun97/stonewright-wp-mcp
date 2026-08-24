import {
	closeSync,
	existsSync,
	fstatSync,
	linkSync,
	openSync,
	readFileSync,
	readdirSync,
	renameSync,
	statSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import { randomUUID } from 'node:crypto';
import { dirname, basename, join } from 'node:path';
import { uptime } from 'node:os';

const LOCK_STALE_MS = 30_000;
const LOCK_ATTEMPTS = 500;
const QUARANTINE_MAX_PER_KIND = 32;
const BOOT_IDENTITY = `boot:${Math.floor((Date.now() - uptime() * 1000) / 60_000)}`;
const PROCESS_STARTED_AT = Math.floor(Date.now() - process.uptime() * 1000);

type OwnedFile = {
	fd: number;
	contents: string;
	device: number;
	inode: number;
};

type LockSnapshot = {
	contents: string;
	modifiedAt: number;
	device: number;
	inode: number;
};

export function withOwnedFileLock<T>(lockPath: string, operation: () => T): T {
	let owned: OwnedFile | null = null;
	for (let attempt = 0; attempt < LOCK_ATTEMPTS && owned === null; attempt += 1) {
		const mutex = acquireRecoveryMutex(lockPath);
		try {
			cleanupQuarantines(lockPath);
			owned = tryCreateOwnedFile(lockPath);
			if (owned === null) {
				const stale = staleLockSnapshot(lockPath);
				if (stale !== null && quarantineObserved(lockPath, stale, 'recovery')) {
					owned = tryCreateOwnedFile(lockPath);
				}
			}
		} finally {
			releaseOwnedFile(`${lockPath}.recovery-mutex`, mutex, 'release');
			closeOwnedFile(mutex);
		}
		if (owned === null) waitBriefly();
	}
	if (owned === null) throw new Error(`Timed out waiting for Direct state lock: ${lockPath}`);

	try {
		return operation();
	} finally {
		const mutex = acquireRecoveryMutex(lockPath);
		try {
			releaseOwnedFile(lockPath, owned, 'release');
			cleanupQuarantines(lockPath);
		} finally {
			releaseOwnedFile(`${lockPath}.recovery-mutex`, mutex, 'release');
			closeOwnedFile(mutex);
			closeOwnedFile(owned);
		}
	}
}

function acquireRecoveryMutex(lockPath: string): OwnedFile {
	const mutexPath = `${lockPath}.recovery-mutex`;
	for (let attempt = 0; attempt < LOCK_ATTEMPTS; attempt += 1) {
		const owned = tryCreateOwnedFile(mutexPath);
		if (owned !== null) return owned;
		const stale = staleLockSnapshot(mutexPath);
		if (stale !== null) quarantineObserved(mutexPath, stale, 'recovery');
		waitBriefly();
	}
	throw new Error(`Timed out waiting for Direct recovery mutex: ${mutexPath}`);
}

function tryCreateOwnedFile(path: string): OwnedFile | null {
	let fd = -1;
	try {
		fd = openSync(path, 'wx', 0o600);
		const contents = `${JSON.stringify({
			pid: process.pid,
			created_at: Date.now(),
			token: randomUUID(),
			boot_id: BOOT_IDENTITY,
			process_started_at: PROCESS_STARTED_AT,
		})}\n`;
		writeFileSync(fd, contents, { encoding: 'utf8' });
		const stat = fstatSync(fd);
		return { fd, contents, device: stat.dev, inode: stat.ino };
	} catch (error) {
		if (fd >= 0) closeSync(fd);
		if ((error as NodeJS.ErrnoException).code === 'EEXIST') return null;
		throw error;
	}
}

function releaseOwnedFile(path: string, owned: OwnedFile, kind: 'release'): boolean {
	const quarantinePath = `${path}.${kind}-${randomUUID()}`;
	try {
		if (!ownedPathMatches(path, owned)) return false;
		renameSync(path, quarantinePath);
		const quarantined = statSync(quarantinePath);
		if (
			quarantined.dev !== owned.device ||
			quarantined.ino !== owned.inode ||
			readFileSync(quarantinePath, 'utf8') !== owned.contents
		) {
			restoreQuarantined(quarantinePath, path);
			return false;
		}
		unlinkSync(quarantinePath);
		return true;
	} catch {
		return false;
	}
}

function closeOwnedFile(owned: OwnedFile): void {
	try { closeSync(owned.fd); } catch { /* the descriptor is already closed */ }
}

function ownedPathMatches(path: string, owned: OwnedFile): boolean {
	try {
		const current = statSync(path);
		const descriptor = fstatSync(owned.fd);
		return current.dev === owned.device && current.ino === owned.inode
			&& descriptor.dev === owned.device && descriptor.ino === owned.inode
			&& readFileSync(path, 'utf8') === owned.contents;
	} catch {
		return false;
	}
}

function staleLockSnapshot(path: string): LockSnapshot | null {
	try {
		const before = statSync(path);
		const contents = readFileSync(path, 'utf8');
		const after = statSync(path);
		if (before.dev !== after.dev || before.ino !== after.ino || before.mtimeMs !== after.mtimeMs) return null;
		const snapshot = { contents, modifiedAt: after.mtimeMs, device: after.dev, inode: after.ino };
		let parsed: { pid?: number; boot_id?: string; process_started_at?: number };
		try {
			parsed = JSON.parse(contents) as { pid?: number; boot_id?: string; process_started_at?: number };
		} catch {
			return Date.now() - after.mtimeMs > LOCK_STALE_MS ? snapshot : null;
		}
		if (Number.isInteger(parsed.pid) && Number(parsed.pid) > 0) {
			if (typeof parsed.boot_id === 'string' && parsed.boot_id !== BOOT_IDENTITY) return snapshot;
			if (
				Number(parsed.pid) === process.pid &&
				Number.isFinite(parsed.process_started_at) &&
				Number(parsed.process_started_at) !== PROCESS_STARTED_AT
			) return snapshot;
			try {
				process.kill(Number(parsed.pid), 0);
				return null;
			} catch (error) {
				return (error as NodeJS.ErrnoException).code === 'EPERM' ? null : snapshot;
			}
		}
		return Date.now() - after.mtimeMs > LOCK_STALE_MS ? snapshot : null;
	} catch {
		return null;
	}
}

function quarantineObserved(path: string, observed: LockSnapshot, kind: 'recovery'): boolean {
	const quarantinePath = `${path}.${kind}-${randomUUID()}`;
	try {
		const current = statSync(path);
		if (
			current.dev !== observed.device ||
			current.ino !== observed.inode ||
			readFileSync(path, 'utf8') !== observed.contents
		) return false;
		renameSync(path, quarantinePath);
		const quarantined = statSync(quarantinePath);
		if (
			quarantined.dev !== observed.device ||
			quarantined.ino !== observed.inode ||
			readFileSync(quarantinePath, 'utf8') !== observed.contents
		) {
			restoreQuarantined(quarantinePath, path);
			return false;
		}
		unlinkSync(quarantinePath);
		return true;
	} catch {
		return false;
	}
}

function restoreQuarantined(quarantinePath: string, path: string): void {
	try {
		linkSync(quarantinePath, path);
		unlinkSync(quarantinePath);
	} catch {
		// A canonical owner already exists; retain the quarantine for bounded cleanup.
	}
}

function cleanupQuarantines(lockPath: string): void {
	const directory = dirname(lockPath);
	const lockName = escapeRegExp(basename(lockPath));
	const patterns = [
		new RegExp(`^${lockName}\\.recovery-[0-9a-f-]{36}$`),
		new RegExp(`^${lockName}\\.release-[0-9a-f-]{36}$`),
		new RegExp(`^${lockName}\\.recovery-mutex\\.recovery-[0-9a-f-]{36}$`),
		new RegExp(`^${lockName}\\.recovery-mutex\\.release-[0-9a-f-]{36}$`),
	];
	const now = Date.now();
	for (const pattern of patterns) {
		const artifacts = readdirSync(directory)
			.filter((name) => pattern.test(name))
			.map((name) => {
				try { return { name, mtimeMs: statSync(join(directory, name)).mtimeMs }; } catch { return null; }
			})
			.filter((item): item is { name: string; mtimeMs: number } => item !== null)
			.sort((left, right) => right.mtimeMs - left.mtimeMs || right.name.localeCompare(left.name));
		for (const artifact of artifacts) {
			if (now - artifact.mtimeMs <= LOCK_STALE_MS) continue;
			try { unlinkSync(join(directory, artifact.name)); } catch { /* another owner cleaned it */ }
		}
		const retained = artifacts.filter((artifact) => existsSync(join(directory, artifact.name)));
		for (const artifact of retained.slice(QUARANTINE_MAX_PER_KIND)) {
			if (now - artifact.mtimeMs <= LOCK_STALE_MS) continue;
			try { unlinkSync(join(directory, artifact.name)); } catch { /* another owner cleaned it */ }
		}
	}
}

function escapeRegExp(value: string): string {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function waitBriefly(): void {
	Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 10);
}
