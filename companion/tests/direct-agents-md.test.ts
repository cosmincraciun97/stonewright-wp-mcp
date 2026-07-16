import { describe, expect, it } from 'vitest';
import { mkdtempSync, writeFileSync, readFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	ensureStonewrightAgentsMd,
	agentsMdSync,
	agentsMdTemplate,
	MARK_START,
	POINTER_MARKER,
} from '../src/direct/agents-md.js';
import { seedBuiltinSkills } from '../src/direct/skills-store.js';

describe('agents-md', () => {
	it('creates managed AGENTS.md once and is idempotent', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-agents-'));
		const a = ensureStonewrightAgentsMd(dir);
		expect(a.created).toBe(true);
		expect(existsSync(a.path)).toBe(true);
		const b = ensureStonewrightAgentsMd(dir);
		expect(b.created).toBe(false);
		expect(b.updated).toBe(false);
		expect(readFileSync(a.path, 'utf8')).toContain(MARK_START);
	});

	it('preserves user lines outside managed markers on update', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-agents2-'));
		const path = join(dir, 'AGENTS.md');
		writeFileSync(path, `# User top\n\n${agentsMdTemplate()}\n# User bottom\n`, 'utf8');
		// Force rewrite by touching template path via ensure (same content → no update).
		// Append junk inside managed to force replacement:
		const corrupted = readFileSync(path, 'utf8').replace(
			'Start every WordPress task',
			'CORRUPTED Start every WordPress task',
		);
		writeFileSync(path, corrupted, 'utf8');
		const r = ensureStonewrightAgentsMd(dir);
		expect(r.updated).toBe(true);
		const body = readFileSync(path, 'utf8');
		expect(body).toContain('# User top');
		expect(body).toContain('# User bottom');
		expect(body).toContain('Start every WordPress task');
		expect(body).not.toContain('CORRUPTED');
	});

	it('sync is read-only on external configs and returns pointer snippet', () => {
		const home = mkdtempSync(join(tmpdir(), 'sw-home-'));
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const claude = join(home, '.claude');
		// ensureStonewrightAgentsMd uses STONEWRIGHT_STATE_DIR via defaultStateDir
		const env = { HOME: home, STONEWRIGHT_STATE_DIR: state };
		const result = agentsMdSync(env, { extra_paths: [join(home, 'AGENTS.md')] });
		expect(result.pointer_snippet).toContain(POINTER_MARKER);
		expect(result.agents_md).toContain('AGENTS.md');
		expect(result.targets.length).toBeGreaterThan(0);
		expect(existsSync(claude)).toBe(false); // never created external dirs
	});
});

describe('builtin skills seed', () => {
	it('copy-if-missing preserves user edits and restores deleted files', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-seed-'));
		const first = seedBuiltinSkills(dir);
		expect(first.seeded.length).toBeGreaterThan(0);
		const skillPath = join(dir, 'skills', '_builtin', 'elementor-direct-editing.md');
		expect(existsSync(skillPath)).toBe(true);
		writeFileSync(skillPath, 'USER EDIT', 'utf8');
		const second = seedBuiltinSkills(dir);
		expect(second.skipped.length).toBeGreaterThan(0);
		expect(readFileSync(skillPath, 'utf8')).toBe('USER EDIT');
		// delete and re-seed
		rmSync(skillPath);
		const third = seedBuiltinSkills(dir);
		expect(third.seeded.some((n) => n.includes('elementor'))).toBe(true);
		expect(readFileSync(skillPath, 'utf8')).toContain('Elementor');
	});
});
