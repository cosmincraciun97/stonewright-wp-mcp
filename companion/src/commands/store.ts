/**
 * Commands v1 local store: recipes and one-use plans under
 * ~/.stonewright/commands/<site-id>/ and ~/.stonewright/command-plans/<site-id>/.
 *
 * Hygiene contract: directories 0700, files 0600, temp+fsync+atomic rename,
 * symlink refusal, slug-built filenames only (no arbitrary path input),
 * per-site advisory lock for mutations, STONEWRIGHT_STATE_DIR override for tests.
 */

import {
	chmodSync,
	closeSync,
	existsSync,
	fsyncSync,
	lstatSync,
	mkdirSync,
	openSync,
	readFileSync,
	readdirSync,
	realpathSync,
	renameSync,
	unlinkSync,
	writeSync,
} from 'node:fs';
import { homedir } from 'node:os';
import { join, resolve } from 'node:path';
import { randomBytes } from 'node:crypto';
import { assertNoSensitiveMaterial } from '../direct/sensitive-content.js';
import { SLUG_PATTERN } from './limits.js';
import { parseCommandRecipe } from './schema.js';
import { CommandError } from './risk.js';
import type { CommandPlanV1, CommandRecipeV1 } from './types.js';

export interface StorePaths {
	commandsDir: string;
	plansDir: string;
}

export function storePaths(env: NodeJS.ProcessEnv = process.env): StorePaths {
	const override = (env['STONEWRIGHT_STATE_DIR'] ?? '').trim();
	const root = override ? resolve(override) : join(homedir(), '.stonewright');
	return {
		commandsDir: join(root, 'commands'),
		plansDir: join(root, 'command-plans'),
	};
}

function assertSafeSlug(slug: string): void {
	if (!SLUG_PATTERN.test(slug)) {
		throw new CommandError('command_invalid_slug', `Invalid command slug: ${slug}`);
	}
}

function assertSafeSiteId(siteId: string): void {
	if (!/^[A-Za-z0-9_-]{1,64}$/.test(siteId)) {
		throw new CommandError('command_invalid_site_id', 'Invalid site id.');
	}
}

function hardenDir(path: string): void {
	mkdirSync(path, { recursive: true, mode: 0o700 });
	if (process.platform !== 'win32') chmodSync(path, 0o700);
}

function writeFileAtomic(path: string, contents: string): void {
	hardenDir(join(path, '..'));
	const tmp = `${path}.${randomBytes(6).toString('hex')}.tmp`;
	const fd = openSync(tmp, 'wx', 0o600);
	try {
		writeSync(fd, Buffer.from(contents, 'utf8'));
		fsyncSync(fd);
	} finally {
		closeSync(fd);
	}
	renameSync(tmp, path);
	if (process.platform !== 'win32') chmodSync(path, 0o600);
}

function readJsonFile(path: string): unknown {
	if (!existsSync(path)) return undefined;
	try {
		return JSON.parse(readFileSync(path, 'utf8'));
	} catch (err) {
		throw new CommandError(
			'command_store_corrupt',
			`Stored command file is not valid JSON: ${err instanceof Error ? err.message : String(err)}`,
		);
	}
}

function assertRegularFile(path: string): void {
	let stat;
	try {
		stat = lstatSync(path);
	} catch {
		return;
	}
	if (stat.isSymbolicLink()) {
		throw new CommandError('command_store_symlink', `Refusing symlinked command file: ${path}`);
	}
}

/** Advisory per-site lock (exclusive create), same pattern as the registry lock. */
export function withSiteLock<T>(lockPath: string, fn: () => T, timeoutMs = 10_000): T {
	hardenDir(join(lockPath, '..'));
	const start = Date.now();
	while (Date.now() - start <= timeoutMs) {
		let fd: number;
		try {
			fd = openSync(lockPath, 'wx', 0o600);
		} catch (err) {
			if ((err as NodeJS.ErrnoException).code === 'EEXIST') {
				const until = Date.now() + 40;
				while (Date.now() < until) {
					// bounded spin
				}
				continue;
			}
			throw err;
		}
		try {
			writeSync(fd, Buffer.from(`${process.pid}\n`, 'utf8'));
			return fn();
		} finally {
			closeSync(fd);
			try {
				unlinkSync(lockPath);
			} catch {
				// ignore
			}
		}
	}
	throw new CommandError('command_lock_timeout', `Timed out waiting for command store lock at ${lockPath}`);
}

