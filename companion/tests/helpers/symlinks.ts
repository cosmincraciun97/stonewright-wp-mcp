import { mkdtempSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { TestContext } from 'vitest';

export const SYMLINKS_UNAVAILABLE_REASON =
	'Symlink fixtures unavailable: this Windows account may not create symbolic links (needs Developer Mode or the symlink privilege). The check runs on Linux and macOS CI.';

let cached: boolean | undefined;

/** True when this process can create a file symbolic link in the temporary directory. */
export function canCreateSymlinks(): boolean {
	if (cached !== undefined) return cached;
	const directory = mkdtempSync(join(tmpdir(), 'sw-symlink-probe-'));
	try {
		const target = join(directory, 'target');
		writeFileSync(target, 'probe');
		symlinkSync(target, join(directory, 'link'));
		cached = true;
	} catch {
		cached = false;
	} finally {
		rmSync(directory, { recursive: true, force: true });
	}
	return cached;
}

/**
 * Skips the running test, with the reason named, when this Windows runner cannot create symlinks.
 * A POSIX runner always can, so there a missing capability fails the test instead of hiding it.
 */
export function requireSymlinks(context: Pick<TestContext, 'skip'>): void {
	if (canCreateSymlinks()) return;
	if (process.platform !== 'win32') {
		throw new Error('This runner cannot create symbolic links, and the symlink refusal checks must run here.');
	}
	context.skip(SYMLINKS_UNAVAILABLE_REASON);
}
