import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mkdtempSync, readFileSync, readdirSync, rmSync, utimesSync, writeFileSync } from 'node:fs';
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
}));

vi.mock('node:fs', async (importOriginal) => {
	const actual = await importOriginal<typeof import('node:fs')>();
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
		if (race.failMarkerRename && source.includes('.audit-idempotency') && source.endsWith('.tmp')) {
			race.failMarkerRename = false;
			throw Object.assign(new Error('synthetic marker rename failure'), { code: 'EIO' });
		}
		return actual.renameSync(String(from), String(to));
	}) as typeof actual.renameSync;
	return {
		...actual,
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
		});
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

});
