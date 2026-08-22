import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { planCommand, resolveSteps, verifyPlanReference, recipeSha256 } from '../src/commands/planner.js';
import { markPlanConsumed, loadPlan } from '../src/commands/store.js';
import { CommandError } from '../src/commands/risk.js';
import { parseCommandRecipe } from '../src/commands/schema.js';
import type { CommandRecipeV1 } from '../src/commands/types.js';

const stateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-plan-'));
const env = { STONEWRIGHT_STATE_DIR: stateDir };
const wpRoot = mkdtempSync(join(tmpdir(), 'stonewright-cmd-plan-wp-'));
writeFileSync(join(wpRoot, 'wp-config.php'), '<?php // synthetic');

function makeRecipe(overrides: Partial<CommandRecipeV1> = {}): CommandRecipeV1 {
	const parsed = parseCommandRecipe({
		schema_version: 1,
		slug: 'flush-cache',
		title: 'Flush cache',
		description: 'Write recipe with verified readback.',
		parameters: {},
		steps: [
			{ id: 'flush', kind: 'wp_cli', argv: ['cache', 'flush'] },
			{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
		],
		...overrides,
	});
	if (!parsed.ok) throw new Error(parsed.error);
	return parsed.recipe;
}

describe('command planner', () => {
	it('resolves typed parameters and enforces bounds/enums/required', () => {
		const recipe = makeRecipe({
			parameters: {
				id: { type: 'integer', required: true, minimum: 1, maximum: 100 },
				mood: { type: 'string', enum: ['happy', 'sad'] },
				loud: { type: 'boolean' },
			},
			steps: [
				{ id: 'get', kind: 'wp_cli', argv: ['post', 'list', '{{id}}'] },
			],
		});

		const steps = resolveSteps(recipe, { id: '7', mood: 'happy', loud: 'true' });
		expect(steps[0].argv).toEqual(['post', 'list', '7']);
		expect(steps[0].risk).toBe('read');

		expect(() => resolveSteps(recipe, {})).toThrow(/required/i);
		expect(() => resolveSteps(recipe, { id: '0' })).toThrow(CommandError);
		expect(() => resolveSteps(recipe, { id: '101' })).toThrow(CommandError);
		expect(() => resolveSteps(recipe, { id: '5', mood: 'angry' })).toThrow(CommandError);
		expect(() => resolveSteps(recipe, { id: 'abc' })).toThrow(CommandError);
	});

	it('blocks target overrides smuggled through parameter values after substitution', () => {
		const recipe = makeRecipe({
			parameters: { p: { type: 'string' } },
			steps: [{ id: 'sneak', kind: 'wp_cli', argv: ['core', 'version', '{{p}}'] }],
		});
		expect(() => resolveSteps(recipe, { p: '--path=/etc' })).toThrow(/target override/i);
		expect(() => resolveSteps(recipe, { p: '--user=hacker' })).toThrow(/target override/i);
	});

	it('returns read recipes directly without persisting a plan', () => {
		const read = parseCommandRecipe({
			schema_version: 1,
			slug: 'read-only',
			title: 'Read',
			description: 'Read-only.',
			parameters: {},
			steps: [{ id: 'v', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } }],
		});
		if (!read.ok) throw new Error(read.error);
		const outcome = planCommand({ site_id: 'SITE1', local_wp_root: wpRoot, recipe: read.recipe, env });
		expect(outcome.kind).toBe('read');
	});

	it('persists write plans with a stable hash and ~10 minute TTL', () => {
		const recipe = makeRecipe();
		const now = new Date('2026-08-21T12:00:00.000Z');
		const first = planCommand({ site_id: 'SITE1', local_wp_root: wpRoot, recipe, env, now });
		if (first.kind !== 'write') throw new Error('expected write plan');
		const second = planCommand({ site_id: 'SITE1', local_wp_root: wpRoot, recipe, env, now });
		if (second.kind !== 'write') throw new Error('expected write plan');

		expect(first.plan.plan_sha256).toBe(second.plan.plan_sha256);
		expect(first.plan.expires_at).toBe('2026-08-21T12:10:00.000Z');
		expect(first.plan.consumed_at).toBeNull();

		const stored = loadPlan('SITE1', first.plan.plan_id, env);
		expect(stored?.recipe_slug).toBe('flush-cache');
	});

	it('refuses secret-like parameter values before saving a write plan', () => {
		const isolatedStateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-secret-plan-'));
		const isolatedEnv = { STONEWRIGHT_STATE_DIR: isolatedStateDir };
		const recipe = makeRecipe({
			parameters: { value: { type: 'string', required: true } },
			steps: [
				{ id: 'update', kind: 'wp_cli', argv: ['option', 'update', '{{value}}'] },
				{ id: 'verify', kind: 'wp_cli', argv: ['core', 'version'], expect: { exit_code: 0 } },
			],
		});

		expect(() =>
			planCommand({
				site_id: 'SITE1',
				local_wp_root: wpRoot,
				recipe,
				params: { value: 'password=real-private-value' },
				env: isolatedEnv,
			}),
		).toThrow(/credential material/i);
		expect(existsSync(join(isolatedStateDir, 'command-plans'))).toBe(false);
	});

	it('verifies site binding, recipe binding, TTL, and hash before run', () => {
		const recipe = makeRecipe();
		const outcome = planCommand({ site_id: 'SITE1', local_wp_root: wpRoot, recipe, env });
		if (outcome.kind !== 'write') throw new Error('expected write plan');
		const plan = outcome.plan;

		verifyPlanReference('SITE1', recipe, plan.plan_id, plan.plan_sha256, env);

		const otherRecipe = makeRecipe({ slug: 'other-slug' });
		expect(() => verifyPlanReference('SITE2', recipe, plan.plan_id, plan.plan_sha256, env)).toThrow(/not found/i);
		expect(() => verifyPlanReference('SITE1', otherRecipe, plan.plan_id, plan.plan_sha256, env)).toThrow(/belong|changed|re-plan/i);
		expect(() => verifyPlanReference('SITE1', recipe, plan.plan_id, '0'.repeat(64), env)).toThrow(/plan_sha256/);

		const expired = planCommand({
			site_id: 'SITE1',
			local_wp_root: wpRoot,
			recipe,
			env,
			now: new Date(Date.parse('2026-08-21T12:00:00.000Z')),
		});
		if (expired.kind !== 'write') throw new Error('expected write plan');
		expect(() =>
			verifyPlanReference('SITE1', recipe, expired.plan.plan_id, expired.plan.plan_sha256, env, new Date(Date.parse('2026-08-21T12:11:00.000Z'))),
		).toThrow(/expired/i);
	});

	it('consumes plans exactly once (replay refused)', () => {
		const outcome = planCommand({ site_id: 'SITE1', local_wp_root: wpRoot, recipe: makeRecipe(), env });
		if (outcome.kind !== 'write') throw new Error('expected write plan');
		const plan = outcome.plan;

		expect(markPlanConsumed('SITE1', plan.plan_id, env)).toBe(true);
		expect(markPlanConsumed('SITE1', plan.plan_id, env)).toBe(false);
		expect(() =>
			verifyPlanReference('SITE1', makeRecipe(), plan.plan_id, plan.plan_sha256, env),
		).toThrow(/already used/i);
	});

	it('produces a stable recipe hash independent of plan ids', () => {
		const recipe = makeRecipe();
		expect(recipeSha256(recipe)).toBe(recipeSha256(makeRecipe()));
	});

	it('refuses planning when the configured site has no valid local root', () => {
		const recipe = makeRecipe();
		const isolatedStateDir = mkdtempSync(join(tmpdir(), 'stonewright-cmd-missing-root-plan-'));
		const isolatedEnv = { STONEWRIGHT_STATE_DIR: isolatedStateDir };
		expect(() => planCommand({ site_id: 'SITE1', recipe, env: isolatedEnv })).toThrow(
			/local WordPress root configured/i,
		);
		const invalidRoot = mkdtempSync(join(tmpdir(), 'stonewright-cmd-invalid-root-plan-'));
		expect(() => planCommand({ site_id: 'SITE1', local_wp_root: invalidRoot, recipe, env: isolatedEnv })).toThrow(
			/Stored WordPress root/i,
		);
		expect(existsSync(join(isolatedStateDir, 'command-plans'))).toBe(false);
	});
});
