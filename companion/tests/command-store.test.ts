import { mkdtempSync, mkdirSync, writeFileSync, symlinkSync, statSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import {
	withSiteLock,
	getRecipe,
	listRecipes,
	removeRecipe,
	saveRecipe,
	storePaths,
	validateLocalWpRoot,
} from '../src/commands/store.js';
import { CommandError } from '../src/commands/risk.js';
import { parseCommandRecipe } from '../src/commands/schema.js';
import type { CommandRecipeV1 } from '../src/commands/types.js';

function recipe(slug: string): CommandRecipeV1 {
	const parsed = parseCommandRecipe({
		schema_version: 1,
		slug,
		title: `Title ${slug}`,
		description: 'Synthetic recipe for tests.',
		parameters: {},
		steps: [{ id: 'version', kind: 'wp_cli', argv: ['core', 'version'] }],
	});
	if (!parsed.ok) throw new Error(parsed.error);
	return parsed.recipe;
}

const stateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-store-'));
const env = { STONEWRIGHT_STATE_DIR: stateDir };
const wpRoot = mkdtempSync(join(tmpdir(), 'stonewright-cmd-store-wp-'));
writeFileSync(join(wpRoot, 'wp-config.php'), '<?php // synthetic');

function saveOpts(extra: { replace?: boolean } = {}): { env: typeof env; local_wp_root: string; replace?: boolean } {
	return { env, local_wp_root: wpRoot, ...extra };
}

afterEach(() => {
	rmSync(join(stateDir, 'commands'), { recursive: true, force: true });
});

describe('command store', () => {
	it('roundtrips recipes and lists bounded summaries', () => {
		saveRecipe('SITE1', recipe('alpha'), saveOpts());
		saveRecipe('SITE1', recipe('beta'), saveOpts());

		expect(getRecipe('SITE1', 'alpha', env).slug).toBe('alpha');
		const rows = listRecipes('SITE1', env);
		expect(rows.map((r) => r.slug)).toEqual(['alpha', 'beta']);
		expect(rows[0]).toMatchObject({ step_count: 1, parameters: [] });
	});

	it('refuses duplicate slug without --replace and allows it with --replace', () => {
		saveRecipe('SITE1', recipe('dup'), saveOpts());
		expect(() => saveRecipe('SITE1', recipe('dup'), saveOpts())).toThrow(CommandError);
		saveRecipe('SITE1', recipe('dup'), saveOpts({ replace: true }));
	});

	it('stores files 0600 inside 0700 directories', () => {
		if (process.platform === 'win32') return;
		saveRecipe('SITE1', recipe('perm'), saveOpts());
		const paths = storePaths(env);
		const fileMode = statSync(join(paths.commandsDir, 'SITE1', 'perm.json')).mode & 0o777;
		const dirMode = statSync(join(paths.commandsDir, 'SITE1')).mode & 0o777;
		expect(fileMode).toBe(0o600);
		expect(dirMode).toBe(0o700);
	});

	it('isolates recipes per site id', () => {
		saveRecipe('SITEA', recipe('iso'), saveOpts());
		expect(listRecipes('SITEB', env)).toEqual([]);
		expect(() => getRecipe('SITEB', 'iso', env)).toThrow(/does not exist/);
	});

	it('requires matching confirmation to remove', () => {
		saveRecipe('SITE1', recipe('gone'), saveOpts());
		expect(() => removeRecipe('SITE1', 'gone', 'other', env)).toThrow(/Confirmation mismatch/);
		removeRecipe('SITE1', 'gone', 'gone', env);
		expect(() => getRecipe('SITE1', 'gone', env)).toThrow(CommandError);
	});

	it('refuses symlinked stored files', () => {
		saveRecipe('SITE1', recipe('link'), saveOpts());
		const paths = storePaths(env);
		const real = join(paths.commandsDir, 'SITE1', 'link.json');
		const target = join(stateDir, 'outside.json');
		writeFileSync(target, '{}');
		rmSync(real);
		symlinkSync(target, real);
		expect(() => getRecipe('SITE1', 'link', env)).toThrow(/symlink/i);
	});

	it('refuses credential-like material at save time', () => {
		const parsed = parseCommandRecipe({
			schema_version: 1,
			slug: 'leaky',
			title: 'Leaky',
			description: 'password=hunter2 inside description',
			parameters: {},
			steps: [{ id: 'v', kind: 'wp_cli', argv: ['core', 'version'] }],
		});
		expect(parsed.ok).toBe(true);
		if (parsed.ok) {
			expect(() => saveRecipe('SITE1', parsed.recipe, saveOpts())).toThrow();
		}
	});

	it('refuses save when the configured site has no valid local root', () => {
		expect(() => saveRecipe('SITE1', recipe('missing-root'), { env })).toThrow(
			expect.objectContaining({ code: 'command_wp_root_missing' }),
		);
		const invalidRoot = mkdirSync(join(stateDir, 'invalid-wp-root'), { recursive: true });
		expect(() => saveRecipe('SITE1', recipe('invalid-root'), { env, local_wp_root: invalidRoot })).toThrow(
			expect.objectContaining({ code: 'command_wp_root_missing' }),
		);
		expect(listRecipes('SITE1', env)).toEqual([]);
	});
});

describe('local wp root validation', () => {
	it('accepts a real directory containing wp-config.php and canonicalizes it', () => {
		const root = mkdtempSync(join(tmpdir(), 'wp-root-'));
		writeFileSync(join(root, 'wp-config.php'), '<?php // synthetic');
		const canonical = validateLocalWpRoot(root);
		expect(typeof canonical).toBe('string');
		expect(canonical.length).toBeGreaterThan(0);
	});

	it('refuses missing, non-directory, and wp-config-less roots', () => {
		expect(() => validateLocalWpRoot('/nonexistent/path/here')).toThrow(/does not exist/);
		const empty = mkdirSync(join(stateDir, 'empty-root'), { recursive: true });
		expect(() => validateLocalWpRoot(empty)).toThrow(/wp-config\.php/);
	});
});

describe('store hardening', () => {
	it('refuses path traversal through slug or site id', () => {
		expect(() => getRecipe('SITE1', '../evil', env)).toThrow(/Invalid command slug/);
		expect(() => getRecipe('../evil', 'alpha', env)).toThrow(/Invalid site id/);
	});

	it('per-site lock times out when another holder refuses to release', () => {
		const paths = storePaths(env);
		const lockPath = join(paths.commandsDir, 'LOCKED.lock');
		mkdirSync(join(lockPath, '..'), { recursive: true });
		writeFileSync(lockPath, '99999\n');
		try {
			expect(() => withSiteLock(lockPath, () => 1, 60)).toThrow(/timed out/i);
		} finally {
			rmSync(lockPath, { force: true });
		}
	});
});