function recipePath(env: NodeJS.ProcessEnv, siteId: string, slug: string): string {
	assertSafeSiteId(siteId);
	assertSafeSlug(slug);
	return join(storePaths(env).commandsDir, siteId, `${slug}.json`);
}

function planPath(env: NodeJS.ProcessEnv, siteId: string, planId: string): string {
	assertSafeSiteId(siteId);
	if (!/^[a-f0-9-]{16,64}$/i.test(planId)) {
		throw new CommandError('command_invalid_plan_id', 'Invalid plan id.');
	}
	return join(storePaths(env).plansDir, siteId, `${planId}.json`);
}

/**
 * Validate + canonicalize a local WordPress root for connect add/repair.
 * Requires an existing real directory containing wp-config.php; symlinks are
 * refused. Returns the canonical realpath.
 */
export function validateLocalWpRoot(raw: string): string {
	const trimmed = raw.trim();
	if (trimmed === '') {
		throw new CommandError('command_wp_root_missing', 'A WordPress root path is required.');
	}
	const resolved = resolve(trimmed);
	let stat;
	try {
		stat = lstatSync(resolved);
	} catch {
		throw new CommandError('command_wp_root_missing', `WordPress root does not exist: ${resolved}`);
	}
	if (stat.isSymbolicLink()) {
		throw new CommandError('command_wp_root_symlink', 'Symlinked WordPress roots are refused.');
	}
	if (!stat.isDirectory()) {
		throw new CommandError('command_wp_root_missing', `WordPress root is not a directory: ${resolved}`);
	}
	if (!existsSync(join(resolved, 'wp-config.php'))) {
		throw new CommandError('command_wp_root_missing', `Directory has no wp-config.php: ${resolved}`);
	}
	return realpathSync(resolved);
}

/** Runner-side revalidation of a stored root before each run. */
export function revalidateStoredRoot(raw: string): string {
	if (!raw || typeof raw !== 'string' || !existsSync(raw)) {
		throw new CommandError('command_wp_root_missing', `Stored WordPress root is no longer valid: ${raw}`);
	}
	let stat;
	try {
		stat = lstatSync(raw);
	} catch {
		throw new CommandError('command_wp_root_missing', `Stored WordPress root is no longer valid: ${raw}`);
	}
	if (stat.isSymbolicLink()) {
		throw new CommandError('command_wp_root_symlink', 'Symlinked WordPress roots are refused.');
	}
	if (!stat.isDirectory()) {
		throw new CommandError('command_wp_root_missing', `Stored WordPress root is not a directory: ${raw}`);
	}
	if (!existsSync(join(raw, 'wp-config.php'))) {
		throw new CommandError('command_wp_root_missing', `Stored WordPress root has no wp-config.php: ${raw}`);
	}
	return realpathSync(raw);
}

/**
 * Enforce the local-runtime boundary for every command mutation path.
 *
 * The CLI and MCP callers both pass the site record's stored root into this
 * helper before saving, planning, or running a recipe. Keeping the check here
 * prevents a caller from accidentally turning a configured remote-only site
 * into a local WP-CLI target by skipping the runner.
 */
export function requireValidLocalWpRoot(raw: string | undefined): string {
	if (typeof raw !== 'string' || raw.trim() === '') {
		throw new CommandError(
			'command_wp_root_missing',
			'This site has no local WordPress root configured. Run `stonewright connect repair <alias> --wp-root <path>`.',
		);
	}
	return revalidateStoredRoot(raw);
}

/** One advisory lock per site (not per file) for all store mutations. */
function siteLockPath(env: NodeJS.ProcessEnv, siteId: string, kind: 'commands' | 'plans'): string {
	assertSafeSiteId(siteId);
	const paths = storePaths(env);
	return join(kind === 'commands' ? paths.commandsDir : paths.plansDir, `${siteId}.lock`);
}

export function saveRecipe(
	siteId: string,
	recipe: CommandRecipeV1,
	opts: { replace?: boolean; env?: NodeJS.ProcessEnv; local_wp_root?: string | undefined } = {},
): { path: string } {
	const env = opts.env ?? process.env;
	requireValidLocalWpRoot(opts.local_wp_root);
	const path = recipePath(env, siteId, recipe.slug);
	assertNoSensitiveMaterial(JSON.stringify(recipe), 'command recipe');
	return withSiteLock(siteLockPath(env, siteId, 'commands'), () => {
		if (existsSync(path) && !opts.replace) {
			throw new CommandError(
				'command_slug_exists',
				`Command "${recipe.slug}" already exists for this site. Pass --replace to overwrite.`,
			);
		}
		assertRegularFile(path);
		writeFileAtomic(path, `${JSON.stringify(recipe, null, '\t')}\n`);
		return { path };
	});
}

