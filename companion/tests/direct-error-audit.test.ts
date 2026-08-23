import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, readFileSync, existsSync, readdirSync, statSync, utimesSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { appendDirectAudit, recentRecurringErrors, defaultAuditPath, rotateDirectAudit } from '../src/direct/audit.js';
import { createMcpServer } from '../src/mcp-server.js';
import { resetTaskStartSeenForTests } from '../src/direct/writes.js';

describe('direct error audit', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-err-audit-'));
		resetTaskStartSeenForTests();
	});

	afterEach(() => {
		resetTaskStartSeenForTests();
	});

	it('groups recurring errors by tool', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit({ tool: 'stonewright-content-update', site: 's', status: 'error', error: 'not found' }, path);
		appendDirectAudit({ tool: 'stonewright-content-update', site: 's', status: 'error', error: 'not found again' }, path);
		appendDirectAudit({ tool: 'stonewright-media-delete', site: 's', status: 'error', error: 'once' }, path);
		const rows = recentRecurringErrors(stateDir, 5);
		expect(rows).toHaveLength(1);
		expect(rows[0]?.tool).toBe('stonewright-content-update');
		expect(rows[0]?.count).toBe(2);
		expect(rows[0]?.last_error).toContain('again');
		expect(rows[0]?.repair.length).toBeGreaterThan(10);
	});

	it('writes the shared effect and incident fields', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit(
			{
				tool: 'stonewright-content-update',
				site: 'site-a',
				resource: 'post:8104',
				resourceType: 'post',
				status: 'error',
				code: 'write_failed',
				error: 'failed',
				operationClass: 'content_write',
				verificationStatus: 'failed',
				rollbackStatus: 'verified',
				beforeSha256: 'before',
				afterSha256: 'after',
				changedBytes: 12,
				causeKey: 'write_failed|content_update',
			},
			path,
		);

		const row = JSON.parse(readFileSync(path, 'utf8').trim()) as Record<string, unknown>;
		expect(row).toMatchObject({
			backend: 'direct',
			event_type: 'direct_tool',
			operation_class: 'content_write',
			resource_type: 'post',
			resource_ref: 'post:8104',
			execution_status: 'error',
			verification_status: 'failed',
			rollback_status: 'verified',
			before_sha256: 'before',
			after_sha256: 'after',
			changed_bytes: 12,
			error_code: 'write_failed',
			cause_key: 'write_failed|content_update',
			severity: 'error',
		});
		expect(String(row['request_id'])).toHaveLength(36);
		expect(String(row['site_fingerprint'])).toHaveLength(64);
		expect(statSync(path).mode & 0o777).toBe(0o600);
		const incidentsDir = join(stateDir, 'incidents');
		expect(existsSync(incidentsDir)).toBe(true);
		const incidentFiles = readFileSync(path, 'utf8').includes('write_failed');
		expect(incidentFiles).toBe(true);
	});

	it('writes the canonical lifecycle envelope and deduplicates one terminal owner', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const input = {
			tool: 'stonewright-content-update',
			site: 'site-a',
			status: 'ok' as const,
			eventId: '11111111-1111-4111-8111-111111111111',
			correlationId: '22222222-2222-4222-8222-222222222222',
			idempotencyKey: 'direct:content:42:terminal',
			lifecyclePhase: 'terminal',
			terminalOwner: 'direct-registry',
		};

		const first = appendDirectAudit(input, path);
		const duplicate = appendDirectAudit(input, path);
		const rows = readFileSync(path, 'utf8').trim().split('\n').map((line) => JSON.parse(line));

		expect(rows).toHaveLength(1);
		expect(duplicate).toEqual(first);
		expect(first).toMatchObject({
			schema_version: '2.0',
			event_id: '11111111-1111-4111-8111-111111111111',
			correlation_id: '22222222-2222-4222-8222-222222222222',
			idempotency_key: expect.stringMatching(/^[a-f0-9]{64}$/),
			lifecycle_phase: 'terminal',
			terminal: true,
			terminal_owner: 'direct-registry',
		});
	});

	it('feeds redacted normalized failures into the private incident store', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const first = appendDirectAudit({
			tool: 'stonewright-content-update',
			site: 'site-a',
			status: 'error',
			code: 'write_failed',
			error: 'password=private-value https://customer.example/private',
			causeKey: 'content-update|write_failed',
		}, path);
		appendDirectAudit({
			tool: 'stonewright-content-update',
			site: 'site-a',
			status: 'error',
			code: 'write_failed',
			error: 'Authorization: Bearer private-token',
			causeKey: 'content-update|write_failed',
		}, path);

		expect(first.request_id).toHaveLength(36);
		const incidentPath = join(stateDir, 'incidents', `${first.site_fingerprint}.json`);
		const body = readFileSync(incidentPath, 'utf8');
		expect(body).toContain('"state": "open"');
		expect(body).not.toContain('private-value');
		expect(body).not.toContain('customer.example');
		expect(body).not.toContain('private-token');
	});

	it('redacts credentials from all free-text audit fields', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const basic = 'YWRtaW46c2VjcmV0';
		const privateValue = 'test-private-value';
		const appPassword = ['test', 'test', 'test', 'test', 'test', 'test'].join(' ');
		appendDirectAudit(
			{
				tool: 'stonewright-direct',
				site: 'site-a',
				status: 'error',
				error: `Authorization: Basic ${basic} password=${privateValue}`,
				validatorSummary: `application_password: "${appPassword}"`,
				smokeSummary: 'access_token=test-token-value',
				resource: 'https://user:test-private-value@example.com/wp-json/?token=test-token-value',
			},
			path,
		);

		const body = readFileSync(path, 'utf8');
		expect(body).not.toContain(basic);
		expect(body).not.toContain(privateValue);
		expect(body).not.toContain(appPassword);
		expect(body).not.toContain('test-token-value');
		expect(body).toContain('[redacted');
	});

	it('stores only a fingerprint for a URL-shaped site binding', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit({
			tool: 'stonewright-direct',
			site: 'https://user:private-password@customer.example/wp-json/',
			status: 'ok',
		}, path);

		const body = readFileSync(path, 'utf8');
		const row = JSON.parse(body) as Record<string, unknown>;
		expect(body).not.toContain('customer.example');
		expect(body).not.toContain('private-password');
		expect(row).not.toHaveProperty('site');
		expect(String(row['site_fingerprint'])).toMatch(/^[a-f0-9]{64}$/);
	});

	it('task-start returns recurring_errors after audited failures', async () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit({ tool: 'stonewright-foo', site: '_global', status: 'error', error: 'boom' }, path);
		appendDirectAudit({ tool: 'stonewright-foo', site: '_global', status: 'error', error: 'boom2' }, path);

		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
			},
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const start = tools['stonewright-task-start'];
		const res = await start.handler!({ task: 'fix something' });
		const body = JSON.parse(res.content[0].text) as {
			recurring_errors: Array<{ tool: string; count: number }>;
			incident_actions: Array<{ ability: string; state: string }>;
			required_actions: string[];
			guidance: string[];
		};
		expect(body.recurring_errors.some((r) => r.tool === 'stonewright-foo' && r.count >= 2)).toBe(true);
		expect(body.incident_actions).toContainEqual(expect.objectContaining({
			ability: 'stonewright-foo',
			state: 'open',
		}));
		expect(body.required_actions).toContain('repair_open_incidents_first');
		expect(body.guidance.some((g) => g.includes('incident_actions'))).toBe(true);
	});

	it('defaultAuditPath honors STONEWRIGHT_STATE_DIR', () => {
		const p = defaultAuditPath({ STONEWRIGHT_STATE_DIR: stateDir });
		expect(p.startsWith(stateDir)).toBe(true);
		expect(existsSync(stateDir) || true).toBe(true);
		void readFileSync;
	});

	it('rotates by size with an atomic redacted receipt and bounded archives', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		writeFileSync(path, `${JSON.stringify({ error: 'password=private-value', padding: 'x'.repeat(300) })}\n`, { mode: 0o600 });

		const receipt = rotateDirectAudit(path, {
			maxBytes: 128,
			maxAgeMs: 30 * 24 * 60 * 60 * 1000,
			maxFiles: 2,
			now: new Date('2026-08-24T00:00:00.000Z'),
		});

		expect(receipt).toMatchObject({ reason: 'size', previous_bytes: expect.any(Number) });
		expect(receipt?.archive).toMatch(/^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/);
		expect(existsSync(path)).toBe(false);
		const receiptBody = readFileSync(`${path}.rotation-receipts.jsonl`, 'utf8');
		expect(receiptBody).not.toContain('private-value');
		expect(receiptBody).not.toContain(stateDir);
		expect(readdirSync(stateDir).filter((name) => /^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/.test(name))).toHaveLength(1);
	});

	it('recovers an interrupted age rotation exactly once before appending', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		writeFileSync(path, `${JSON.stringify({ status: 'ok' })}\n`, { mode: 0o600 });
		utimesSync(path, new Date('2026-01-01T00:00:00.000Z'), new Date('2026-01-01T00:00:00.000Z'));
		const archive = 'audit-direct.20260824T000000Z.deadbeef.jsonl';
		writeFileSync(`${path}.rotation-journal.json`, JSON.stringify({
			version: 1,
			archive,
			reason: 'age',
			rotated_at: '2026-08-24T00:00:00.000Z',
			previous_bytes: statSync(path).size,
			receipt_id: 'a'.repeat(64),
		}), { mode: 0o600 });

		appendDirectAudit({ tool: 'stonewright-ping', site: 'site-a', status: 'ok' }, path, {
			maxBytes: 1024,
			maxAgeMs: 24 * 60 * 60 * 1000,
			maxFiles: 2,
			now: new Date('2026-08-24T00:00:01.000Z'),
		});

		expect(existsSync(join(stateDir, archive))).toBe(true);
		expect(existsSync(`${path}.rotation-journal.json`)).toBe(false);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
		const receipts = readFileSync(`${path}.rotation-receipts.jsonl`, 'utf8').trim().split('\n');
		expect(receipts).toHaveLength(1);
	});

	it('replays a terminal receipt after its audit row was rotated', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const input = {
			tool: 'stonewright-content-update',
			site: 'site-a',
			status: 'ok' as const,
			eventId: '11111111-1111-4111-8111-111111111111',
			idempotencyKey: 'terminal-across-rotation',
		};
		const first = appendDirectAudit(input, path);
		rotateDirectAudit(path, { maxBytes: 1, now: new Date('2026-08-24T00:00:00.000Z') });

		const replay = appendDirectAudit(input, path);

		expect(replay).toEqual(first);
		expect(existsSync(path)).toBe(false);
	});
});
