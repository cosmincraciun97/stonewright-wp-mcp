import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const race = vi.hoisted(() => ({
	replaceStaleOnRead: false,
	replaceDuringAppend: false,
	replaced: false,
	competitorReads: 0,
	unauthorizedDelete: false,
	failMarkerRename: false,
	replaceBeforeCanonicalUnlink: false,
	replaceAtRecoveryRename: false,
	stoleLiveLock: false,
	lockOpens: 0,
	lockCloses: 0,
	lockFds: new Set<number>(),
}));

vi.mock('node:fs', async (importOriginal) => {
	const actual = await importOriginal<typeof import('node:fs')>();
	const mockedOpen = ((path: unknown, ...args: unknown[]) => {
		const fd = Reflect.apply(actual.openSync, actual, [path, ...args]) as number;
		if (String(path).includes('.lock')) {
			race.lockOpens += 1;
			race.lockFds.add(fd);
		}
		return fd;
	}) as typeof actual.openSync;
	const mockedClose = ((fd: number) => {
		if (race.lockFds.delete(fd)) race.lockCloses += 1;
		return actual.closeSync(fd);
	}) as typeof actual.closeSync;
	const mockedRead = ((file: unknown, ...args: unknown[]) => {
		const value = Reflect.apply(actual.readFileSync, actual, [file, ...args]) as string | Buffer;
		const path = String(file);
		if (race.replaceStaleOnRead && path.endsWith('.lock')) {
			if (!race.replaced) {
				race.replaced = true;
				actual.writeFileSync(path, `${JSON.stringify({ pid: process.pid, created_at: Date.now(), token: 'competing-recoverer' })}\n`, { mode: 0o600 });
			} else if (String(value).includes('competing-recoverer')) {
				race.competitorReads += 1;
				if (race.competitorReads >= 2) actual.unlinkSync(path);
			}
		}
		return value;
	}) as typeof actual.readFileSync;
	const mockedAppend = ((file: unknown, ...args: unknown[]) => {
		const result = Reflect.apply(actual.appendFileSync, actual, [file, ...args]) as void;
		const path = String(file);
		if (race.replaceDuringAppend && path.endsWith('audit-direct.jsonl')) {
			actual.writeFileSync(`${path}.lock`, `${JSON.stringify({ pid: process.pid, created_at: Date.now(), token: 'competing-owner' })}\n`, { mode: 0o600 });
		}
		return result;
	}) as typeof actual.appendFileSync;
	const mockedUnlink = ((file: unknown) => {
		const path = String(file);
		if (path.endsWith('.lock')) {
			try {
				const body = String(actual.readFileSync(path, 'utf8'));
				if (race.replaceBeforeCanonicalUnlink && body.includes('stale-owner')) {
					race.replaceBeforeCanonicalUnlink = false;
					actual.writeFileSync(path, `${JSON.stringify({
						pid: process.pid,
						created_at: Date.now(),
						token: 'replacement-owner',
					})}\n`, { mode: 0o600 });
					race.unauthorizedDelete = true;
				}
				if (body.includes('competing-recoverer') || body.includes('competing-owner')) {
					race.unauthorizedDelete = true;
				}
			} catch {
				// The observed owner already released its lock.
			}
		}
		return actual.unlinkSync(path);
	}) as typeof actual.unlinkSync;
	const mockedRename = ((from: unknown, to: unknown) => {
		const source = String(from);
		const destination = String(to);
		if (
			race.replaceAtRecoveryRename &&
			source.endsWith('.lock') &&
			destination.includes('.lock.recovery-') &&
			!destination.endsWith('.lock.recovery-mutex') &&
			!actual.existsSync(`${source}.recovery-mutex`)
		) {
			race.replaceAtRecoveryRename = false;
			actual.writeFileSync(source, `${JSON.stringify({
				pid: process.pid,
				created_at: Date.now(),
				token: 'live-owner-at-rename',
			})}\n`, { mode: 0o600 });
			race.stoleLiveLock = true;
		}
		if (race.failMarkerRename && source.includes('.audit-idempotency') && source.endsWith('.tmp')) {
			race.failMarkerRename = false;
			throw Object.assign(new Error('synthetic marker rename failure'), { code: 'EIO' });
		}
		return actual.renameSync(String(from), String(to));
	}) as typeof actual.renameSync;
	return {
		...actual,
		openSync: mockedOpen,
		closeSync: mockedClose,
		readFileSync: mockedRead,
		appendFileSync: mockedAppend,
		unlinkSync: mockedUnlink,
		renameSync: mockedRename,
	};
});

import { appendDirectAudit } from '../src/direct/audit.js';

