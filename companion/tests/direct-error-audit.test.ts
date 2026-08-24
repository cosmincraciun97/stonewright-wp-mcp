import { describe, expect, it, beforeEach, afterEach, vi } from 'vitest';
import { mkdtempSync, readFileSync, existsSync, readdirSync, statSync, unlinkSync, utimesSync, writeFileSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { build } from 'esbuild';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { appendDirectAudit as appendDirectAuditRaw, directTerminalAuditReceipt, recentRecurringErrors, defaultAuditPath, rotateDirectAudit, withDirectAuditReceiptContext, type DirectAuditEntry, type DirectAuditRotationPolicy } from '../src/direct/audit.js';
import { createMcpServer } from '../src/mcp-server.js';
import { resetTaskStartSeenForTests } from '../src/direct/writes.js';

function appendDirectAudit(entry: DirectAuditEntry, path?: string, rotation?: DirectAuditRotationPolicy) {
	const identities: Record<string, string> = {
		's': 'https://site-s.example.test',
		'site-a': 'https://site-a.example.test',
		'site-b': 'https://site-b.example.test',
		'default': 'https://default.example.test',
		'_global': 'direct-global:_global',
	};
	return appendDirectAuditRaw({
		...entry,
		targetIdentity: entry.targetIdentity ?? identities[entry.site] ?? 'https://fixture.example.test',
	}, path, rotation);
}

function pemBlock(kind: string, body: string): string {
	return `-----BEGIN ${kind}-----\n${body}\n-----END ${kind}-----`;
}

describe('direct error audit', () => {
	let stateDir: string;
	const spawnedChildren: Array<ReturnType<typeof spawn>> = [];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-err-audit-'));
		resetTaskStartSeenForTests();
	});

	afterEach(() => {
		resetTaskStartSeenForTests();
		for (const child of spawnedChildren.splice(0)) {
			if (child.exitCode === null && child.signalCode === null) {
				try { child.kill('SIGKILL'); } catch { /* already gone */ }
			}
		}
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
			operationId: '33333333-3333-4333-8333-333333333333',
			parentEventId: '44444444-4444-4444-8444-444444444444',
			attempt: 2,
			idempotencyKey: 'direct:content:42:terminal',
			lifecyclePhase: 'terminal',
			terminalOwner: 'direct-registry',
		};

		const first = appendDirectAudit(input, path);
		const duplicate = appendDirectAudit(input, path);
		const rows = readFileSync(path, 'utf8').trim().split('\n').map((line): unknown => JSON.parse(line) as unknown);

		expect(rows).toHaveLength(1);
		expect(duplicate).toEqual(first);
		expect(first).toMatchObject({
			schema_version: '2.0',
			event_id: '11111111-1111-4111-8111-111111111111',
			correlation_id: '22222222-2222-4222-8222-222222222222',
			operation_id: '33333333-3333-4333-8333-333333333333',
			parent_event_id: '44444444-4444-4444-8444-444444444444',
			attempt: 2,
			lifecycle_phase: 'terminal',
			terminal: true,
			terminal_owner: 'direct-registry',
		});
		expect(String(first['idempotency_key'])).toMatch(/^[a-f0-9]{64}$/);
	});

	it('does not let a repointed alias suppress the new target terminal row', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const terminal = {
			tool: 'stonewright-content-update',
			site: 'site-a',
			status: 'error' as const,
			code: 'write_failed',
			resource: 'pages/42',
			idempotencyKey: 'same-client-terminal-key',
			operationId: '11111111-1111-4111-8111-111111111111',
			payload: { code: 'write_failed' },
		};

		const first = appendDirectAudit({
			...terminal,
			targetIdentity: 'https://old.example.test',
		}, path);
		const second = appendDirectAudit({
			...terminal,
			targetIdentity: 'https://new.example.test',
		}, path);
		const rows = readFileSync(path, 'utf8').trim().split('\n').map((line) => JSON.parse(line) as Record<string, unknown>);

		expect(rows).toHaveLength(2);
		expect(second['request_id']).not.toBe(first['request_id']);
		expect(second.site_fingerprint).not.toBe(first.site_fingerprint);
	});

	it('binds caller idempotency to ability resource payload status and operation', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const base = {
			tool: 'stonewright-content-update', site: 'site-a', resource: 'post:42', status: 'ok' as const,
			idempotencyKey: 'caller-key', operationId: '33333333-3333-4333-8333-333333333333', payload: { title: 'First' },
		};
		const first = appendDirectAudit(base, path);
		const replay = appendDirectAudit(base, path);
		const changedTool = appendDirectAudit({ ...base, tool: 'stonewright-settings-update' }, path);
		const changedResource = appendDirectAudit({ ...base, resource: 'post:43' }, path);
		const changedPayload = appendDirectAudit({ ...base, payload: { title: 'Second' } }, path);
		const changedStatus = appendDirectAudit({ ...base, status: 'error' }, path);
		const changedOperation = appendDirectAudit({ ...base, operationId: '55555555-5555-4555-8555-555555555555' }, path);

		expect(replay['idempotency_key']).toBe(first['idempotency_key']);
		expect(changedTool['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(changedResource['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(changedPayload['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(changedStatus['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(changedOperation['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(6);
	});

	it('never replays a terminal audit receipt from another site', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const base = {
			tool: 'stonewright-content-update', resource: 'post:42', status: 'ok' as const,
			idempotencyKey: 'caller-key', operationId: '33333333-3333-4333-8333-333333333333', payload: { title: 'Same' },
		};
		const first = appendDirectAudit({ ...base, site: 'site-a' }, path);
		const second = appendDirectAudit({ ...base, site: 'site-b' }, path);

		expect(second['idempotency_key']).not.toBe(first['idempotency_key']);
		expect(second['site_fingerprint']).not.toBe(first['site_fingerprint']);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(2);
	});

	it('uses canonical read health runtime write and safety classifications', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const health = appendDirectAudit({ tool: 'stonewright-health-check', site: 's', status: 'ok' }, path);
		const read = appendDirectAudit({ tool: 'stonewright-content-get', site: 's', status: 'ok' }, path);
		const runtime = appendDirectAudit({ tool: 'stonewright-php-execute', site: 's', status: 'ok' }, path);
		const write = appendDirectAudit({ tool: 'stonewright-content-update', site: 's', status: 'ok' }, path);
		const blocked = appendDirectAudit({ tool: 'stonewright-content-update', site: 's', status: 'blocked' }, path);

		expect(health).toMatchObject({ category: 'HEALTH', operation_class: 'HEALTH' });
		expect(read).toMatchObject({ category: 'READ', operation_class: 'READ' });
		expect(runtime).toMatchObject({ category: 'RUNTIME', operation_class: 'EXECUTION' });
		expect(write).toMatchObject({ category: 'WRITE', operation_class: 'WRITE' });
		expect(blocked).toMatchObject({ category: 'SAFETY', operation_class: 'SAFETY', outcome: 'BLOCKED' });
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

	it('returns one terminal receipt with a bounded secondary error when incident persistence fails', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		writeFileSync(join(stateDir, 'incidents'), 'synthetic-conflict\n', { mode: 0o600 });
		let contextReceipt: Record<string, unknown> | null = null;
		const input = {
			tool: 'stonewright-content-update', site: 'site-a', status: 'error' as const,
			code: 'write_failed', causeKey: 'content-update|write_failed',
			idempotencyKey: 'incident-persistence-failure',
			operationId: '33333333-3333-4333-8333-333333333333',
		};

		const first = withDirectAuditReceiptContext(() => {
			const receipt = appendDirectAudit(input, path);
			contextReceipt = directTerminalAuditReceipt();
			return receipt;
		});
		const replay = appendDirectAudit(input, path);

		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
		expect(first).toMatchObject({
			terminal: true,
			secondary_errors: [{ component: 'incident_store', code: 'incident_persistence_failed' }],
		});
		expect(contextReceipt).toEqual(first);
		expect(replay['idempotency_key']).toBe(first['idempotency_key']);
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
		expect(p).toBe(join(stateDir, 'audit-direct.jsonl'));
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

		expect(receipt?.reason).toBe('size');
		expect(typeof receipt?.previous_bytes).toBe('number');
		expect(receipt?.archive).toMatch(/^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/);
		expect(existsSync(path)).toBe(false);
		const receiptBody = readFileSync(`${path}.rotation-receipts.jsonl`, 'utf8');
		expect(receiptBody).not.toContain('private-value');
		expect(receiptBody).not.toContain(stateDir);
		const archiveBody = readFileSync(join(stateDir, String(receipt?.archive)), 'utf8');
		expect(archiveBody).not.toContain('private-value');
		expect(archiveBody).toContain('[redacted]');
		expect(readdirSync(stateDir).filter((name) => /^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/.test(name))).toHaveLength(1);
	});

	it('recursively redacts key material certificates and credential blobs in legacy archives', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const legacy = {
			visible: 'safe-before',
			nested: [
				{ private_key: 'sentinel-private-key-snake' },
				{ privateKey: 'sentinel-private-key-camel' },
				{ key_pem: 'sentinel-key-pem' },
				{ clientCertificate: 'sentinel-certificate-blob' },
				{ credentials: { value: 'sentinel-credential-blob' } },
			],
			note: [
				'safe-before-pem',
				pemBlock('OPENSSH PRIVATE KEY', 'sentinel-openssh-key'),
				pemBlock('CERTIFICATE', 'sentinel-pem-certificate'),
				'safe-after-pem',
			].join('\n'),
			padding: 'x'.repeat(300),
		};
		writeFileSync(path, `${JSON.stringify(legacy)}\n`, { mode: 0o600 });

		const receipt = rotateDirectAudit(path, {
			maxBytes: 128,
			maxFiles: 2,
			now: new Date('2026-08-24T00:00:00.000Z'),
		});

		const archiveBody = readFileSync(join(stateDir, String(receipt?.archive)), 'utf8');
		for (const sentinel of [
			'sentinel-private-key-snake',
			'sentinel-private-key-camel',
			'sentinel-key-pem',
			'sentinel-certificate-blob',
			'sentinel-credential-blob',
			'sentinel-openssh-key',
			'sentinel-pem-certificate',
		]) {
			expect(archiveBody).not.toContain(sentinel);
		}
		expect(archiveBody).toContain('safe-before');
		expect(archiveBody).toContain('safe-before-pem');
		expect(archiveBody).toContain('safe-after-pem');
		expect(Buffer.byteLength(archiveBody)).toBeLessThan(1200);
	});

	it('archives corrupt legacy rows without retaining secret-derived fingerprints', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const corrupt = 'not-json password=legacy-private-value';
		writeFileSync(path, `${corrupt}\n${'x'.repeat(300)}\n`, { mode: 0o600 });

		const receipt = rotateDirectAudit(path, {
			maxBytes: 128,
			maxFiles: 2,
			now: new Date('2026-08-24T00:00:00.000Z'),
		});

		const archiveBody = readFileSync(join(stateDir, String(receipt?.archive)), 'utf8');
		expect(archiveBody).not.toContain('legacy-private-value');
		expect(archiveBody).not.toContain('sha256');
		expect(archiveBody).toContain('"event_type":"legacy_corrupt"');
		expect(archiveBody).toContain('"redacted":true');
	});

	it('recovers an abandoned empty terminal marker after a crash', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const input = {
			tool: 'stonewright-content-update', site: 's', resource: 'post:42', status: 'ok' as const,
			idempotencyKey: 'crash-key', operationId: '33333333-3333-4333-8333-333333333333', payload: { title: 'safe' },
		};
		appendDirectAudit(input, path);
		const markerDir = join(stateDir, '.audit-idempotency');
		const marker = join(markerDir, readdirSync(markerDir)[0]);
		writeFileSync(path, '', { mode: 0o600 });
		writeFileSync(marker, '', { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(marker, stale, stale);

		const recovered = appendDirectAudit(input, path);
		expect(recovered['status']).toBe('ok');
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	});

	it('recovers an old malformed audit lock by file age', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, '{"pid":', { mode: 0o600 });
		const stale = new Date(Date.now() - 120_000);
		utimesSync(lock, stale, stale);

		const row = appendDirectAudit({ tool: 'stonewright-content-get', site: 'site-a', status: 'ok' }, path);

		expect(row['status']).toBe('ok');
		expect(existsSync(lock)).toBe(false);
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	}, 10_000);

	it('does not steal a fresh malformed audit lock', async () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const lock = `${path}.lock`;
		writeFileSync(lock, '', { mode: 0o600 });
		const fixture = join(process.cwd(), 'tests', 'fixtures', 'direct-audit-writer.ts');
		const runner = join(stateDir, 'direct-audit-live-lock-writer.mjs');
		await build({ entryPoints: [fixture], bundle: true, platform: 'node', format: 'esm', outfile: runner });
		const child = spawn(process.execPath, [runner, path, 'a', '1'], { stdio: 'pipe' });
		let exited = false;
		child.on('exit', () => { exited = true; });

		await new Promise((resolve) => setTimeout(resolve, 100));
		expect(exited).toBe(false);
		expect(existsSync(path)).toBe(false);

		unlinkSync(lock);
		await new Promise<void>((resolve, reject) => {
			child.once('exit', (code) => code === 0 ? resolve() : reject(new Error(`writer exited ${code}`)));
		});
		expect(readFileSync(path, 'utf8').trim().split('\n')).toHaveLength(1);
	}, 10_000);

	it('prunes stale and overflow terminal markers under the audit lock', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const markerDir = join(stateDir, '.audit-idempotency');
		writeFileSync(path, '', { mode: 0o600 });
		for (let index = 0; index < 1005; index += 1) {
			const marker = join(markerDir, index.toString(16).padStart(64, '0'));
			if (!existsSync(markerDir)) {
				appendDirectAudit({ tool: 'stonewright-bootstrap', site: 'site-a', status: 'ok', idempotencyKey: 'bootstrap-marker' }, path);
			}
			writeFileSync(marker, `${JSON.stringify({ idempotency_key: index.toString(16).padStart(64, '0') })}\n`, { mode: 0o600 });
			if (index < 5) {
				const expired = new Date(Date.now() - 45 * 24 * 60 * 60_000);
				utimesSync(marker, expired, expired);
			}
		}

		appendDirectAudit({ tool: 'stonewright-content-get', site: 'site-a', status: 'ok', idempotencyKey: 'compact-markers' }, path);

		const markers = readdirSync(markerDir).filter((name) => /^[a-f0-9]{64}$/.test(name));
		expect(markers.length).toBeLessThanOrEqual(1000);
		for (let index = 0; index < 5; index += 1) {
			expect(markers).not.toContain(index.toString(16).padStart(64, '0'));
		}
	});

	it('cleans interrupted marker receipt replacements and keeps the marker directory bounded', () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit({
			tool: 'stonewright-bootstrap',
			site: 'site-a',
			status: 'ok',
			idempotencyKey: 'bootstrap-temp-cleanup',
		}, path);
		const markerDir = join(stateDir, '.audit-idempotency');
		for (let index = 0; index < 70; index += 1) {
			const marker = index.toString(16).padStart(64, '0');
			const suffix = index.toString(16).padStart(12, '0');
			writeFileSync(
				join(markerDir, `${marker}.00000000-0000-4000-8000-${suffix}.tmp`),
				'{"state":"interrupted"}\n',
				{ mode: 0o600 },
			);
		}

		appendDirectAudit({
			tool: 'stonewright-content-get',
			site: 'site-a',
			status: 'ok',
			idempotencyKey: 'after-interrupted-replacement',
		}, path);

		const entries = readdirSync(markerDir);
		expect(entries.filter((name) => name.endsWith('.tmp'))).toHaveLength(0);
		expect(entries.length).toBeLessThanOrEqual(1000);
	});

	it('serializes concurrent rotation without losing or corrupting appends', async () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		const fixture = join(process.cwd(), 'tests', 'fixtures', 'direct-audit-writer.ts');
		const runner = join(stateDir, 'direct-audit-writer.mjs');
		await build({ entryPoints: [fixture], bundle: true, platform: 'node', format: 'esm', outfile: runner });
		const run = (worker: string) => new Promise<void>((resolve, reject) => {
			const child = spawn(process.execPath, [runner, path, worker, '25'], { stdio: 'pipe' });
			spawnedChildren.push(child);
			let stderr = '';
			child.stderr.on('data', (chunk) => { stderr += String(chunk); });
			child.on('exit', (code) => code === 0 ? resolve() : reject(new Error(`${worker} exited ${code}: ${stderr}`)));
		});

		await Promise.all([run('a'), run('b')]);
		const files = readdirSync(stateDir).filter((name) => name === 'audit-direct.jsonl' || /^audit-direct\.\d{8}T\d{6}Z\.[a-f0-9]{8}\.jsonl$/.test(name));
		const rows = files.flatMap((name) => readFileSync(join(stateDir, name), 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>));

		expect(rows).toHaveLength(50);
		expect(new Set(rows.map((row) => row['event_id'])).size).toBe(50);
		expect(rows.every((row) => row['schema_version'] === '2.0')).toBe(true);
	}, 15_000);

	it('audits a write guard denial as blocked safety before the tool body starts', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
				STONEWRIGHT_DIRECT_WRITES: 'off', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			},
			fetchImpl: () => Promise.reject(new Error('tool body must not run')),
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const result = await tools['stonewright-content-update']?.handler?.({ id: 42, title: 'Blocked' }) as { isError?: boolean };

		expect(result.isError).toBe(true);
		const row = JSON.parse(readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim()) as Record<string, unknown>;
		expect(row).toMatchObject({ tool: 'stonewright-content-update', status: 'blocked', category: 'SAFETY', outcome: 'BLOCKED' });
	});

	it('audits an always-confirm denial as blocked safety before the request starts', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
				STONEWRIGHT_DIRECT_WRITES: 'on', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			},
			fetchImpl: () => Promise.reject(new Error('request must not start')),
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		const result = await tools['stonewright-theme-activate']?.handler?.({ stylesheet: 'synthetic-theme' }) as { isError?: boolean };

		expect(result.isError).toBe(true);
		const row = JSON.parse(readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim()) as Record<string, unknown>;
		expect(row).toMatchObject({
			tool: 'stonewright-theme-activate', status: 'blocked', code: 'confirmation_required',
			category: 'SAFETY', operation_class: 'SAFETY', outcome: 'BLOCKED',
		});
	});

	it('blocks every always-confirm destructive tool when Direct writes are off', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', {
			status: 200,
			headers: { 'content-type': 'application/json' },
		})));
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
				STONEWRIGHT_DIRECT_WRITES: 'off', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			},
			fetchImpl,
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const calls: Array<[string, Record<string, unknown>]> = [
			['stonewright-theme-activate', { stylesheet: 'synthetic-theme', confirm: true }],
			['stonewright-plugin-delete', { plugin: 'synthetic/plugin', confirm: true }],
			['stonewright-user-delete', { id: 9, reassign: 1, confirm: true }],
			['stonewright-app-password-create', { user_id: 9, name: 'synthetic-client', confirm: true }],
			['stonewright-app-password-revoke', { user_id: 9, uuid: 'synthetic-uuid', confirm: true }],
			['stonewright-skill-delete', { slug: 'synthetic-skill', confirm: true }],
		];

		for (const [name, input] of calls) {
			const result = await tools[name]?.handler?.(input);
			expect(result?.isError, name).toBe(true);
			expect(JSON.parse(result?.content[0]?.text ?? '{}'), name).toMatchObject({
				blocked: true,
				error: 'direct_writes_disabled',
			});
		}
		expect(fetchImpl).not.toHaveBeenCalled();
	}, 15_000);

	it('requires task-start for every always-confirm destructive tool', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', {
			status: 200,
			headers: { 'content-type': 'application/json' },
		})));
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
				STONEWRIGHT_DIRECT_WRITES: 'on',
			},
			fetchImpl,
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const calls: Array<[string, Record<string, unknown>]> = [
			['stonewright-theme-activate', { stylesheet: 'synthetic-theme', confirm: true }],
			['stonewright-plugin-delete', { plugin: 'synthetic/plugin', confirm: true }],
			['stonewright-user-delete', { id: 9, reassign: 1, confirm: true }],
			['stonewright-app-password-create', { user_id: 9, name: 'synthetic-client', confirm: true }],
			['stonewright-app-password-revoke', { user_id: 9, uuid: 'synthetic-uuid', confirm: true }],
			['stonewright-skill-delete', { slug: 'synthetic-skill', confirm: true }],
		];

		for (const [name, input] of calls) {
			const result = await tools[name]?.handler?.(input);
			expect(result?.isError, name).toBe(true);
			expect(JSON.parse(result?.content[0]?.text ?? '{}'), name).toMatchObject({
				blocked: true,
				error: 'task_start_required',
			});
		}
		expect(fetchImpl).not.toHaveBeenCalled();
	}, 15_000);

	it('audits one ordinary Direct read failure from async dispatch metadata', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
			fetchImpl: () => Promise.resolve(new Response(JSON.stringify({ code: 'rest_unavailable', message: 'Synthetic read failure', data: { status: 503 } }), {
				status: 503,
				headers: { 'content-type': 'application/json' },
			})),
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<unknown> }> })._registeredTools ?? {};
		await tools['stonewright-content-list']?.handler?.({ type: 'posts' });

		const rows = readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim().split('\n').map((line) => JSON.parse(line) as Record<string, unknown>);
		expect(rows).toHaveLength(1);
		expect(rows[0]).toMatchObject({
			tool: 'stonewright-content-list',
			status: 'error',
			category: 'READ',
			outcome: 'FAILED',
		});
	});

	it('audits one Elementor-style structured read failure and opens one incident', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
				STONEWRIGHT_WP_CLI_BIN: join(stateDir, 'missing-wp-cli'), STONEWRIGHT_WP_CLI_DISABLE_HOST_DISCOVERY: '1',
			},
			fetchImpl: () => Promise.resolve(new Response(JSON.stringify({ code: 'rest_post_invalid_id', message: 'Synthetic missing document', data: { status: 404 } }), {
				status: 404,
				headers: { 'content-type': 'application/json' },
			})),
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const result = await tools['stonewright-elementor-data-get']?.handler?.({ post_id: 42 });
		const payload = JSON.parse(result?.content[0]?.text ?? '{}') as Record<string, unknown>;

		expect(payload).toMatchObject({ ok: false });
		const rows = readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim().split('\n').map((line) => JSON.parse(line) as Record<string, unknown>);
		expect(rows).toHaveLength(1);
		expect(rows[0]).toMatchObject({
			tool: 'stonewright-elementor-data-get',
			status: 'error',
			category: 'READ',
			outcome: 'FAILED',
		});
		const incidentDir = join(stateDir, 'incidents');
		expect(readdirSync(incidentDir).filter((name) => name.endsWith('.json'))).toHaveLength(1);
	});

	it('audits a structured read failure through the registry dispatch context when wrapper metadata is omitted', async () => {
		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
				STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
			},
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const result = await tools['stonewright-blueprint-get']?.handler?.({ id: 'missing-synthetic-blueprint' });
		const payload = JSON.parse(result?.content[0]?.text ?? '{}') as Record<string, unknown>;

		expect(payload).toMatchObject({ ok: false, error: 'not_found' });
		expect(readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim().split('\n')).toHaveLength(1);
	});

	it('persists one terminal audit row for one failed Direct write', async () => {
		const originalStateDir = process.env.STONEWRIGHT_STATE_DIR;
		const originalTaskGate = process.env.STONEWRIGHT_DIRECT_REQUIRE_TASK_START;
		process.env.STONEWRIGHT_STATE_DIR = stateDir;
		process.env.STONEWRIGHT_DIRECT_REQUIRE_TASK_START = 'off';
		try {
			const server = await createMcpServer({
				env: {
					STONEWRIGHT_MODE: 'direct', STONEWRIGHT_MCP_TOOL_PROFILE: 'full', STONEWRIGHT_STATE_DIR: stateDir,
					STONEWRIGHT_WP_URL: 'http://example.test', STONEWRIGHT_WP_USERNAME: 'admin', STONEWRIGHT_WP_APP_PASSWORD: 'pw',
					STONEWRIGHT_DIRECT_WRITES: 'on', STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
				},
				fetchImpl: () => Promise.resolve(new Response(JSON.stringify({ code: 'rest_write_failed', message: 'Synthetic write failure', data: { status: 500 } }), {
					status: 500,
					headers: { 'content-type': 'application/json' },
				})),
			});
			const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<unknown> }> })._registeredTools ?? {};
			await tools['stonewright-content-update']?.handler?.({ id: 42, title: 'Synthetic title' });

			const rows = readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8').trim().split('\n').map((line) => JSON.parse(line) as Record<string, unknown>);
			expect(rows).toHaveLength(1);
			expect(rows[0]).toMatchObject({
				tool: 'stonewright-content-update',
				status: 'error',
				terminal: true,
			});
		} finally {
			if (originalStateDir === undefined) delete process.env.STONEWRIGHT_STATE_DIR;
			else process.env.STONEWRIGHT_STATE_DIR = originalStateDir;
			if (originalTaskGate === undefined) delete process.env.STONEWRIGHT_DIRECT_REQUIRE_TASK_START;
			else process.env.STONEWRIGHT_DIRECT_REQUIRE_TASK_START = originalTaskGate;
		}
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
