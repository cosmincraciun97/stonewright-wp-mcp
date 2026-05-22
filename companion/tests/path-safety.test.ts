/**
 * Tests for {@link assertInsideArtifacts}.
 *
 * The companion's filesystem helpers MUST reject any path that escapes the
 * configured artifacts root. These tests cover:
 *   - absolute paths outside the root
 *   - relative paths that traverse outside via `..`
 *   - confusable prefixes (e.g. `/root-evil` vs root `/root`)
 *   - non-existent target paths (the typical case for write operations)
 *   - symlink-resolved roots (realpath is honoured)
 */

import { describe, it, expect, beforeAll, afterAll } from 'vitest';
import { mkdirSync, mkdtempSync, rmSync, symlinkSync, writeFileSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { assertInsideArtifacts, getArtifactsRoot } from '../src/lib/paths.js';

describe('assertInsideArtifacts', () => {
	let root: string;
	let scratch: string;

	beforeAll(() => {
		scratch = mkdtempSync(join(tmpdir(), 'stonewright-path-test-'));
		root = realpathSync(scratch);
		mkdirSync(join(root, 'sub'), { recursive: true });
		writeFileSync(join(root, 'sub', 'file.txt'), 'hello');
	});

	afterAll(() => {
		rmSync(scratch, { recursive: true, force: true });
	});

	it('accepts a path inside the root', () => {
		expect(() => assertInsideArtifacts(join(root, 'a.png'), root)).not.toThrow();
		expect(() => assertInsideArtifacts(join(root, 'sub', 'b.png'), root)).not.toThrow();
	});

	it('accepts a relative path that resolves inside the root', () => {
		expect(() => assertInsideArtifacts('a.png', root)).not.toThrow();
		expect(() => assertInsideArtifacts('sub/b.png', root)).not.toThrow();
	});

	it('returns the resolved absolute path', () => {
		const resolved = assertInsideArtifacts('sub/x.png', root);
		expect(resolved).toBe(join(root, 'sub', 'x.png'));
	});

	it('rejects an absolute path outside the root', () => {
		const outside = join(tmpdir(), 'unrelated.png');
		expect(() => assertInsideArtifacts(outside, root)).toThrow(/escapes artifacts root/);
	});

	it('rejects parent-directory traversal', () => {
		expect(() => assertInsideArtifacts('../escape.png', root)).toThrow(/escapes artifacts root/);
		expect(() => assertInsideArtifacts(join(root, '..', 'escape.png'), root)).toThrow(
			/escapes artifacts root/,
		);
		expect(() => assertInsideArtifacts(join(root, 'sub', '..', '..', 'escape.png'), root)).toThrow(
			/escapes artifacts root/,
		);
	});

	it('rejects a confusable prefix that is not a true descendant', () => {
		const sibling = root + '-evil';
		mkdirSync(sibling, { recursive: true });
		try {
			expect(() => assertInsideArtifacts(join(sibling, 'file.png'), root)).toThrow(
				/escapes artifacts root/,
			);
		} finally {
			rmSync(sibling, { recursive: true, force: true });
		}
	});

	it('rejects a symlink that points outside the root', () => {
		// Create a symlink INSIDE the root that aims at /tmp. realpath of the
		// nearest existing ancestor should follow the symlink and produce a
		// path outside the root.
		const linkDir = join(root, 'link');
		symlinkSync(tmpdir(), linkDir, 'dir');
		try {
			// Sanity-check: the symlink target must NOT be inside our root. If this
			// assertion fails the symlink didn't actually escape, and the test would
			// be silently passing for the wrong reason.
			const linkRealpath = realpathSync(linkDir);
			const rootWithSep = root.endsWith(sep) ? root : root + sep;
			expect(linkRealpath === root || linkRealpath.startsWith(rootWithSep)).toBe(false);

			expect(() => assertInsideArtifacts(join(linkDir, 'evil.png'), root)).toThrow(
				/escapes artifacts root/,
			);
		} finally {
			rmSync(linkDir, { force: true });
		}
	});

	it('accepts a non-existent target inside the root', () => {
		expect(() =>
			assertInsideArtifacts(join(root, 'does-not-exist-yet', 'deep', 'file.png'), root),
		).not.toThrow();
	});

	it('rejects empty paths', () => {
		expect(() => assertInsideArtifacts('', root)).toThrow();
	});
});

describe('getArtifactsRoot', () => {
	it('uses COMPANION_ARTIFACTS_ROOT when set', () => {
		const target = mkdtempSync(join(tmpdir(), 'sw-art-root-'));
		try {
			const resolved = getArtifactsRoot({ COMPANION_ARTIFACTS_ROOT: target } as NodeJS.ProcessEnv);
			expect(resolved).toBe(realpathSync(target));
		} finally {
			rmSync(target, { recursive: true, force: true });
		}
	});

	it('falls back to a tmpdir-based default', () => {
		const resolved = getArtifactsRoot({} as NodeJS.ProcessEnv);
		expect(resolved.endsWith(`${sep}stonewright-artifacts`)).toBe(true);
	});

	it('creates the directory if missing', () => {
		const missing = join(tmpdir(), 'sw-art-fresh-' + Date.now());
		try {
			const resolved = getArtifactsRoot({ COMPANION_ARTIFACTS_ROOT: missing } as NodeJS.ProcessEnv);
			expect(resolved).toBe(realpathSync(missing));
		} finally {
			rmSync(missing, { recursive: true, force: true });
		}
	});
});
