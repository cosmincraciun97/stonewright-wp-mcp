import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { appendDirectAudit, recentRecurringErrors } from '../src/direct/audit.js';
import { directIncidentFingerprint } from '../src/direct/incidents.js';

const SITE_A = 'https://a.example.test';
const SITE_B = 'https://b.example.test';
const DAY = 24 * 60 * 60 * 1000;

describe('shared state between Direct and Plugin mode: the audit trail', () => {
	let dir: string;
	let auditPath: string;

	beforeEach(() => {
		dir = mkdtempSync(join(tmpdir(), 'sw-shared-audit-'));
		auditPath = join(dir, 'audit-direct.jsonl');
	});
	afterEach(() => {
		rmSync(dir, { recursive: true, force: true });
	});

	const incidentFiles = () => {
		const root = join(dir, 'incidents');
		return existsSync(root) ? readdirSync(root, { recursive: true }).map(String).filter((name) => name.endsWith('.json')) : [];
	};

	function failure(site: string, tool: string, extra: Record<string, unknown> = {}, when = new Date()) {
		return appendDirectAudit(
			{ tool, site, targetIdentity: site, status: 'error', code: 'boom', error: `${tool} failed`, timestamp: when.toISOString(), ...extra },
			auditPath,
		);
	}

	describe('backend and mode labels', () => {
		it('a Direct row is labelled direct and opens an incident when it failed', () => {
			const row = failure(SITE_A, 'stonewright-content-update');
			expect(row['backend']).toBe('direct');
			expect(row['mode']).toBe('direct');
			expect(incidentFiles()).toHaveLength(1);
		});

		it('a local row is labelled local, and a failed one opens no Direct incident', () => {
			const row = failure(SITE_A, 'stonewright-command-run', { backend: 'local', eventType: 'command_recipe' });
			expect(row['backend']).toBe('local');
			expect(row['mode']).toBe('local');
			expect(row['event_type']).toBe('command_recipe');
			expect(incidentFiles()).toHaveLength(0);
		});

		it('an explicit mode on a local row is kept', () => {
			const row = failure(SITE_A, 'stonewright-rescue-status', { backend: 'local', mode: 'plugin' });
			expect(row['backend']).toBe('local');
			expect(row['mode']).toBe('plugin');
		});
	});

	describe('recurring errors', () => {
		it('lists only the errors of the site asked for', () => {
			failure(SITE_A, 'stonewright-content-update');
			failure(SITE_A, 'stonewright-content-update');
			failure(SITE_B, 'stonewright-menu-create');
			failure(SITE_B, 'stonewright-menu-create');
			const forA = recentRecurringErrors(auditPath, 5, { siteFingerprint: directIncidentFingerprint(SITE_A) });
			expect(forA.map((row) => row.tool)).toEqual(['stonewright-content-update']);
			const forB = recentRecurringErrors(auditPath, 5, { siteFingerprint: directIncidentFingerprint(SITE_B) });
			expect(forB.map((row) => row.tool)).toEqual(['stonewright-menu-create']);
			// No site given: every site, as before.
			expect(recentRecurringErrors(auditPath, 5).map((row) => row.tool).sort()).toEqual([
				'stonewright-content-update',
				'stonewright-menu-create',
			]);
		});

		it('leaves out errors older than the window (30 days by default)', () => {
			const old = new Date(Date.now() - 31 * DAY);
			failure(SITE_A, 'stonewright-content-update', {}, old);
			failure(SITE_A, 'stonewright-content-update', {}, old);
			failure(SITE_A, 'stonewright-media-upload', {}, new Date(Date.now() - 2 * DAY));
			failure(SITE_A, 'stonewright-media-upload', {}, new Date(Date.now() - DAY));
			const fingerprint = directIncidentFingerprint(SITE_A);
			expect(recentRecurringErrors(auditPath, 5, { siteFingerprint: fingerprint }).map((row) => row.tool)).toEqual([
				'stonewright-media-upload',
			]);
			expect(
				recentRecurringErrors(auditPath, 5, { siteFingerprint: fingerprint, windowMs: 60 * DAY }).map((row) => row.tool).sort(),
			).toEqual(['stonewright-content-update', 'stonewright-media-upload']);
		});

		it('does not count local rows, including old rows that were labelled direct', () => {
			failure(SITE_A, 'stonewright-command-run', { backend: 'local', eventType: 'command_recipe' });
			failure(SITE_A, 'stonewright-command-run', { backend: 'local', eventType: 'command_recipe' });
			// A row written before the label existed: backend direct, but a command recipe.
			writeFileSync(
				auditPath,
				`${readFileSync(auditPath, 'utf8')}${[1, 2]
					.map(() =>
						JSON.stringify({
							tool: 'stonewright-rescue-status',
							status: 'error',
							error: 'x',
							backend: 'direct',
							event_type: 'rescue_command',
							site_fingerprint: directIncidentFingerprint(SITE_A),
							timestamp: new Date().toISOString(),
						}),
					)
					.join('\n')}\n`,
			);
			expect(recentRecurringErrors(auditPath, 5, { siteFingerprint: directIncidentFingerprint(SITE_A) })).toEqual([]);
		});
	});

	it('creates no incident directory for local rows alone', () => {
		mkdirSync(dir, { recursive: true });
		failure(SITE_A, 'stonewright-rescue-rollback', { backend: 'local', eventType: 'rescue_command' });
		expect(incidentFiles()).toEqual([]);
	});
});
