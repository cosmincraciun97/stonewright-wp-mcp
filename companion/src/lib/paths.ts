/**
 * Path-safety helpers for the companion's filesystem operations.
 *
 * Anything the companion reads or writes (screenshots, pixel-diff output)
 * MUST go through {@link assertInsideArtifacts}. The helper rejects
 * absolute paths outside the configured artifacts root and any traversal
 * (`..`) that would resolve outside the root. Realpath is used so symlinks
 * cannot smuggle a write past the check.
 *
 * Root selection (in order):
 *  1. `COMPANION_ARTIFACTS_ROOT` env var
 *  2. `${os.tmpdir()}/stonewright-artifacts`
 *
 * The root is created on demand the first time it is requested so callers
 * never have to worry about missing directories.
 */

import { mkdirSync, realpathSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { isAbsolute, resolve, dirname, join, basename, sep } from 'node:path';

function defaultRoot(): string {
	return join(tmpdir(), 'stonewright-artifacts');
}

/**
 * Returns the absolute, real (symlink-resolved) artifacts directory,
 * creating it if it does not exist yet.
 */
export function getArtifactsRoot(env: NodeJS.ProcessEnv = process.env): string {
	const raw = (env['COMPANION_ARTIFACTS_ROOT'] ?? '').trim();
	const target = raw === '' ? defaultRoot() : raw;
	if (!existsSync(target)) {
		mkdirSync(target, { recursive: true, mode: 0o700 });
	}
	// Use realpath so symlink-based roots are normalized once. If the root
	// somehow vanished between existsSync and realpathSync, surface the OS
	// error rather than silently downgrading to the un-resolved path.
	return realpathSync(target);
}

/**
 * Throws if `requestedPath` resolves outside `root`.
 *
 * Behaviour:
 *  - If the file exists, both paths are realpath-resolved before comparison.
 *  - If the file does not exist yet (typical for write targets), the
 *    nearest existing parent directory is realpath-resolved and the check
 *    is performed against that. The full would-be path must still be
 *    inside the root.
 *
 * The check is a string prefix check on absolute, realpath-resolved paths
 * with the path separator appended so `/tmp/sw-artifacts-evil` cannot
 * pass for root `/tmp/sw-artifacts`.
 *
 * **TOCTOU trust boundary:** there is a time-of-check / time-of-use gap
 * between this function returning and the actual filesystem write. The
 * caller MUST write to the returned path immediately and SHOULD NOT pass
 * the path through any user-controlled subdirectory after this check.
 * The risk is low in practice because `os.tmpdir()` is per-user, making
 * cross-process symlink races hard to exploit, but the window exists.
 *
 * @param requestedPath  The path the caller wants to read or write.
 * @param root           Pre-resolved root (see {@link getArtifactsRoot}).
 */
export function assertInsideArtifacts(requestedPath: string, root: string): string {
	if (typeof requestedPath !== 'string' || requestedPath.length === 0) {
		throw new Error('Path must be a non-empty string.');
	}

	// Resolve the requested path absolutely so `..` segments collapse.
	// We do NOT call realpath on the requested path first, because the
	// target may not exist yet — instead we realpath the nearest existing
	// ancestor and re-attach the remainder.
	const absRequested = isAbsolute(requestedPath) ? resolve(requestedPath) : resolve(root, requestedPath);

	// Walk up until we find a directory that exists, then realpath it.
	let cursor = absRequested;
	let suffix = '';
	while (cursor !== dirname(cursor)) {
		if (existsSync(cursor)) break;
		// Accumulate the trailing portion we strip off so we can re-attach
		// after realpath.
		suffix = join(basename(cursor), suffix);
		cursor = dirname(cursor);
	}

	const realCursor = existsSync(cursor) ? realpathSync(cursor) : cursor;
	const fullReal = suffix === '' ? realCursor : join(realCursor, suffix);

	const rootWithSep = root.endsWith(sep) ? root : root + sep;
	const fullRealWithSep = fullReal.endsWith(sep) ? fullReal : fullReal + sep;

	const inside =
		fullReal === root || fullRealWithSep.startsWith(rootWithSep);

	if (!inside) {
		throw new Error(
			`Path escapes artifacts root: ${requestedPath} (resolves to ${fullReal}, root=${root})`,
		);
	}

	return fullReal;
}
