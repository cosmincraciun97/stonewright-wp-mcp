import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { build } from 'esbuild';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it, afterEach, beforeAll } from 'vitest';
import {
	DirectIncidentStore,
	type DirectIncidentFailure,
} from '../src/direct/incidents.js';
import { expectPrivateStorage } from './helpers/private-storage.js';
import { OAUTH_WARMUP_TIMEOUT_MS, createOAuthTestDirectory, warmUpOAuthStore } from './helpers/windows-oauth-acl.js';

function fingerprint(site: string): string {
	return createHash('sha256').update(site).digest('hex');
}

function failure(overrides: Partial<DirectIncidentFailure> = {}): DirectIncidentFailure {
	return {
		event_id: '11111111-1111-4111-8111-111111111111',
		ability: 'stonewright-content-update',
		error_code: 'write_failed',
		cause_key: 'content-update|write_failed',
		severity: 'high',
		timestamp: '2026-08-12T08:00:00.000Z',
		...overrides,
	};
}

describe('DirectIncidentStore', () => {
	const spawnedChildren: Array<ReturnType<typeof spawn>> = [];

	// On Windows the private-storage check starts a native ACL helper; build it once, outside the first test.
	beforeAll(warmUpOAuthStore, OAUTH_WARMUP_TIMEOUT_MS);

	afterEach(() => {
		for (const child of spawnedChildren.splice(0)) {
			if (child.exitCode === null && child.signalCode === null) {
				try { child.kill('SIGKILL'); } catch { /* already gone */ }
			}
		}
	});

	async function runWriter(runner: string, args: string[]): Promise<void> {
		await new Promise<void>((resolve, reject) => {
			const child = spawn(process.execPath, [runner, ...args], { stdio: 'pipe' });
			spawnedChildren.push(child);
			let stderr = '';
			child.stderr?.on('data', (chunk) => { stderr += String(chunk); });
			child.once('error', reject);
			child.once('exit', (code) => code === 0 ? resolve() : reject(new Error(`incident writer exited ${code}: ${stderr}`)));
		});
	}

	it('aggregates identical failures, opens at threshold, and reopens after resolution', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
		const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));

		const first = store.observeFailure(failure());
		expect(first.state).toBe('observing');
		expect(first.occurrences).toBe(1);

		const second = store.observeFailure(failure({
			event_id: '22222222-2222-4222-8222-222222222222',
			timestamp: '2026-08-12T08:01:00.000Z',
		}));
		expect(second.state).toBe('open');
		expect(second.occurrences).toBe(2);

		const resolved = store.markResolved(second.incident_id, {
			repair_receipt_id: 'a'.repeat(64),
			resolution_event_id: '33333333-3333-4333-8333-333333333333',
			resolved_at: '2026-08-12T08:02:00.000Z',
			expected_version: { generation: second.generation, updated_at: second.updated_at, occurrences: second.occurrences },
		})!;
		store.markLearningPromoted(second.incident_id, 'verified-repair-a', 'a'.repeat(64), {
			generation: resolved.generation, updated_at: resolved.updated_at, occurrences: resolved.occurrences,
		});
		const reopened = store.observeFailure(failure({
			event_id: '44444444-4444-4444-8444-444444444444',
			timestamp: '2026-08-12T08:03:00.000Z',
		}));
		expect(reopened.state).toBe('open');
		expect(reopened.reopened_count).toBe(1);
		expect(reopened.learning_status).toBe('stale');
		expect(reopened.repair_receipt_id).toBeNull();
	});

	it('isolates sites by fingerprint and survives a new process fixture', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
		const a = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		const b = new DirectIncidentStore(baseDir, fingerprint('site-b'));
		const incident = a.observeFailure(failure());

		expect(b.get(incident.incident_id)).toBeNull();
		expect(b.list()).toEqual([]);
		expect(new DirectIncidentStore(baseDir, fingerprint('site-a')).get(incident.incident_id))
			.toMatchObject({ incident_id: incident.incident_id, occurrences: 1 });
	});

	it('does not count the same terminal event twice and preserves correlation identity', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
		const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		const input = failure({
			correlation_id: '22222222-2222-4222-8222-222222222222',
			idempotency_key: 'a'.repeat(64),
		});

		const first = store.observeFailure(input);
		const duplicate = store.observeFailure(input);

		expect(duplicate.occurrences).toBe(1);
		expect(duplicate.failure_event_id).toBe(first.failure_event_id);
		expect(duplicate.correlation_id).toBe('22222222-2222-4222-8222-222222222222');
		expect(duplicate.last_idempotency_key).toBe('a'.repeat(64));
	});

	it('stores only bounded normalized fields with private permissions', () => {
		// The state directory carries a private ACL on Windows, where the file modes below do not apply.
		const baseDir = createOAuthTestDirectory('sw-direct-incidents-');
		const siteFingerprint = fingerprint('site-a');
		const store = new DirectIncidentStore(baseDir, siteFingerprint);
		store.observeFailure(failure({
			ability: 'stonewright-content-update https://customer.example/private',
			error_code: 'Authorization: Bearer private-token',
			cause_key: 'password=private-password cookie=private-cookie',
		}));

		const path = store.path();
		const body = readFileSync(path, 'utf8');
		expect(body).not.toContain('customer.example');
		expect(body).not.toContain('private-token');
		expect(body).not.toContain('private-password');
		expect(body).not.toContain('private-cookie');
		expect(body).not.toContain('site-a');
		expect(body).not.toContain('arguments');
		expectPrivateStorage(path, join(baseDir, 'incidents'));
	});

	it('migrates pre-envelope incident rows without discarding history', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
		const siteFingerprint = fingerprint('site-a');
		const store = new DirectIncidentStore(baseDir, siteFingerprint);
		const incident = store.observeFailure(failure({ event_id: 'event-1' }));
		const legacy = JSON.parse(readFileSync(store.path(), 'utf8')) as { incidents: Array<Record<string, unknown>> };
		delete legacy.incidents[0]?.['correlation_id'];
		delete legacy.incidents[0]?.['last_idempotency_key'];
		writeFileSync(store.path(), `${JSON.stringify(legacy)}\n`, { mode: 0o600 });

		const migrated = new DirectIncidentStore(baseDir, siteFingerprint).get(incident.incident_id);
		expect(migrated).toMatchObject({
			failure_event_id: 'event-1',
			correlation_id: 'event-1',
			last_idempotency_key: '',
		});
		expect(existsSync(`${store.path()}.corrupt`)).toBe(false);
	});

	it('preserves corrupt state for diagnosis and fails closed to an empty store', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
		const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		store.observeFailure(failure());
		const path = store.path();
		writeFileSync(path, '{corrupt', 'utf8');

		const recovered = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		expect(recovered.list()).toEqual([]);
		expect(existsSync(`${path}.corrupt`)).toBe(true);
	});

	it('serializes concurrent failure and repair read-modify-write operations without lost occurrences', async () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-race-'));
		const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		const seeded = store.observeFailure(failure({ idempotency_key: 'f'.repeat(64) }));
		const fixture = join(process.cwd(), 'tests', 'fixtures', 'direct-incident-writer.ts');
		const runner = join(baseDir, 'direct-incident-writer.mjs');
		await build({ entryPoints: [fixture], bundle: true, platform: 'node', format: 'esm', outfile: runner });

		const writers: Array<Promise<void>> = [];
		for (let worker = 0; worker < 4; worker += 1) {
			writers.push(runWriter(runner, [baseDir, 'failure', `failure-${worker}`, '10', seeded.incident_id]));
			writers.push(runWriter(runner, [baseDir, 'resolve', `repair-${worker}`, '10', seeded.incident_id]));
		}
		await Promise.all(writers);

		const current = new DirectIncidentStore(baseDir, fingerprint('site-a')).get(seeded.incident_id);
		expect(current?.occurrences).toBe(41);
		expect(['open', 'resolved']).toContain(current?.state);
		const persisted = JSON.parse(readFileSync(store.path(), 'utf8')) as { incidents: unknown[] };
		expect(persisted.incidents).toHaveLength(1);
	}, 20_000);

	it('rejects stale resolution and learning after a newer failure advances the incident version', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-cas-'));
		const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
		store.observeFailure(failure());
		const validated = store.observeFailure(failure({
			event_id: '22222222-2222-4222-8222-222222222222',
			timestamp: '2026-08-12T08:01:00.000Z',
		}));
		const validationToken = {
			generation: validated.generation,
			updated_at: validated.updated_at,
			occurrences: validated.occurrences,
		};

		store.observeFailure(failure({
			event_id: '33333333-3333-4333-8333-333333333333',
			timestamp: '2026-08-12T08:02:00.000Z',
		}));
		const staleResolution = store.markResolved(validated.incident_id, {
			repair_receipt_id: 'a'.repeat(64),
			resolution_event_id: '44444444-4444-4444-8444-444444444444',
			resolved_at: '2026-08-12T08:03:00.000Z',
			expected_version: validationToken,
		});

		expect(staleResolution).toBeNull();
		expect(store.get(validated.incident_id)).toMatchObject({ state: 'open', occurrences: 3 });

		const current = store.get(validated.incident_id)!;
		const resolved = store.markResolved(validated.incident_id, {
			repair_receipt_id: 'b'.repeat(64),
			resolution_event_id: '55555555-5555-4555-8555-555555555555',
			resolved_at: '2026-08-12T08:04:00.000Z',
			expected_version: {
				generation: current.generation,
				updated_at: current.updated_at,
				occurrences: current.occurrences,
			},
		})!;
		store.observeFailure(failure({
			event_id: '66666666-6666-4666-8666-666666666666',
			timestamp: '2026-08-12T08:05:00.000Z',
		}));

		expect(store.markLearningPromoted(
			validated.incident_id,
			'verified-repair-b',
			'b'.repeat(64),
			{
				generation: resolved.generation,
				updated_at: resolved.updated_at,
				occurrences: resolved.occurrences,
			},
		)).toBe(false);
		expect(store.get(validated.incident_id)).toMatchObject({ state: 'open', learning_status: 'none' });
	});

	describe('repair path and age-out', () => {
		const DAY = 86_400_000;
		const T0 = Date.parse('2026-08-12T08:00:00.000Z');

		it('records whether the latest failure carries a verifier path', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			expect(store.observeFailure(failure()).repair_path).toBe(false);
			expect(store.observeFailure(failure({ repair_path: true })).repair_path).toBe(true);
			expect(store.observeFailure(failure({ repair_path: false })).repair_path).toBe(false);
		});

		it('reads an incident saved before the field existed as having no repair path', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			const incident = store.observeFailure(failure({ repair_path: true }));
			const legacy = JSON.parse(readFileSync(store.path(), 'utf8')) as { incidents: Array<Record<string, unknown>> };
			delete legacy.incidents[0]?.['repair_path'];
			writeFileSync(store.path(), `${JSON.stringify(legacy)}
`, { mode: 0o600 });
			expect(store.get(incident.incident_id)?.repair_path).toBe(false);
		});

		it('requires repair only for an open incident that has a repair path', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			expect(store.requiresRepair()).toBe(false);
			store.observeFailure(failure({ repair_path: true }));
			expect(store.requiresRepair()).toBe(false); // observing: one failure
			store.observeFailure(failure({ repair_path: true }));
			expect(store.requiresRepair()).toBe(true);
			store.observeFailure(failure({ repair_path: false }));
			expect(store.requiresRepair()).toBe(false); // the latest failure has no path
			expect(store.actions()).toHaveLength(1); // still listed
		});

		it('ranks an incident with a repair path ahead of one without', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			for (let i = 0; i < 4; i += 1) store.observeFailure(failure());
			store.observeFailure(failure({ ability: 'stonewright-media-upload', error_code: 'upload_failed', cause_key: 'm', repair_path: true }));
			store.observeFailure(failure({ ability: 'stonewright-media-upload', error_code: 'upload_failed', cause_key: 'm', repair_path: true }));
			expect(store.actions()[0]?.ability).toBe('stonewright-media-upload');
		});

		it('resolves open and observing incidents after seven quiet days and leaves newer ones', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			const stale = store.observeFailure(failure({ timestamp: new Date(T0).toISOString() }));
			store.observeFailure(failure({
				ability: 'stonewright-media-upload', error_code: 'upload_failed', cause_key: 'm',
				timestamp: new Date(T0 + 3 * DAY).toISOString(),
			}));

			expect(store.expireStale(T0 + 7 * DAY - 1)).toBe(0);
			expect(store.expireStale(T0 + 7 * DAY)).toBe(1);
			const row = store.get(stale.incident_id)!;
			expect(row.state).toBe('resolved');
			expect(row.resolved_at).toBe(new Date(T0 + 7 * DAY).toISOString());
			expect(row.learning_status).toBe('none');
			expect(row.repair_receipt_id).toBeNull();
			expect(store.actions().map((a) => a.ability)).toEqual(['stonewright-media-upload']);
			expect(store.expireStale(T0 + 7 * DAY)).toBe(0); // idempotent
		});

		it('does not age out an incident whose latest failure has a repair path', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			const timestamp = new Date(T0).toISOString();
			const kept = store.observeFailure(failure({ timestamp, repair_path: true }));
			store.observeFailure(failure({ timestamp, repair_path: true }));
			const plain = store.observeFailure(failure({
				ability: 'stonewright-media-upload', error_code: 'upload_failed', cause_key: 'm', timestamp,
			}));
			expect(store.expireStale(T0 + 30 * DAY)).toBe(1);
			expect(store.get(kept.incident_id)?.state).toBe('open');
			expect(store.get(plain.incident_id)?.state).toBe('resolved');
			expect(store.requiresRepair()).toBe(true);
		});

		it('reopens an aged-out incident on a new matching failure and counts it', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			const first = store.observeFailure(failure({ timestamp: new Date(T0).toISOString() }));
			store.expireStale(T0 + 9 * DAY);
			const again = store.observeFailure(failure({ timestamp: new Date(T0 + 10 * DAY).toISOString() }));
			expect(again.incident_id).toBe(first.incident_id);
			expect(again.state).toBe('open');
			expect(again.reopened_count).toBe(1);
		});

		it('does not create state when there are no incidents', () => {
			const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
			const store = new DirectIncidentStore(baseDir, fingerprint('site-a'));
			expect(store.expireStale(T0)).toBe(0);
			expect(existsSync(join(baseDir, 'incidents'))).toBe(false);
		});
	});
});
