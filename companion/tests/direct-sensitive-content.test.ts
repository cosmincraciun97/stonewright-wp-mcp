import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { recordMemory } from '../src/direct/memory-store.js';
import { containsSensitiveMaterial } from '../src/direct/sensitive-content.js';
import { saveSkill } from '../src/direct/skills-store.js';

describe('Direct persistent-state credential guard', () => {
	it('detects credential material but permits explicit placeholders and prohibitions', () => {
		const credentialName = 'pass' + 'word';
		const credential = ['real', 'private', 'value'].join('-');
		const appPassword = ['test', 'test', 'test', 'test', 'test', 'test'].join(' ');
		expect(containsSensitiveMaterial(`${credentialName}=${credential}`)).toBe(true);
		expect(containsSensitiveMaterial('Authorization: Bearer abcdefghijklmnop')).toBe(true);
		expect(containsSensitiveMaterial(appPassword)).toBe(true);
		expect(containsSensitiveMaterial('appPassword was real-private-value')).toBe(true);
		expect(containsSensitiveMaterial('https://user:real-private-value@example.com/wp-json/')).toBe(true);
		expect(containsSensitiveMaterial('password=<your-application-password>')).toBe(false);
		expect(containsSensitiveMaterial('password=${STONEWRIGHT_WP_APP_PASSWORD}')).toBe(false);
		expect(containsSensitiveMaterial('password=xxxx xxxx xxxx xxxx xxxx xxxx')).toBe(false);
		expect(containsSensitiveMaterial('confirmation_token=required')).toBe(false);
		expect(containsSensitiveMaterial('Never store passwords or API keys.')).toBe(false);
	});

	it('refuses credential material in memory and user skills', () => {
		const baseDir = mkdtempSync(join(tmpdir(), 'sw-sensitive-state-'));

		expect(() =>
			recordMemory({
				baseDir,
				scope: '_global',
				text: 'Use application_' + 'password=' + ['real', 'private', 'value'].join('-'),
			}),
		).toThrow(/credential material/);
		expect(() =>
			saveSkill({
				baseDir,
				scope: '_global',
				slug: 'bad-secret',
				name: 'Bad secret',
				description: 'Unsafe',
				triggers: ['unsafe'],
				body: 'Authorization: Bearer abcdefghijklmnop',
			}),
		).toThrow(/credential material/);
	});
});