describe('Direct audit lock ownership', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-audit-lock-owner-'));
		Object.assign(race, {
			replaceStaleOnRead: false,
			replaceDuringAppend: false,
			replaced: false,
			competitorReads: 0,
			unauthorizedDelete: false,
			failMarkerRename: false,
			replaceBeforeCanonicalUnlink: false,
			replaceAtRecoveryRename: false,
			stoleLiveLock: false,
			lockOpens: 0,
			lockCloses: 0,
		});
		race.lockFds.clear();
	});

	afterEach(() => {
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('does not delete a competing owner lock during release', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		race.replaceDuringAppend = true;

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'https://site-a.example.test', status: 'ok' }, path);

		expect(race.unauthorizedDelete).toBe(false);
		expect(readFileSync(`${path}.lock`, 'utf8')).toContain('competing-owner');
	});

	it('does not delete a live lock installed by a competing stale-lock recoverer', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, `${JSON.stringify({ pid: 999_999, created_at: 1, token: 'stale-owner' })}\n`, { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(lock, stale, stale);
		race.replaceStaleOnRead = true;

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'https://site-a.example.test', status: 'ok' }, path);

		expect(race.unauthorizedDelete).toBe(false);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	});

	it('recovers a stale lock whose live PID belongs to a different boot or process start', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, `${JSON.stringify({
			pid: process.pid,
			created_at: 1,
			token: 'reused-pid-owner',
			boot_id: 'synthetic-previous-boot',
			process_started_at: 1,
		})}\n`, { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(lock, stale, stale);
		vi.spyOn(Atomics, 'wait').mockReturnValue('timed-out');

		expect(() => appendDirectAudit({
			tool: 'stonewright-content-get',
			site: 'https://site-a.example.test',
			status: 'ok',
		}, path)).not.toThrow();
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	});

	it('never deletes a replacement installed after stale-lock comparison', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, `${JSON.stringify({ pid: 999_999, created_at: 1, token: 'stale-owner' })}\n`, { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(lock, stale, stale);
		race.replaceBeforeCanonicalUnlink = true;

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'https://site-a.example.test', status: 'ok' }, path);

		expect(race.unauthorizedDelete).toBe(false);
	});

	it('serializes stale recovery before the production rename can steal a new live lock', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, `${JSON.stringify({ pid: 999_999, created_at: 1, token: 'stale-owner' })}\n`, { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(lock, stale, stale);
		race.replaceAtRecoveryRename = true;

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'https://site-a.example.test', status: 'ok' }, path);

		expect(race.stoleLiveLock).toBe(false);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	});

	it('removes stale recovery and release quarantines while preserving fresh artifacts and bounding both classes', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		const stale = new Date(Date.now() - 120_000);
		for (const kind of ['recovery', 'release']) {
			for (let index = 0; index < 80; index += 1) {
				const suffix = index.toString(16).padStart(12, '0');
				const artifact = `${lock}.${kind}-00000000-0000-4000-8000-${suffix}`;
				writeFileSync(artifact, `${kind}-${index}\n`, { mode: 0o600 });
				utimesSync(artifact, stale, stale);
			}
		}
		for (const kind of ['recovery', 'release']) {
			for (let index = 0; index < 80; index += 1) {
				const suffix = index.toString(16).padStart(12, '0');
				const artifact = `${lock}.recovery-mutex.${kind}-00000000-0000-4000-8000-${suffix}`;
				writeFileSync(artifact, `mutex-${kind}-${index}\n`, { mode: 0o600 });
				utimesSync(artifact, stale, stale);
			}
		}
		const fresh = `${lock}.release-ffffffff-ffff-4fff-8fff-ffffffffffff`;
		writeFileSync(fresh, 'fresh-owner\n', { mode: 0o600 });

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'https://site-a.example.test', status: 'ok' }, path);

		const entries = readdirSync(stateDir);
		expect(entries.filter((name) => name.startsWith('audit-direct.jsonl.lock.recovery-') && !name.endsWith('recovery-mutex'))).toHaveLength(0);
		expect(entries.filter((name) => name.startsWith('audit-direct.jsonl.lock.release-')).length).toBeLessThanOrEqual(32);
		expect(entries.filter((name) => name.startsWith('audit-direct.jsonl.lock.recovery-mutex.'))).toHaveLength(0);
		expect(existsSync(fresh)).toBe(true);
	});

	it('removes a temporary marker receipt when atomic replacement fails', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		race.failMarkerRename = true;

		const row = appendDirectAudit({
			tool: 'stonewright-content-get',
			site: 'https://site-a.example.test',
			status: 'ok',
			idempotencyKey: 'synthetic-rename-failure',
		}, path);

		expect(row['status']).toBe('ok');
		const markerDir = join(stateDir, '.audit-idempotency');
		expect(readdirSync(markerDir).filter((name) => name.endsWith('.tmp'))).toHaveLength(0);
	});

	it('closes every canonical and recovery-mutex descriptor across repeated lock cycles', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		for (let index = 0; index < 20; index += 1) {
			appendDirectAudit({
				tool: 'stonewright-content-get',
				site: 'https://site-a.example.test',
				status: 'ok',
				idempotencyKey: `descriptor-${index}`,
			}, path);
		}

		expect(race.lockOpens).toBeGreaterThan(0);
		expect(race.lockCloses).toBe(race.lockOpens);
		expect(race.lockFds.size).toBe(0);
	});

});
