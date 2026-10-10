import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { existsSync, mkdirSync, mkdtempSync, readdirSync, rmSync, symlinkSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	createFixtureRunId,
	fixtureRootName,
	removeRunFixtureRoots,
	removeStaleFixtureRoots,
	removeTreeNoFollow,
	removeWithRetry,
} from './helpers/oauth-fixture-cleanup.js';

const DAY_MS = 24 * 60 * 60 * 1000;
const RUN = 'a'.repeat(32);
const OTHER_RUN = 'b'.repeat(32);
const uuid = (n: number): string => `00000000-0000-4000-8000-${String(n).padStart(12, '0')}`;

let base: string;
beforeEach(() => { base = mkdtempSync(join(tmpdir(), 'sw-fixture-cleanup-')); });
afterEach(() => { rmSync(base, { recursive: true, force: true }); });

function makeRoot(name: string, withContent = true): string {
	const root = join(base, name);
	mkdirSync(join(root, 'stonewright-oauth-acl-x'), { recursive: true });
	if (withContent) writeFileSync(join(root, 'stonewright-oauth-acl-x', 'oauth-acl.exe'), 'synthetic');
	return root;
}
function age(path: string, ms: number, now: number): void {
	const when = new Date(now - ms);
	utimesSync(path, when, when);
}
function link(target: string, path: string): boolean {
	try { symlinkSync(target, path, process.platform === 'win32' ? 'junction' : 'dir'); return true; } catch { return false; }
}

describe('fixture root naming', () => {
	it('creates 32 hex run ids and names roots with the run id and a uuid', () => {
		const id = createFixtureRunId();
		expect(id).toMatch(/^[0-9a-f]{32}$/);
		expect(createFixtureRunId()).not.toBe(id);
		expect(fixtureRootName(RUN, uuid(1))).toBe(`stonewright-oauth-fixtures-run-${RUN}-${uuid(1)}`);
	});
});

describe('removeRunFixtureRoots', () => {
	it('removes only roots of this run, recursively', async () => {
		const mine = [makeRoot(fixtureRootName(RUN, uuid(1))), makeRoot(fixtureRootName(RUN, uuid(2)))];
		const otherRun = makeRoot(fixtureRootName(OTHER_RUN, uuid(3)));
		const oldStyle = makeRoot(`stonewright-oauth-fixtures-${uuid(4)}`);
		const unrelated = makeRoot('some-other-folder');
		const lookalike = makeRoot(`${fixtureRootName(RUN, uuid(5))}-extra`);
		const wrongPrefix = makeRoot(`x-${fixtureRootName(RUN, uuid(6))}`);
		const file = join(base, fixtureRootName(RUN, uuid(7)));
		writeFileSync(file, 'not a directory');

		const result = await removeRunFixtureRoots(base, RUN);

		expect([...result.removed].sort()).toEqual([...mine].sort());
		for (const gone of mine) expect(existsSync(gone)).toBe(false);
		for (const kept of [otherRun, oldStyle, unrelated, lookalike, wrongPrefix, file]) expect(existsSync(kept)).toBe(true);
	});

	it('rejects a run id that is not 32 lowercase hex characters', async () => {
		makeRoot(fixtureRootName(RUN, uuid(1)));
		for (const bad of ['', 'run', RUN.toUpperCase(), `${RUN}0`, '*', '..']) {
			await expect(removeRunFixtureRoots(base, bad)).rejects.toThrow(/run id/i);
		}
		expect(readdirSync(base)).toHaveLength(1);
	});

	it('does not remove a root that is a link and never follows it', async (ctx) => {
		const target = makeRoot('link-target');
		const linkPath = join(base, fixtureRootName(RUN, uuid(1)));
		if (!link(target, linkPath)) return ctx.skip();

		const result = await removeRunFixtureRoots(base, RUN);

		expect(result.removed).toEqual([]);
		expect(existsSync(join(target, 'stonewright-oauth-acl-x', 'oauth-acl.exe'))).toBe(true);
		expect(existsSync(linkPath)).toBe(true);
	});

	it('removes a link inside a root without touching what it points at', async (ctx) => {
		const outside = makeRoot('outside');
		const root = makeRoot(fixtureRootName(RUN, uuid(1)));
		if (!link(outside, join(root, 'inner-link'))) return ctx.skip();

		const result = await removeRunFixtureRoots(base, RUN);

		expect(result.removed).toEqual([root]);
		expect(existsSync(root)).toBe(false);
		expect(existsSync(join(outside, 'stonewright-oauth-acl-x', 'oauth-acl.exe'))).toBe(true);
	});

	it('reports a root it could not remove after the bounded retry', async () => {
		const root = makeRoot(fixtureRootName(RUN, uuid(1)));
		let now = 0;
		let attempts = 0;
		const result = await removeRunFixtureRoots(base, RUN, {
			remove: () => { attempts += 1; throw new Error('EBUSY'); },
			now: () => now,
			sleep: (ms) => { now += ms; return Promise.resolve(); },
			timeoutMs: 1000,
			intervalMs: 100,
		});
		expect(result.removed).toEqual([]);
		expect(result.failed).toEqual([root]);
		expect(attempts).toBeLessThanOrEqual(11);
		expect(existsSync(root)).toBe(true);
	});
});

