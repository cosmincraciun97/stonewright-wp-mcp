import { existsSync, mkdtempSync, readFileSync, writeFileSync, rmSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runCommand, assessRecipe } from '../src/commands/runner.js';
import { planCommand } from '../src/commands/planner.js';
import { parseCommandRecipe } from '../src/commands/schema.js';
import { buildSiteRecord } from '../src/cli/connect/registry.js';
import type { SiteRecordV2 } from '../src/cli/connect/types.js';
import type { CommandRecipeV1, CommandRunReceiptV1 } from '../src/commands/types.js';
import type { ExecFileRunner } from '../src/wp-cli.js';

const stateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-run-'));
const wpRoot = mkdtempSync(join(tmpdir(), 'stonewright-wp-root-'));
writeFileSync(join(wpRoot, 'wp-config.php'), '<?php // synthetic fixture');

// The runner writes its audit event through the default (process.env) path.
beforeEach(() => {
	process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
});
afterEach(() => {
	delete process.env['STONEWRIGHT_STATE_DIR'];
	rmSync(join(stateDir, 'command-plans'), { recursive: true, force: true });
});

const sharedSite = buildSiteRecord({
	alias: 'runner-site',
	url: 'https://runner.example.test',
	username: 'admin',
	credential_ref: 'memory://runner/app-password',
	local_wp_root: wpRoot,
});

function site(): SiteRecordV2 {
	return sharedSite;
}

function recipe(overrides: Partial<CommandRecipeV1> = {}): CommandRecipeV1 {
	const parsed = parseCommandRecipe({
		schema_version: 1,
		slug: 'read-version',
		title: 'Read version',
		description: 'Synthetic read-only recipe.',
		parameters: {},
		steps: [
			{ id: 'version', kind: 'wp_cli', argv: ['core', 'version'], expect: { stdout_contains: '6.' } },
		],
		...overrides,
	});
	if (!parsed.ok) throw new Error(parsed.error);
	return parsed.recipe;
}

function fakeRunner(stdout = '6.9\n', exitCode = 0): { runner: ExecFileRunner; calls: string[][] } {
	const calls: string[][] = [];
	const runner: ExecFileRunner = (_file, args) => {
		calls.push(args);
		return Promise.resolve({ stdout, stderr: '', exitCode });
	};
	return { runner, calls };
}