export type RecipeSummary = {
	slug: string;
	title: string;
	description: string;
	parameters: string[];
	step_count: number;
};

export function listRecipes(siteId: string, env: NodeJS.ProcessEnv = process.env): RecipeSummary[] {
	assertSafeSiteId(siteId);
	const dir = join(storePaths(env).commandsDir, siteId);
	if (!existsSync(dir)) return [];
	const summaries: RecipeSummary[] = [];
	for (const entry of readdirSync(dir)) {
		if (!entry.endsWith('.json')) continue;
		try {
			const raw = readJsonFile(join(dir, entry)) as CommandRecipeV1 | undefined;
			if (!raw || typeof raw !== 'object' || typeof raw.slug !== 'string') continue;
			summaries.push({
				slug: raw.slug,
				title: String(raw.title ?? ''),
				description: String(raw.description ?? ''),
				parameters: Object.keys(raw.parameters ?? {}),
				step_count: Array.isArray(raw.steps) ? raw.steps.length : 0,
			});
		} catch {
			// Unreadable rows never break listing.
		}
	}
	return summaries.sort((a, b) => a.slug.localeCompare(b.slug));
}

export function getRecipe(siteId: string, slug: string, env: NodeJS.ProcessEnv = process.env): CommandRecipeV1 {
	const path = recipePath(env, siteId, slug);
	assertRegularFile(path);
	const raw = readJsonFile(path);
	if (raw === undefined) {
		throw new CommandError('command_not_found', `Command "${slug}" does not exist for this site.`);
	}
	const parsed = parseCommandRecipe(raw);
	if (!parsed.ok || parsed.recipe.slug !== slug) {
		throw new CommandError('command_store_corrupt', `Stored command "${slug}" failed schema checks.`);
	}
	return parsed.recipe;
}

export function removeRecipe(
	siteId: string,
	slug: string,
	confirmSlug: string,
	env: NodeJS.ProcessEnv = process.env,
): { removed: true } {
	if (confirmSlug !== slug) {
		throw new CommandError('command_remove_confirm', `Confirmation mismatch: pass --confirm ${slug}.`);
	}
	const path = recipePath(env, siteId, slug);
	return withSiteLock(siteLockPath(env, siteId, 'commands'), () => {
		if (!existsSync(path)) {
			throw new CommandError('command_not_found', `Command "${slug}" does not exist for this site.`);
		}
		unlinkSync(path);
		return { removed: true as const };
	});
}

export function savePlan(siteId: string, plan: CommandPlanV1, env: NodeJS.ProcessEnv = process.env): { path: string } {
	assertNoSensitiveMaterial(JSON.stringify(plan), 'command plan');
	const path = planPath(env, siteId, plan.plan_id);
	return withSiteLock(siteLockPath(env, siteId, 'plans'), () => {
		writeFileAtomic(path, `${JSON.stringify(plan, null, '\t')}\n`);
		return { path };
	});
}

export function loadPlan(siteId: string, planId: string, env: NodeJS.ProcessEnv = process.env): CommandPlanV1 | null {
	const path = planPath(env, siteId, planId);
	assertRegularFile(path);
	const raw = readJsonFile(path);
	if (raw === undefined) return null;
	const plan = raw as CommandPlanV1;
	if (!plan || plan.schema_version !== 1 || plan.plan_id !== planId) return null;
	return plan;
}

/** Atomically mark a plan consumed; returns false when already consumed. */
export function markPlanConsumed(
	siteId: string,
	planId: string,
	env: NodeJS.ProcessEnv = process.env,
): boolean {
	const path = planPath(env, siteId, planId);
	return withSiteLock(siteLockPath(env, siteId, 'plans'), (): boolean => {
		const raw = readJsonFile(path);
		const plan = raw as CommandPlanV1 | undefined;
		if (!plan || typeof plan !== 'object') return false;
		if (plan.consumed_at) return false;
		plan.consumed_at = new Date().toISOString();
		writeFileAtomic(path, `${JSON.stringify(plan, null, '\t')}\n`);
		return true;
	});
}
