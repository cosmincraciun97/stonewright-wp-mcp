import { describe, expect, it } from 'vitest';
import './helpers/task-start.js';
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
		// Point sites lookup at a missing path so local ~/.stonewright/sites.json
		// cannot leak a site alias into pluginless self-improve tests.
		env: {
			STONEWRIGHT_SITES_FILE: '/tmp/does-not-exist-sw-sites-selfimprove.json',
		} as NodeJS.ProcessEnv,
		baseDir: mkdtempSync(join(tmpdir(), 'sw-si-')),
		directToolCount: DIRECT_TOOL_NAMES.length,
	};
}

describe('direct self-improve tools', () => {
	it('exports seven wave-4 self-improve tools and total surface >= 98', () => {
		expect(DIRECT_WAVE4_SELFIMPROVE_TOOL_NAMES).toHaveLength(7);
		expect(DIRECT_TOOL_NAMES.length).toBeGreaterThanOrEqual(98);
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
		expect(result.verified).toBe(true);
		expect(result.stored).toBe(true);
		expect(result.backend).toBe('direct');
		expect(result.memory_id).toBeTruthy();
		expect(result.storage_ref).toMatch(/^direct:memory\//);
		expect(memoryList(c).items.some((i) => i.text.includes('alt text'))).toBe(true);
	});

	it('learning-record accepts canonical topic+correction and dedupes', () => {
		const c = ctx();
		const first = learningRecord(c, {
			topic: 'Device tabs',
			correction: 'Use toolbar device tabs only',
			scope: 'user',
			source: 'explicit-user-request',
		});
		expect(first.verified).toBe(true);
		expect(first.visibility).toMatch(/local-only/i);
		expect(first.memory_backend).toMatch(/direct/);
		const second = learningRecord(c, {
			topic: 'Device tabs',
			correction: 'Use toolbar device tabs only',
			scope: 'user',
		});
		expect(second.memory_id).toBe(first.memory_id);
		expect(memoryList(c).items.filter((i) => i.text.includes('toolbar device')).length).toBe(1);
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
		expect(start.capabilities.direct_tools).toBeGreaterThanOrEqual(98);
		expect(start.setup?.agents_md).toBeTruthy();
		expect(start.guidance.some((g) => /HARD RULE:.*single-target scope/i.test(g))).toBe(true);
		expect(start.guidance.some((g) => /HARD RULE:.*ad-hoc plugins/i.test(g))).toBe(true);
		expect(start.guidance.some((g) => /HARD RULE:.*HTTP-first/i.test(g))).toBe(true);
		expect(start.guidance.some((g) => /HARD RULE:.*Elementor responsive preview/i.test(g))).toBe(true);
		expect(start.guidance.some((g) => /HARD RULE:.*Verified learning/i.test(g))).toBe(true);
		expect(start.guidance.some((g) => /HARD RULE:.*Design section isolation/i.test(g))).toBe(true);
		expect(start.guidance.join('\n').toLowerCase()).not.toContain('transavia');
	});

	it('unknown site alias fails without writing _global', () => {
		const c = ctx();
		const before = memoryList(c).items.length;
		expect(() =>
			learningRecord(c, {
				topic: 'x',
				correction: 'must not land global',
				scope: 'project',
				site: 'totally-unknown-site-alias-xyz',
			}),
		).toThrow(/site_alias_unresolved/i);
		expect(memoryList(c).items.length).toBe(before);
	});

	it('task-start reports memory backend and local-only visibility', () => {
		const c = ctx();
		const start = taskStart(c, { task: 'repair audit' });
		expect(start.target_context?.memory_backend).toMatch(/direct/);
		expect(start.target_context?.memory_visibility).toMatch(/local-only/i);
	});
});