describe('removeStaleFixtureRoots', () => {
	it('removes run-named roots older than 24 hours and keeps newer ones', async () => {
		const now = Date.now();
		const old = makeRoot(fixtureRootName(OTHER_RUN, uuid(1)));
		const newer = makeRoot(fixtureRootName(OTHER_RUN, uuid(2)));
		const justUnder = makeRoot(fixtureRootName(OTHER_RUN, uuid(3)));
		age(old, DAY_MS + 60_000, now);
		age(newer, 60_000, now);
		age(justUnder, DAY_MS - 60_000, now);

		const result = await removeStaleFixtureRoots(base, { now: () => now, currentRunId: RUN });

		expect(result.removed).toEqual([old]);
		expect(existsSync(old)).toBe(false);
		expect(existsSync(newer)).toBe(true);
		expect(existsSync(justUnder)).toBe(true);
	});

	it('never touches old-style, foreign or current-run folders, however old', async () => {
		const now = Date.now();
		const kept = [
			makeRoot(`stonewright-oauth-fixtures-${uuid(1)}`),
			makeRoot('some-other-folder'),
			makeRoot(`stonewright-oauth-fixtures-run-${uuid(2)}`),
			makeRoot(`stonewright-oauth-fixtures-run-nothex-${uuid(3)}`),
			makeRoot(`${fixtureRootName(OTHER_RUN, uuid(4))}-extra`),
			makeRoot(fixtureRootName(RUN, uuid(5))),
		];
		for (const path of kept) age(path, 10 * DAY_MS, now);

		const result = await removeStaleFixtureRoots(base, { now: () => now, currentRunId: RUN });

		expect(result.removed).toEqual([]);
		for (const path of kept) expect(existsSync(path)).toBe(true);
	});

	it('skips an old root that is a link', async (ctx) => {
		const now = Date.now();
		const target = makeRoot('link-target');
		const linkPath = join(base, fixtureRootName(OTHER_RUN, uuid(1)));
		if (!link(target, linkPath)) return ctx.skip();

		const result = await removeStaleFixtureRoots(base, { now: () => now + 10 * DAY_MS, currentRunId: RUN });

		expect(result.removed).toEqual([]);
		expect(existsSync(join(target, 'stonewright-oauth-acl-x', 'oauth-acl.exe'))).toBe(true);
	});

	it('returns nothing for a directory that does not exist', async () => {
		const result = await removeStaleFixtureRoots(join(base, 'missing'), { now: () => Date.now(), currentRunId: RUN });
		expect(result).toEqual({ removed: [], failed: [] });
	});
});

describe('removeWithRetry', () => {
	it('retries until the removal succeeds', async () => {
		let now = 0;
		let calls = 0;
		const ok = await removeWithRetry('p', {
			remove: () => { calls += 1; if (calls < 3) throw new Error('EBUSY'); },
			now: () => now,
			sleep: (ms) => { now += ms; return Promise.resolve(); },
			timeoutMs: 5000,
			intervalMs: 100,
		});
		expect(ok).toBe(true);
		expect(calls).toBe(3);
	});

	it('stops after the timeout, with bounded attempts and bounded waiting', async () => {
		let now = 0;
		let calls = 0;
		let slept = 0;
		const ok = await removeWithRetry('p', {
			remove: () => { calls += 1; throw new Error('EBUSY'); },
			now: () => now,
			sleep: (ms) => { slept += ms; now += ms; return Promise.resolve(); },
			timeoutMs: 3000,
			intervalMs: 250,
		});
		expect(ok).toBe(false);
		expect(calls).toBeLessThanOrEqual(13);
		expect(slept).toBeLessThanOrEqual(3000);
	});

	it('makes exactly one attempt when the timeout is zero', async () => {
		let calls = 0;
		const ok = await removeWithRetry('p', {
			remove: () => { calls += 1; throw new Error('EBUSY'); },
			now: () => 0,
			sleep: () => Promise.resolve(),
			timeoutMs: 0,
			intervalMs: 100,
		});
		expect(ok).toBe(false);
		expect(calls).toBe(1);
	});
});

describe('removeTreeNoFollow', () => {
	it('removes nested files and directories', () => {
		const root = makeRoot('tree');
		mkdirSync(join(root, 'a', 'b'), { recursive: true });
		writeFileSync(join(root, 'a', 'b', 'f.txt'), 'x');
		removeTreeNoFollow(root);
		expect(existsSync(root)).toBe(false);
	});

	it('removes a link entry without removing its target', (ctx) => {
		const target = makeRoot('target');
		const linkPath = join(base, 'l');
		if (!link(target, linkPath)) return ctx.skip();
		removeTreeNoFollow(linkPath);
		expect(existsSync(linkPath)).toBe(false);
		expect(existsSync(join(target, 'stonewright-oauth-acl-x', 'oauth-acl.exe'))).toBe(true);
	});
});
