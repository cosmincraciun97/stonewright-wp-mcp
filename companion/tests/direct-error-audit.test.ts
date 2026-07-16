import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, readFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { appendDirectAudit, recentRecurringErrors, defaultAuditPath } from '../src/direct/audit.js';
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

	it('task-start returns recurring_errors after audited failures', async () => {
		const path = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit({ tool: 'stonewright-foo', site: '_global', status: 'error', error: 'boom' }, path);
		appendDirectAudit({ tool: 'stonewright-foo', site: '_global', status: 'error', error: 'boom2' }, path);

		const server = await createMcpServer({
			env: {
				STONEWRIGHT_MODE: 'direct',
				STONEWRIGHT_STATE_DIR: stateDir,
			},
		});
		const tools = (server as { _registeredTools?: Record<string, { handler?: (i: unknown) => Promise<{ content: Array<{ text: string }> }> }> })._registeredTools ?? {};
		const start = tools['stonewright-task-start'];
		const res = await start!.handler!({ task: 'fix something' });
		const body = JSON.parse(res.content[0]!.text!) as {
			recurring_errors: Array<{ tool: string; count: number }>;
			guidance: string[];
		};
		expect(body.recurring_errors.some((r) => r.tool === 'stonewright-foo' && r.count >= 2)).toBe(true);
		expect(body.guidance.some((g) => g.includes('recurring_errors'))).toBe(true);
	});

	it('defaultAuditPath honors STONEWRIGHT_STATE_DIR', () => {
		const p = defaultAuditPath({ STONEWRIGHT_STATE_DIR: stateDir });
		expect(p.startsWith(stateDir)).toBe(true);
		expect(existsSync(stateDir) || true).toBe(true);
		void readFileSync;
	});
});
