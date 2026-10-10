import { randomUUID } from 'node:crypto';
import { lstatSync, readdirSync, rmdirSync, unlinkSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, parse } from 'node:path';

/**
 * Cleanup of the synthetic OAuth fixture roots that Windows test runs create at
 * the drive root. Every root is named
 * `stonewright-oauth-fixtures-run-<runId>-<uuid>`; the run id is shared by all
 * workers of one test run, so one teardown can remove them all.
 */
export const FIXTURE_RUN_ID_ENV = 'STONEWRIGHT_OAUTH_FIXTURE_RUN_ID';

const ROOT_PREFIX = 'stonewright-oauth-fixtures-run-';
const RUN_ID_PATTERN = /^[0-9a-f]{32}$/;
const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const ANY_RUN_ROOT = new RegExp(`^${ROOT_PREFIX}[0-9a-f]{32}-${UUID_PATTERN}$`);

export const STALE_FIXTURE_ROOT_MS = 24 * 60 * 60 * 1000;
export const DEFAULT_RETRY_TIMEOUT_MS = 5000;
export const DEFAULT_RETRY_INTERVAL_MS = 200;

export interface RemoveOptions {
	/** Removes one path; throws while the path is still in use. */
	remove?: (path: string) => void;
	now?: () => number;
	sleep?: (ms: number) => Promise<void>;
	timeoutMs?: number;
	intervalMs?: number;
}

export interface SweepResult {
	removed: string[];
	failed: string[];
}

export function isFixtureRunId(value: unknown): value is string {
	return typeof value === 'string' && RUN_ID_PATTERN.test(value);
}

export function createFixtureRunId(): string {
	return randomUUID().replaceAll('-', '');
}

export function fixtureRootName(runId: string, id: string = randomUUID()): string {
	return `${ROOT_PREFIX}${runId}-${id}`;
}

/** The drive root that holds the fixture roots. */
export function oauthFixtureBase(): string {
	return parse(homedir()).root;
}

function sleepReal(ms: number): Promise<void> {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

/** Removes a tree without following links: a link is removed as an entry, never entered. */
export function removeTreeNoFollow(path: string): void {
	let stats;
	try { stats = lstatSync(path); } catch (error) {
		if ((error as NodeJS.ErrnoException).code === 'ENOENT') return;
		throw error;
	}
	if (stats.isSymbolicLink()) {
		try { unlinkSync(path); } catch { rmdirSync(path); } // A Windows junction is removed like an empty directory.
		return;
	}
	if (!stats.isDirectory()) {
		unlinkSync(path);
		return;
	}
	let failure: unknown;
	for (const name of readdirSync(path)) {
		try { removeTreeNoFollow(join(path, name)); } catch (error) { failure ??= error; } // Keep removing what can be removed.
	}
	if (failure !== undefined) throw failure;
	rmdirSync(path);
}

/** Retries for a short, bounded time: a helper process may still hold a file for a moment. */
export async function removeWithRetry(path: string, options: RemoveOptions = {}): Promise<boolean> {
	const remove = options.remove ?? removeTreeNoFollow;
	const now = options.now ?? Date.now;
	const sleep = options.sleep ?? sleepReal;
	const timeoutMs = Math.max(0, options.timeoutMs ?? DEFAULT_RETRY_TIMEOUT_MS);
	const intervalMs = Math.max(1, options.intervalMs ?? DEFAULT_RETRY_INTERVAL_MS);
	const maxAttempts = Math.floor(timeoutMs / intervalMs) + 1;
	const deadline = now() + timeoutMs;
	for (let attempt = 1; ; attempt += 1) {
		try { remove(path); return true; } catch { /* Retry below while time remains. */ }
		const remaining = deadline - now();
		if (attempt >= maxAttempts || remaining <= 0) return false;
		await sleep(Math.min(intervalMs, remaining));
	}
}

async function sweep(dir: string, select: (name: string, mtimeMs: number) => boolean, options: RemoveOptions): Promise<SweepResult> {
	const result: SweepResult = { removed: [], failed: [] };
	let names: string[];
	try { names = readdirSync(dir); } catch { return result; }
	for (const name of names) {
		if (!ANY_RUN_ROOT.test(name)) continue;
		const path = join(dir, name);
		let stats;
		try { stats = lstatSync(path); } catch { continue; }
		if (stats.isSymbolicLink() || !stats.isDirectory()) continue; // Never follow or remove links.
		if (!select(name, stats.mtimeMs)) continue;
		(await removeWithRetry(path, options) ? result.removed : result.failed).push(path);
	}
	return result;
}

/** Removes the roots of one test run, which must be a 32 character lowercase hex run id. */
export async function removeRunFixtureRoots(dir: string, runId: string, options: RemoveOptions = {}): Promise<SweepResult> {
	if (!isFixtureRunId(runId)) throw new Error('Invalid fixture run id.');
	const own = new RegExp(`^${ROOT_PREFIX}${runId}-${UUID_PATTERN}$`);
	return sweep(dir, (name) => own.test(name), options);
}

/** Removes roots of other runs whose last change is older than 24 hours. */
export async function removeStaleFixtureRoots(
	dir: string,
	options: RemoveOptions & { now: () => number; currentRunId?: string; maxAgeMs?: number },
): Promise<SweepResult> {
	const maxAgeMs = options.maxAgeMs ?? STALE_FIXTURE_ROOT_MS;
	const current = options.currentRunId === undefined ? null : `${ROOT_PREFIX}${options.currentRunId}-`;
	return sweep(dir, (name, mtimeMs) => (current === null || !name.startsWith(current)) && options.now() - mtimeMs > maxAgeMs, options);
}