describe('command runner', () => {
	it('runs read-only recipes directly and verifies expectations', async () => {
		const { runner, calls } = fakeRunner('6.9\n');
		const receipt = await runCommand({ site: site(), recipe: recipe(), runner, env: process.env });

		expect(receipt.ok).toBe(true);
		expect(receipt.verification_status).toBe('passed');
		expect(receipt.risk).toBe('read');
		expect(receipt.completed_steps).toBe(1);
		expect(calls[0]).toContain('--path=' + realpathSync(wpRoot));
		expect(calls[0]).toContain('core');

		// Audit rows never contain argv or payload material.
		const auditPath = join(stateDir, 'audit-direct.jsonl');
		expect(existsSync(auditPath)).toBe(true);
		const rawAudit = readFileSync(auditPath, 'utf8');
		expect(rawAudit).not.toContain('UNIQUE-AUDIT-MARKER-915');
		expect(rawAudit).not.toContain('["core","version"]');
	});

	it('refuses write recipes without a one-use approved plan', async () => {
		const write = recipe({
			slug: 'flush-cache',
			steps: [
				{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		});
		const { runner } = fakeRunner();
		await expect(
			runCommand({ site: site(), recipe: write, runner, env: process.env }),
		).rejects.toMatchObject({ code: 'command_approval_required' });
	});

	it('executes approved write plans in order, consuming the plan once', async () => {
		const write = recipe({
			slug: 'flush-cache',
			steps: [
				{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		});
		const outcome = planCommand({
			site_id: site().id,
			local_wp_root: site().local_wp_root,
			recipe: write,
			env: process.env,
		});
		if (outcome.kind !== 'write') throw new Error('expected write plan');

		const { runner, calls } = fakeRunner();
		const receipt = await runCommand({
			site: site(),
			recipe: write,
			plan_id: outcome.plan.plan_id,
			plan_sha256: outcome.plan.plan_sha256,
			runner,
			env: process.env,
		});

		expect(receipt.ok).toBe(true);
		expect(calls.map((args) => args[args.length - 2])).toEqual(['cache', 'core']);
		await expect(
			runCommand({
				site: site(),
				recipe: write,
				plan_id: outcome.plan.plan_id,
				plan_sha256: outcome.plan.plan_sha256,
				runner,
				env: process.env,
			}),
		).rejects.toMatchObject({ code: 'command_plan_consumed' });
	});

	it('stops at the first failing step and reports partial apply for writes', async () => {
		const write = recipe({
			slug: 'two-steps',
			steps: [
				{ id: 'first', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'second', kind: 'wp_cli', argv: ['cache', 'purge'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		});
		const outcome = planCommand({
			site_id: site().id,
			local_wp_root: site().local_wp_root,
			recipe: write,
			env: process.env,
		});
		if (outcome.kind !== 'write') throw new Error('expected write plan');

		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve(
				calls.length === 2
					? { stdout: '', stderr: 'Error: purge failed', exitCode: 1 }
					: { stdout: 'ok\n', stderr: '', exitCode: 0 },
			);
		};

		const receipt = await runCommand({
			site: site(),
			recipe: write,
			plan_id: outcome.plan.plan_id,
			plan_sha256: outcome.plan.plan_sha256,
			runner,
			env: process.env,
		});

		expect(receipt.ok).toBe(false);
		expect(receipt.failed_step).toBe('second');
		expect(receipt.completed_steps).toBe(1);
		expect(receipt.partial_apply).toBe(true);
		expect(calls).toHaveLength(2);
	});

	it('fails verification when a false expectation meets a zero exit code', async () => {
		const strict = recipe({
			steps: [{ id: 'v', kind: 'wp_cli', argv: ['core', 'version'], expect: { stdout_contains: '5.7' } }],
		});
		const { runner } = fakeRunner('6.9\n', 0);
		const receipt = await runCommand({ site: site(), recipe: strict, runner, env: process.env });
		expect(receipt.ok).toBe(false);
		expect(receipt.verification_status).toBe('failed');
	});

	it('redacts credential-like output and bounds summaries', async () => {
		const leaky = 'Authorization: Bearer supersecret token=abc123 password=hunter2\n' + 'x'.repeat(5000);
		const { runner } = fakeRunner(leaky, 0);
		const receipt = await runCommand({ site: site(), recipe: recipe(), runner, env: process.env });

		const flat = JSON.stringify(receipt);
		expect(flat).not.toContain('supersecret');
		expect(flat).not.toContain('hunter2');
		const parsedReceipt = JSON.parse(flat) as CommandRunReceiptV1;
		expect(parsedReceipt.steps[0]?.stdout_summary.length).toBeLessThanOrEqual(2100);
	});

	it('requires a valid local wp root before doing anything', async () => {
		const rootless: SiteRecordV2 = buildSiteRecord({
			alias: 'no-root',
			url: 'https://noroot.example.test',
			username: 'admin',
			credential_ref: 'memory://x',
		});
		const { runner } = fakeRunner();
		await expect(
			runCommand({ site: rootless, recipe: recipe(), runner, env: process.env }),
		).rejects.toMatchObject({ code: 'command_wp_root_missing' });
	});

	it('assessRecipe classifies without side effects', () => {
		const read = assessRecipe(recipe());
		expect(read.risk).toBe('read');

		const write = assessRecipe(recipe({
			slug: 'w',
			steps: [
				{ id: 'f', kind: 'wp_cli', argv: ['cache', 'flush'] },
				{ id: 'v', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		}));
		expect(write.risk).toBe('write');
	});
});

describe('command runner audit hygiene', () => {
	it('keeps parameter values out of the audit trail', async () => {
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		try {
			const paramRecipe = recipe({
				slug: 'param-probe',
				parameters: { marker: { type: 'string' } },
				steps: [{ id: 'v', kind: 'wp_cli', argv: ['core', 'version', '{{marker}}'], expect: { exit_code: 0 } }],
			});
			const { runner } = fakeRunner('6.9\n', 0);
			const receipt = await runCommand({
				site: site(),
				recipe: paramRecipe,
				params: { marker: 'UNIQUE-AUDIT-MARKER-915' },
				runner,
				env: process.env,
			});
			expect(receipt.ok).toBe(true);
			const rawAudit = readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8');
			expect(rawAudit).toContain('stonewright-command-run');
			expect(rawAudit).not.toContain('UNIQUE-AUDIT-MARKER-915');
		} finally {
			delete process.env['STONEWRIGHT_STATE_DIR'];
		}
	});
});
