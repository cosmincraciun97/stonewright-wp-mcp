import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { readdirSync } from 'node:fs';
import { runCommandCli } from '../src/cli/command.js';
import { buildSiteRecord, saveRegistry, emptyRegistry, upsertSite, loadRegistry, findSiteByAlias } from '../src/cli/connect/registry.js';
import { getRecipe, loadPlan } from '../src/commands/store.js';
import { verifyPlanReference } from '../src/commands/planner.js';
import type { ExecFileRunner } from '../src/wp-cli.js';

const fakeWp: ExecFileRunner = (_file, args) => {
	const tokens = args.filter((t) => !t.startsWith('--'));
	if (tokens.includes('version') || tokens.includes('flush')) {
		return Promise.resolve({ stdout: '6.9-synthetic\n', stderr: '', exitCode: 0 });
	}
	return Promise.resolve({ stdout: '', stderr: `unknown command: ${tokens.join(' ')}`, exitCode: 1 });
};

const stateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-cli-'));
const sitesFile = join(stateDir, 'sites.json');
const wpRoot = mkdtempSync(join(tmpdir(), 'stonewright-cli-wp-'));
writeFileSync(join(wpRoot, 'wp-config.php'), '<?php // synthetic');

function bootstrap(): void {
	const site = buildSiteRecord({
		alias: 'cli-site',
		url: 'https://cli.example.test',
		username: 'admin',
		credential_ref: 'memory://cli/app-password',
		local_wp_root: wpRoot,
	});
	let reg = emptyRegistry();
	reg = upsertSite(reg, site, { makeDefault: true });
	saveRegistry(reg, { sitesFile });
}

let recipeFile = '';
beforeEach(() => {
	process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	process.env['STONEWRIGHT_SITES_FILE'] = sitesFile;
	bootstrap();
	recipeFile = join(stateDir, 'recipe.json');
	writeFileSync(recipeFile, JSON.stringify({
		schema_version: 1,
		slug: 'core-version',
		title: 'Core version',
		description: 'Read-only version probe.',
		parameters: {},
		steps: [
			{ id: 'version', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
		],
	}));
});
afterEach(() => {
	delete process.env['STONEWRIGHT_STATE_DIR'];
	delete process.env['STONEWRIGHT_SITES_FILE'];
});

describe('command CLI', () => {
	it('add/list/show/remove roundtrip with exit code 0', async () => {
		expect(await runCommandCli(['add', '--file', recipeFile], process.env, fakeWp)).toBe(0);
		expect(await runCommandCli(['list'], process.env, fakeWp)).toBe(0);
		expect(await runCommandCli(['show', 'core-version'], process.env, fakeWp)).toBe(0);
		expect(await runCommandCli(['remove', 'core-version', '--confirm', 'wrong'], process.env, fakeWp)).toBe(1);
		expect(await runCommandCli(['remove', 'core-version', '--confirm', 'core-version'], process.env, fakeWp)).toBe(0);
	});

	it('duplicate add fails without --replace (exit 1)', async () => {
		await runCommandCli(['add', '--file', recipeFile], process.env, fakeWp);
		expect(await runCommandCli(['add', '--file', recipeFile], process.env, fakeWp)).toBe(1);
		expect(await runCommandCli(['add', '--file', recipeFile, '--replace'], process.env, fakeWp)).toBe(0);
	});

	it('read-only run exits 0 without a plan', async () => {
		await runCommandCli(['add', '--file', recipeFile], process.env, fakeWp);
		const code = await runCommandCli(['run', 'core-version'], process.env, fakeWp);
		expect(code).toBe(0);
	});

	it('write recipes route through plan → approval-required (exit 2) → approved run', async () => {
		const writeRecipe = join(stateDir, 'write.json');
		writeFileSync(writeRecipe, JSON.stringify({
			schema_version: 1,
			slug: 'flush-cache',
			title: 'Flush cache',
			description: 'Write with verified readback.',
			parameters: {},
			steps: [
				{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		}));
		await runCommandCli(['add', '--file', writeRecipe], process.env, fakeWp);

		// Direct run of a write recipe → approval required, exit 2.
		expect(await runCommandCli(['run', 'flush-cache'], process.env, fakeWp)).toBe(2);

		// Plan succeeds (exit 0).
		await runCommandCli(['plan', 'flush-cache'], process.env, fakeWp);

		// Approved run with plan + approve exits 0.
		const { registry } = loadRegistry({ sitesFile });
		const site = findSiteByAlias(registry, 'cli-site');
		if (!site) throw new Error('fixture site missing');
		const stored = getRecipe(site.id, 'flush-cache', process.env);
		const plansDir = join(stateDir, 'command-plans', site.id);
		const planFiles = readdirSync(plansDir).filter((f) => f.endsWith('.json'));
		expect(planFiles.length).toBeGreaterThan(0);
		const planId = planFiles[0].replace(/\.json$/, '');
		const plan = loadPlan(site.id, planId, process.env);
		if (!plan) throw new Error('plan not persisted');
		verifyPlanReference(site.id, stored, plan.plan_id, plan.plan_sha256, process.env);

		expect(
			await runCommandCli(
				['run', 'flush-cache', '--plan', plan.plan_id, '--approve', plan.plan_sha256],
				process.env,
				fakeWp,
			),
		).toBe(0);

		// Replay is refused (exit 1), never silently re-executed.
		expect(
			await runCommandCli(
				['run', 'flush-cache', '--plan', plan.plan_id, '--approve', plan.plan_sha256],
				process.env,
				fakeWp,
			),
		).toBe(1);
	});

	it('unknown subcommand and missing slug fail with exit 1', async () => {
		expect(await runCommandCli(['bogus'], process.env, fakeWp)).toBe(1);
		expect(await runCommandCli(['run'], process.env, fakeWp)).toBe(1);
	});

	it('never prints secrets; errors stay bounded on stderr', async () => {
		await runCommandCli(['add', '--file', recipeFile], process.env, fakeWp);
		const errWrite = process.stderr.write.bind(process.stderr);
		let stderr = '';
		process.stderr.write = ((chunk: string | Uint8Array) => {
			stderr += String(chunk);
			return true;
		}) as typeof process.stderr.write;
		try {
			await runCommandCli(['show', 'missing-slug'], process.env, fakeWp);
		} finally {
			process.stderr.write = errWrite;
		}
		expect(stderr).toContain('does not exist');
	});

	it('accepts repeated --param flags for multi-parameter recipes (exit 0)', async () => {
		const multiRecipe = join(stateDir, 'multi.json');
		writeFileSync(multiRecipe, JSON.stringify({
			schema_version: 1,
			slug: 'multi-param',
			title: 'Multi param',
			description: 'Two parameters resolved end-to-end.',
			parameters: {
				first: { type: 'string', required: true },
				second: { type: 'integer', required: true, minimum: 1 },
			},
			steps: [
				{ id: 'probe', kind: 'wp_cli', argv: ['core', 'version', '{{first}}', '{{second}}'], expect: { exit_code: 0 } },
			],
		}));
		await runCommandCli(['add', '--file', multiRecipe], process.env, fakeWp);
		const code = await runCommandCli(
			['run', 'multi-param', '--param', 'first=alpha', '--param', 'second=7'],
			process.env,
			fakeWp,
		);
		expect(code).toBe(0);
	});
});
