import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { hashSkillText, seedBuiltinSkills } from '../src/direct/skills-store.js';

const sha = (text: string): string => createHash('sha256').update(text.replace(/\r\n/g, '\n')).digest('hex');
const packaged = join(dirname(fileURLToPath(import.meta.url)), '..', 'skills-builtin');

function sourcePack(files: Record<string, string>, previous: Record<string, string[]> = {}): string {
	const dir = mkdtempSync(join(tmpdir(), 'sw-pack-'));
	const manifest: Record<string, { sha256: string; previous: string[] }> = {};
	for (const [name, text] of Object.entries(files)) {
		writeFileSync(join(dir, name), text, 'utf8');
		manifest[name] = { sha256: sha(text), previous: (previous[name] ?? []).map(sha) };
	}
	writeFileSync(join(dir, 'manifest.json'), JSON.stringify({ version: 1, files: manifest }), 'utf8');
	return dir;
}

function installed(state: string, name: string): string {
	return join(state, 'skills', '_builtin', name);
}

describe('built-in skill refresh', () => {
	it('replaces an unedited copy of an earlier shipped version and records it as refreshed', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		seedBuiltinSkills(state, {}, sourcePack({ 'alpha.md': 'old text\n' }));
		const next = sourcePack({ 'alpha.md': 'new text\n' }, { 'alpha.md': ['old text\n'] });

		const result = seedBuiltinSkills(state, {}, next);

		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('new text\n');
		expect(result.refreshed).toEqual(['alpha.md']);
		expect(result.edited).toEqual([]);
		expect(result.seeded).toEqual([]);
	});

	it('leaves an edited copy alone and reports it', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		seedBuiltinSkills(state, {}, sourcePack({ 'alpha.md': 'old text\n' }));
		writeFileSync(installed(state, 'alpha.md'), 'my own edit\n', 'utf8');
		const next = sourcePack({ 'alpha.md': 'new text\n' }, { 'alpha.md': ['old text\n'] });

		const result = seedBuiltinSkills(state, {}, next);
		const again = seedBuiltinSkills(state, {}, next);

		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('my own edit\n');
		expect(result.refreshed).toEqual([]);
		expect(result.edited).toEqual(['alpha.md']);
		expect(again.edited).toEqual(['alpha.md']);
	});

	it('treats a copy that matches no shipped version as edited, even when it is newer than the manifest knows', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const next = sourcePack({ 'alpha.md': 'new text\n' }, { 'alpha.md': ['old text\n'] });
		mkdirSync(join(state, 'skills', '_builtin'), { recursive: true });
		writeFileSync(installed(state, 'alpha.md'), 'unknown earlier text\n', 'utf8');

		const result = seedBuiltinSkills(state, {}, next);

		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('unknown earlier text\n');
		expect(result.edited).toEqual(['alpha.md']);
	});

	it('does not touch a copy that is already current, and ignores line-ending differences', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const pack = sourcePack({ 'alpha.md': 'line one\nline two\n' });
		seedBuiltinSkills(state, {}, pack);
		writeFileSync(installed(state, 'alpha.md'), 'line one\r\nline two\r\n', 'utf8');

		const result = seedBuiltinSkills(state, {}, pack);

		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('line one\r\nline two\r\n');
		expect(result.refreshed).toEqual([]);
		expect(result.edited).toEqual([]);
		expect(result.skipped).toEqual(['alpha.md']);
	});

	it('restores a deleted copy and copies a new file', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const pack = sourcePack({ 'alpha.md': 'a\n', 'beta.md': 'b\n' });
		seedBuiltinSkills(state, {}, pack);
		rmSync(installed(state, 'alpha.md'));

		const result = seedBuiltinSkills(state, {}, pack);

		expect(result.seeded).toEqual(['alpha.md']);
		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('a\n');
	});

	it('falls back to copy-if-missing when the manifest is absent or unreadable', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const pack = sourcePack({ 'alpha.md': 'new text\n' }, { 'alpha.md': ['old text\n'] });
		mkdirSync(join(state, 'skills', '_builtin'), { recursive: true });
		writeFileSync(installed(state, 'alpha.md'), 'old text\n', 'utf8');

		writeFileSync(join(pack, 'manifest.json'), '{ not json', 'utf8');
		const broken = seedBuiltinSkills(state, {}, pack);
		rmSync(join(pack, 'manifest.json'));
		const missing = seedBuiltinSkills(state, {}, pack);

		expect(readFileSync(installed(state, 'alpha.md'), 'utf8')).toBe('old text\n');
		expect(broken.refreshed).toEqual([]);
		expect(missing.refreshed).toEqual([]);
	});

	it('never writes outside the built-in folder and ignores unsafe manifest names', () => {
		const state = mkdtempSync(join(tmpdir(), 'sw-state-'));
		const pack = sourcePack({ 'alpha.md': 'a\n' });
		writeFileSync(
			join(pack, 'manifest.json'),
			JSON.stringify({ version: 1, files: { '../escape.md': { sha256: sha('x'), previous: [sha('y')] }, 'alpha.md': { sha256: sha('a\n'), previous: [] } } }),
			'utf8',
		);

		seedBuiltinSkills(state, {}, pack);

		expect(readdirSync(join(state, 'skills', '_builtin'))).toEqual(['alpha.md']);
		expect(existsSync(join(state, 'skills', 'escape.md'))).toBe(false);
	});
});

