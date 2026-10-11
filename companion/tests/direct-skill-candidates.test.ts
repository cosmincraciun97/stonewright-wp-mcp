import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { getSkill, saveSkill } from '../src/direct/skills-store.js';
import {
	learningRecord,
	skillGet,
	skillList,
	skillSave,
	taskStart,
	type SelfImproveContext,
} from '../src/direct/tools/self-improve.js';
import { memoryList } from '../src/direct/tools/self-improve.js';

let baseDir: string;

function ctx(): SelfImproveContext {
	return {
		env: { STONEWRIGHT_SITES_FILE: '/tmp/does-not-exist-sw-sites-candidates.json' } as NodeJS.ProcessEnv,
		baseDir,
		directToolCount: 0,
	};
}

const skill = {
	slug: 'wide-images',
	name: 'Wide images',
	description: 'How to place wide images',
	triggers: ['wide images'],
	body: '# Steps\n1. Use the image block with align wide.\n',
};

beforeEach(() => {
	baseDir = mkdtempSync(join(tmpdir(), 'sw-cand-'));
});

afterEach(() => {
	rmSync(baseDir, { recursive: true, force: true });
});

describe('a skill an agent saves is a candidate, never active', () => {
	it('is stored disabled and is not offered as a playbook at task start', () => {
		const out = skillSave(ctx(), skill);
		expect(out.enabled).toBe(false);
		expect(getSkill({ baseDir, scope: '_global', slug: 'wide-images' }).enabled).toBe(false);
		expect(taskStart(ctx(), { task: 'place wide images on the home page' }).matched_skills.map((row) => row.slug)).not.toContain('wide-images');
		// It stays readable as a note.
		expect(skillList(ctx()).items.find((row) => row.slug === 'wide-images')?.enabled).toBe(false);
		expect(skillGet(ctx(), { slug: 'wide-images' }).body).toContain('align wide');
	});

	it('says that a person or Plugin mode activates it', () => {
		const out = skillSave(ctx(), skill) as Record<string, unknown>;
		expect(String(out['note'])).toMatch(/Plugin mode/);
		expect(out['status']).toBe('candidate');
	});

	it('refuses enabled:true, saves nothing and points to Plugin mode', () => {
		expect(() => skillSave(ctx(), { ...skill, enabled: true })).toThrow(/Plugin mode/);
		expect(skillList(ctx()).items.some((row) => row.slug === 'wide-images')).toBe(false);
	});

	it('accepts enabled:false as the same candidate', () => {
		expect(skillSave(ctx(), { ...skill, enabled: false }).enabled).toBe(false);
	});

	it('does not change a skill that is already active', () => {
		saveSkill({ baseDir, scope: '_global', slug: 'wide-images', name: 'Wide images', description: 'active', triggers: ['wide images'], body: 'ORIGINAL BODY', enabled: true });
		expect(() => skillSave(ctx(), { ...skill, body: 'REPLACED BODY' })).toThrow(/active/i);
		const kept = getSkill({ baseDir, scope: '_global', slug: 'wide-images' });
		expect(kept.enabled).toBe(true);
		expect(kept.body).toBe('ORIGINAL BODY');
	});

	it('may replace a candidate that is still disabled', () => {
		skillSave(ctx(), skill);
		skillSave(ctx(), { ...skill, body: 'second draft' });
		expect(getSkill({ baseDir, scope: '_global', slug: 'wide-images' }).body).toBe('second draft');
	});

	it('does not touch a built-in skill of the same name', () => {
		saveSkill({ baseDir, scope: '_builtin', slug: 'wide-images', name: 'Built in', description: 'built in', triggers: ['wide images'], body: 'BUILT-IN BODY', enabled: true });
		skillSave(ctx(), skill);
		expect(getSkill({ baseDir, scope: '_builtin', slug: 'wide-images' }).body).toBe('BUILT-IN BODY');
		expect(getSkill({ baseDir, scope: '_builtin', slug: 'wide-images' }).enabled).toBe(true);
	});

	it('learning-record draft_skill cannot replace an active skill either, and records no lesson when it refuses', () => {
		saveSkill({ baseDir, scope: '_global', slug: 'wide-images', name: 'Wide images', description: 'active', triggers: ['wide images'], body: 'ORIGINAL BODY', enabled: true });
		expect(() => learningRecord(ctx(), { topic: 'images', correction: 'wide images need align wide', draft_skill: skill })).toThrow(/active/i);
		expect(getSkill({ baseDir, scope: '_global', slug: 'wide-images' }).body).toBe('ORIGINAL BODY');
		expect(memoryList(ctx()).items.some((row) => row.text.includes('align wide'))).toBe(false);
	});

	it('learning-record draft_skill stays a disabled candidate', () => {
		const out = learningRecord(ctx(), { topic: 'images', correction: 'wide images need align wide', draft_skill: skill });
		expect(out.skill?.enabled).toBe(false);
		expect(readFileSync(join(baseDir, 'skills', '_global', 'wide-images.md'), 'utf8')).toContain('"enabled": false');
	});

	it('task-start tells the agent that drafted skills stay disabled', () => {
		const start = taskStart(ctx(), { task: 'anything' });
		expect(JSON.stringify(start.required_followups)).toMatch(/disabled/i);
	});
});
