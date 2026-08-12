import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	DirectIncidentStore,
	type DirectIncidentFailure,
} from '../src/direct/incidents.js';

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

		store.markResolved(second.incident_id, {
			repair_receipt_id: 'a'.repeat(64),
			resolution_event_id: '33333333-3333-4333-8333-333333333333',
			resolved_at: '2026-08-12T08:02:00.000Z',
		});
		store.markLearningPromoted(second.incident_id, 'verified-repair-a', 'a'.repeat(64));
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

	it('stores only bounded normalized fields with private permissions', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-direct-incidents-'));
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
		expect(statSync(path).mode & 0o777).toBe(0o600);
		expect(statSync(join(baseDir, 'incidents')).mode & 0o777).toBe(0o700);
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
});
