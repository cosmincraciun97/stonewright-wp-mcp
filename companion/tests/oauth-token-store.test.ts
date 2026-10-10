import { beforeAll, describe, expect, it, vi } from 'vitest';
import { chmodSync, existsSync, linkSync, mkdirSync, readFileSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { OAuthTokenStore } from '../src/oauth-token-manager.js';
import { createOAuthTestDirectory, setSyntheticOAuthAcl, OAUTH_WARMUP_TIMEOUT_MS, warmUpOAuthStore } from './helpers/windows-oauth-acl.js';

// Windows: build and start the native ACL helper before the first test, not inside it.
beforeAll(warmUpOAuthStore, OAUTH_WARMUP_TIMEOUT_MS);

const initial = { accessToken: 'fixture-access', refreshToken: 'fixture-refresh', expiresAt: 0 };
const rotated = { accessToken: 'fixture-next-access', refreshToken: 'fixture-next-refresh', expiresAt: 120_000 };
const failure = vi.hoisted(() => ({ flush: false, rename: false }));
vi.mock('node:fs', async (importOriginal) => {
	const original = await importOriginal<typeof import('node:fs')>();
	return {
		...original,
		fsyncSync: (fd: number) => {
			if (failure.flush) throw Object.assign(new Error('Synthetic flush failure'), { code: 'EIO' });
			return original.fsyncSync(fd);
		},
		renameSync: (from: string, to: string) => {
			if (failure.rename && to.endsWith('oauth.json')) throw Object.assign(new Error('Synthetic replacement failure'), { code: 'EACCES' });
			return original.renameSync(from, to);
		},
	};
});

describe('OAuth token storage', () => {
	it('refuses hard-linked token files without replacing or deleting them', () => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const copy = join(directory, 'hard-link.json');
			const store = new OAuthTokenStore(path);
			store.save(initial);
			linkSync(path, copy);
			expect(() => store.load()).toThrow(/hard links/i);
			expect(() => store.save(rotated)).toThrow(/hard links/i);
			expect(() => store.clear()).toThrow(/hard links/i);
			expect(readFileSync(path, 'utf8')).toBe(readFileSync(copy, 'utf8'));
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('rechecks privacy after a previously accepted directory ACL is broadened', () => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const store = new OAuthTokenStore(path);
			store.save(initial);
			const previous = readFileSync(path, 'utf8');
			setSyntheticOAuthAcl(directory, true);
			expect(() => store.load()).toThrow(/privacy/i);
			expect(() => store.save(rotated)).toThrow(/privacy/i);
			expect(() => store.clear()).toThrow(/privacy/i);
			expect(readFileSync(path, 'utf8')).toBe(previous);
		} finally { setSyntheticOAuthAcl(directory); rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('rejects a DELETE-only foreign ancestor ACE while preserving the private child', () => {
		const directory = createOAuthTestDirectory();
		try {
			const ancestor = join(directory, 'ancestor');
			mkdirSync(ancestor);
			const path = join(ancestor, 'private', 'oauth.json');
			const store = new OAuthTokenStore(path);
			store.save(initial);
			const previous = readFileSync(path, 'utf8');
			setSyntheticOAuthAcl(ancestor, 'Delete', false, false);
			expect(() => store.load()).toThrow(/privacy/i);
			expect(() => store.save(rotated)).toThrow(/privacy/i);
			expect(readFileSync(path, 'utf8')).toBe(previous);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.each(['flush', 'rename'] as const)('preserves the previous file and cleans only its owned temporary after %s fails', (phase) => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const previous = JSON.stringify(initial);
			writeFileSync(path, previous, { mode: 0o600 });
			const unrelated = join(directory, 'oauth.json.unrelated.tmp');
			writeFileSync(unrelated, 'unrelated synthetic bytes');
			failure[phase] = true;
			expect(() => new OAuthTokenStore(path).save(rotated)).toThrow(/Synthetic/);
			expect(readFileSync(path, 'utf8')).toBe(previous);
			expect(readFileSync(unrelated, 'utf8')).toBe('unrelated synthetic bytes');
			expect(readdirSync(directory).sort()).toEqual(['oauth.json', 'oauth.json.unrelated.tmp']);
		} finally {
			failure[phase] = false;
			rmSync(directory, { recursive: true, force: true });
		}
	}, 30_000);

	it('creates a private directory and preserves tokens across replacement and reopen', () => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'new', 'oauth.json');
			const store = new OAuthTokenStore(path);
			store.save(initial);
			expect(new OAuthTokenStore(path).load()).toEqual(initial);
			store.save(rotated);
			expect(new OAuthTokenStore(path).load()).toEqual(rotated);
			expect(readdirSync(join(directory, 'new'))).toEqual(['oauth.json']);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('loads an existing private Windows file without treating POSIX bits as its ACL', () => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			writeFileSync(path, JSON.stringify(initial), { mode: 0o600 });
			chmodSync(path, 0o600);
			expect(new OAuthTokenStore(path).load()).toEqual(initial);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('rejects a broad existing directory without changing it or writing token bytes', () => {
		const directory = createOAuthTestDirectory();
		try {
			setSyntheticOAuthAcl(directory, true);
			const path = join(directory, 'oauth.json');
			expect(() => new OAuthTokenStore(path).save(initial)).toThrow(/private|privacy/i);
			expect(existsSync(path)).toBe(false);
			expect(readdirSync(directory)).toEqual([]);
		} finally { setSyntheticOAuthAcl(directory); rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('rejects a broad existing file while preserving its bytes', () => {
		const directory = createOAuthTestDirectory();
		try {
			const path = join(directory, 'oauth.json');
			const bytes = JSON.stringify(initial);
			writeFileSync(path, bytes);
			setSyntheticOAuthAcl(path, true);
			expect(() => new OAuthTokenStore(path).load()).toThrow(/private|privacy/i);
			expect(() => new OAuthTokenStore(path).save(rotated)).toThrow(/private|privacy/i);
			expect(readFileSync(path, 'utf8')).toBe(bytes);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);

	it.runIf(process.platform === 'win32')('rejects a directory junction before writing through it', () => {
		const directory = createOAuthTestDirectory();
		try {
			const target = join(directory, 'target');
			mkdirSync(target);
			const link = join(directory, 'junction');
			symlinkSync(target, link, 'junction');
			expect(() => new OAuthTokenStore(join(link, 'oauth.json')).save(initial)).toThrow(/symlink|reparse/i);
			expect(readdirSync(target)).toEqual([]);
		} finally { rmSync(directory, { recursive: true, force: true }); }
	}, 30_000);
});
