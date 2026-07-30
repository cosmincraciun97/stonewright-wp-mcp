import { describe, expect, it } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	saveSkill,
	listSkills,
	getSkill,
	deleteSkill,
	matchSkills,
} from '../src/direct/skills-store.js';

function tempBase() {
	return mkdtempSync(join(tmpdir(), 'sw-skills-'));
}

describe('skills store', () => {
	it('saves, lists compactly, and gets a skill', () => {
		const baseDir = tempBase();
		saveSkill({
			baseDir,
			scope: 'mysite',
			slug: 'wc-image-fix',
			name: 'WC image fix',
			description: 'How to fix product images',
			triggers: ['woocommerce', 'product image'],
			body: '# Steps\n1. Check media\n',
		});
		const index = listSkills({ baseDir, scope: 'mysite' });
		expect(index.items).toHaveLength(1);
		expect(index.items[0]).not.toHaveProperty('body');
		expect(index.items[0]?.slug).toBe('wc-image-fix');
		expect(getSkill({ baseDir, scope: 'mysite', slug: 'wc-image-fix' }).body).toContain('# Steps');
	});

	it('rejects invalid and traversal slugs', () => {
		const baseDir = tempBase();
		expect(() =>
			saveSkill({
				baseDir,
				scope: 'mysite',
				slug: '../../etc/passwd',
				name: 'x',
				description: 'd',
				triggers: [],
				body: 'b',
			}),
		).toThrow(/slug/i);
		expect(() => getSkill({ baseDir, scope: 'mysite', slug: '../evil' })).toThrow(/slug/i);
	});

	it('rejects bodies over 64KB', () => {
		const baseDir = tempBase();
		expect(() =>
			saveSkill({
				baseDir,
				scope: 'mysite',
				slug: 'big',
				name: 'n',
				description: 'd',
				triggers: [],
				body: 'x'.repeat(64_001),
			}),
		).toThrow(/64|size|body/i);
	});

	it('matches enabled skills by trigger terms only', () => {
		const baseDir = tempBase();
		saveSkill({
			baseDir,
			scope: 'mysite',
			slug: 'wc-image-fix',
			name: 'n',
			description: 'd',
			triggers: ['woocommerce'],
			body: 'b',
		});
		saveSkill({
			baseDir,
			scope: 'mysite',
			slug: 'off',
			name: 'n',
			description: 'd',
			triggers: ['woocommerce'],
			enabled: false,
			body: 'b',
		});
		const hits = matchSkills({ baseDir, scope: 'mysite', task: 'fix woocommerce product images' });
		expect(hits.map((h) => h.slug)).toEqual(['wc-image-fix']);
	});

	it('deletes a skill', () => {
		const baseDir = tempBase();
		saveSkill({
			baseDir,
			scope: 'mysite',
			slug: 'tmp',
			name: 'n',
			description: 'd',
			triggers: [],
			body: 'b',
		});
		deleteSkill({ baseDir, scope: 'mysite', slug: 'tmp' });
		expect(() => getSkill({ baseDir, scope: 'mysite', slug: 'tmp' })).toThrow(/not found/i);
	});
});
