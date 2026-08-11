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
	writeFileSync,
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
export function restoreFileSnapshot(path: string, snapshot: FileSnapshot): void {
	if (!snapshot.existed) {
		if (existsSync(path)) unlinkSync(path);
		return;
	}
	atomicWriteText(path, snapshot.contents ?? '');
	if (snapshot.mode !== null && process.platform !== 'win32') chmodSync(path, snapshot.mode);
}

/**
 * Atomic write preserving mode when the file already exists.
 */
export function atomicWriteText(path: string, contents: string, mode = 0o600): void {
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
	renameSync(tmp, path);
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

export function redactedDiff(before: string | null, after: string, label: string): string {
	const a = (before ?? '').split('\n');
	const b = after.split('\n');
	const lines: string[] = [`--- ${label} (before)`, `+++ ${label} (after)`];
	const max = Math.max(a.length, b.length);
	let changes = 0;
	for (let i = 0; i < max; i++) {
		const left = a[i];
		const right = b[i];
		if (left === right) continue;
		changes += 1;
		if (left !== undefined) {
			lines.push(`- ${redactLine(left)}`);
		}
		if (right !== undefined) {
			lines.push(`+ ${redactLine(right)}`);
		}
		if (changes > 40) {
			lines.push('… (diff truncated)');
			break;
		}
	}
	if (changes === 0) {
		return `(no textual change in ${label})`;
	}
	return lines.join('\n');
}

function redactLine(line: string): string {
	return line
		.replace(/(PASSWORD|TOKEN|SECRET|AUTHORIZATION)\s*[=:]\s*["']?[^"'\s]+/gi, '$1=***')
		.replace(/Basic\s+[A-Za-z0-9+/=]+/gi, 'Basic ***')
		.replace(/["'](?:[A-Za-z0-9]{4}\s+){5}[A-Za-z0-9]{4}["']/g, '"***"');
}

/**
 * Write with backup + validate + rollback on failure.
 */
export function writeWithRollback(args: {
	path: string;
	nextContents: string;
	validate: (path: string) => void;
}): { backupPath: string | null; diff: string } {
	const before = readTextFile(args.path);
	const backupPath = backupFile(args.path);
	try {
		atomicWriteText(args.path, args.nextContents);
		args.validate(args.path);
		return {
			backupPath,
			diff: redactedDiff(before, args.nextContents, args.path),
		};
	} catch (err) {
		if (backupPath) {
			try {
				restoreBackup(backupPath, args.path);
			} catch {
				// last resort: try writeFileSync of before
				if (before !== null) {
					writeFileSync(args.path, before, 'utf8');
				}
			}
		} else if (existsSync(args.path) && before === null) {
			try {
				unlinkSync(args.path);
			} catch {
				// ignore
			}
		}
		const detail = err instanceof Error ? err.message : String(err);
		throw new ClientConfigError(
			'config_write_failed',
			`Client config write failed and was rolled back: ${detail}`,
		);
	}
}