describe('packaged built-in skill manifest', () => {
	const manifest = JSON.parse(readFileSync(join(packaged, 'manifest.json'), 'utf8')) as {
		version: number;
		files: Record<string, { sha256: string; previous: string[] }>;
	};
	const shipped = readdirSync(packaged).filter((name) => name.endsWith('.md')).sort();

	it('lists every shipped skill with its current hash', () => {
		expect(manifest.version).toBe(1);
		expect(Object.keys(manifest.files).sort()).toEqual(shipped);
		for (const name of shipped) {
			expect(manifest.files[name]?.sha256, name).toBe(hashSkillText(readFileSync(join(packaged, name), 'utf8')));
		}
	});

	it('keeps earlier shipped hashes distinct from the current one', () => {
		for (const [name, entry] of Object.entries(manifest.files)) {
			expect(entry.previous, name).not.toContain(entry.sha256);
			expect(new Set(entry.previous).size, name).toBe(entry.previous.length);
			for (const hash of entry.previous) {
				expect(hash, name).toMatch(/^[0-9a-f]{64}$/);
			}
		}
	});

	it('knows the copy every packaged skill had in the previous release', () => {
		const previousRelease: Record<string, string> = {
			'content-model-additive.md': 'dbc58d9018b03c25bc1177a32067e4778ebebc8f0fc65fb1eabf9f4f9c912b18',
			'elementor-direct-editing.md': 'ecbe553dde496f12fe20699a0d066dd77476be21e782628b85b873ea87ae74f4',
			'gutenberg-authoring.md': 'c2f1956d66a2f12179443b4f4150a1a45dcf267391f9c79d0f644554021f7ec0',
			'http-first-admin.md': '5b93bcd3df29392253d4549d9b826e095938f6e01f108ade01c98b9186ea3a14',
			'no-ad-hoc-plugins.md': '2763bb7df9aa24a944c75e231d813180f5eb07d8f9e16ed5fb165869447dd21b',
			'no-hallucination-protocol.md': '0d9556af44b5aec845aa26de5313030e3146c51623ceb9078756a83ab2651c2c',
			'remote-direct-tool-path.md': 'c8d8a585270a7d79d57d127d79579258d6e9f7b6b16a277ce1806677ad87797b',
			'single-environment-scope.md': 'fe07fdf5306b72d07e5edcf1311b4f099ae0efdfa234d0efa4a2f3fbdb648386',
		};
		for (const [name, hash] of Object.entries(previousRelease)) {
			const entry = manifest.files[name];
			expect([entry?.sha256, ...(entry?.previous ?? [])], name).toContain(hash);
		}
	});

	it('knows the earlier texts that older releases installed', () => {
		const earlier: Record<string, string[]> = {
			'elementor-direct-editing.md': [
				'65552631863c4727671e412f5a50be4620a3b3185378b970802f971af56b0795',
				'bba0d717eb20324bcbb919fd4d94080c8ad4cf53805b243eeb3d2fac1324499c',
			],
			'gutenberg-authoring.md': ['1e81f17787330e053cd5c82b556ae47b78d51173b30dbf63a375e6576729fb20'],
			'no-ad-hoc-plugins.md': ['b7552d455c3da516e026f15239156fb70c8eea25140287c435db063301e22eaf'],
			'remote-direct-tool-path.md': ['29df2ccf4b0b271735f36e3ed4a1d2bf24db6f4464445794411c49e2265b64f9'],
		};
		for (const [name, hashes] of Object.entries(earlier)) {
			for (const hash of hashes) {
				expect(manifest.files[name]?.previous, name).toContain(hash);
			}
		}
	});
});
