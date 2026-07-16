import { describe, expect, it } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	skillSave,
	skillList,
	skillGet,
	skillDelete,
	learningRecord,
	memoryList,
	taskStart,
} from '../src/direct/tools/self-improve.js';
import { DIRECT_TOOL_NAMES, DIRECT_WAVE4_SELFIMPROVE_TOOL_NAMES } from '../src/direct/registry.js';

function ctx() {
	return {
		env: {} as NodeJS.ProcessEnv,
		baseDir: mkdtempSync(join(tmpdir(), 'sw-si-')),
		directToolCount: DIRECT_TOOL_NAMES.length,
	};
}

describe('direct self-improve tools', () => {
	it('exports seven wave-4 self-improve tools and total surface >= 90', () => {
		expect(DIRECT_WAVE4_SELFIMPROVE_TOOL_NAMES).toHaveLength(7);
		expect(DIRECT_TOOL_NAMES.length).toBeGreaterThanOrEqual(90);
	});

	it('save → list → get roundtrip without WordPress credentials', () => {
		const c = ctx();
		skillSave(c, {
			slug: 'wc-image-fix',
			name: 'WC image fix',
			description: 'Fix product images',
			triggers: ['woocommerce'],
			body: '# Steps\n1. media\n',
		});
		const list = skillList(c);
		expect(list.items.some((i) => i.slug === 'wc-image-fix')).toBe(true);
		expect(list.items[0]).not.toHaveProperty('body');
		expect(skillGet(c, { slug: 'wc-image-fix' }).body).toContain('# Steps');
	});

	it('delete requires confirm:true', () => {
		const c = ctx();
		skillSave(c, {
			slug: 'tmp',
			name: 't',
			description: 'd',
			triggers: [],
			body: 'b',
		});
		expect(() => skillDelete(c, { slug: 'tmp' })).toThrow(/confirm:true/i);
		skillDelete(c, { slug: 'tmp', confirm: true });
	});

	it('learning-record with draft_skill creates disabled skill', () => {
		const c = ctx();
		const result = learningRecord(c, {
			text: 'Always set alt text',
			kind: 'correction',
			draft_skill: {
				slug: 'alt-text',
				name: 'Alt text',
				description: 'Set alt',
				triggers: ['image'],
				body: 'Set alt always',
			},
		});
		expect(result.skill?.enabled).toBe(false);
		expect(memoryList(c).items.some((i) => i.text.includes('alt text'))).toBe(true);
	});

	it('task-start matches skills and returns direct mode without site', () => {
		const c = ctx();
		skillSave(c, {
			slug: 'wc-image-fix',
			name: 'WC',
			description: 'd',
			triggers: ['woocommerce'],
			body: 'b',
		});
		learningRecord(c, { text: 'remember media sizes', kind: 'lesson' });
		const start = taskStart(c, { task: 'update woocommerce products' });
		expect(start.mode).toBe('direct');
		expect(start.site).toBeNull();
		expect(start.matched_skills.some((s) => s.slug === 'wc-image-fix')).toBe(true);
		expect(start.memory_highlights.length).toBeGreaterThan(0);
		expect(start.capabilities.direct_tools).toBeGreaterThanOrEqual(90);
	});
});
