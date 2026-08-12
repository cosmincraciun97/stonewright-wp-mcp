import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { createMcpServer } from '../src/mcp-server.js';
import { appendDirectAudit } from '../src/direct/audit.js';
import { DirectIncidentStore, directIncidentFingerprint } from '../src/direct/incidents.js';
import { listMemory, recordMemory } from '../src/direct/memory-store.js';
import { listSkills, saveSkill } from '../src/direct/skills-store.js';

function directEnv(stateDir: string): NodeJS.ProcessEnv {
	return {
		STONEWRIGHT_MODE: 'direct',
		STONEWRIGHT_STATE_DIR: stateDir,
		STONEWRIGHT_SITES_FILE: join(stateDir, 'missing-sites.json'),
	};
}

describe('Direct persistent-state lifecycle', () => {
	it('starts with no user memory, user skills, or audit rows', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-direct-fresh-'));
		await createMcpServer({ env: directEnv(stateDir) });

		expect(listMemory({ baseDir: stateDir, scope: '_global' }).items).toEqual([]);
		expect(listSkills({ baseDir: stateDir, scope: '_global' }).items).toEqual([]);
		expect(existsSync(join(stateDir, 'audit-direct.jsonl'))).toBe(false);
		expect(existsSync(join(stateDir, 'incidents'))).toBe(false);
		expect(listSkills({ baseDir: stateDir, scope: '_builtin' }).items.length).toBeGreaterThan(0);
	});

	it('preserves memory, user skills, and audit across companion restarts and updates', async () => {
		const stateDir = mkdtempSync(join(tmpdir(), 'sw-direct-upgrade-'));
		const env = directEnv(stateDir);
		await createMcpServer({ env });

		recordMemory({
			baseDir: stateDir,
			scope: '_global',
			text: 'Prefer typed readback after writes.',
		});
		saveSkill({
			baseDir: stateDir,
			scope: '_global',
			slug: 'typed-readback',
			name: 'Typed readback',
			description: 'Verify writes.',
			triggers: ['write'],
			body: 'Read the target after every write.',
		});
		const auditPath = join(stateDir, 'audit-direct.jsonl');
		appendDirectAudit(
			{ tool: 'stonewright-content-update', site: 'default', status: 'error', code: 'write_failed' },
			auditPath,
		);
		appendDirectAudit(
			{ tool: 'stonewright-content-update', site: 'default', status: 'error', code: 'write_failed' },
			auditPath,
		);
		const beforeAudit = readFileSync(auditPath, 'utf8');
		const incidentStore = new DirectIncidentStore(stateDir, directIncidentFingerprint('default'));
		const beforeIncident = readFileSync(incidentStore.path(), 'utf8');

		await createMcpServer({ env });

		expect(listMemory({ baseDir: stateDir, scope: '_global' }).items).toHaveLength(1);
		expect(listSkills({ baseDir: stateDir, scope: '_global' }).items).toHaveLength(1);
		expect(readFileSync(auditPath, 'utf8')).toBe(beforeAudit);
		expect(readFileSync(incidentStore.path(), 'utf8')).toBe(beforeIncident);
		expect(new DirectIncidentStore(stateDir, directIncidentFingerprint('default')).list()[0])
			.toMatchObject({ state: 'open', occurrences: 2 });
	});
});
