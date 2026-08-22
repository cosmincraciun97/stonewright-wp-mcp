/**
 * Commands v1 planner: resolve parameters into concrete argv, classify risk,
 * and persist a one-use plan for write recipes.
 *
 * Read-only recipes may run directly. Write recipes require the exact
 * plan_id + plan_sha256 pair at run time; the plan is consumed atomically
 * before the first write step and replay is refused.
 */

import { createHash, randomUUID } from 'node:crypto';
import { COMMAND_SCHEMA_VERSION, PLAN_TTL_MS } from './limits.js';
import {
	CommandError,
	classifyWpCliRisk,
	substituteParams,
	validateRecipeWpCliCommand,
} from './risk.js';
import { loadPlan, requireValidLocalWpRoot, savePlan } from './store.js';
import type {
	CommandPlanV1,
	CommandRecipeV1,
	CommandRisk,
	ResolvedCommandStepV1,
} from './types.js';

export type ResolvedParams = Record<string, string | number | boolean>;

function canonicalJson(value: unknown): string {
	return JSON.stringify(value, (_key: string, val: unknown) => (val === undefined ? null : val));
}

/**
 * The exact payload covered by plan_sha256: the stored plan minus plan_id
 * (random), plan_sha256 (self), and consumed_at (mutates at consumption).
 * Both creation and verification hash through this helper so the invariant
 * is structural, not conventional.
 */
function planHashPayload(plan: Omit<CommandPlanV1, 'plan_id' | 'plan_sha256' | 'consumed_at'>): unknown {
	return {
		schema_version: plan.schema_version,
		recipe_sha256: plan.recipe_sha256,
		site_id: plan.site_id,
		recipe_slug: plan.recipe_slug,
		risk: plan.risk,
		steps: plan.steps,
		created_at: plan.created_at,
		expires_at: plan.expires_at,
	};
}

export function recipeSha256(recipe: CommandRecipeV1): string {
	return createHash('sha256').update(canonicalJson(recipe)).digest('hex');
}

/** Validate one parameter value against its declared schema. */
function coerceParam(
	name: string,
	def: CommandRecipeV1['parameters'][string],
	raw: string,
): string | number | boolean {
	if (def.type === 'boolean') {
		const lowered = raw.trim().toLowerCase();
		if (['true', '1', 'yes'].includes(lowered)) return true;
		if (['false', '0', 'no'].includes(lowered)) return false;
		throw new CommandError('command_param_invalid', `Parameter ${name} expects true or false.`);
	}
	if (def.type === 'integer') {
		if (!/^-?\d+$/.test(raw.trim())) {
			throw new CommandError('command_param_invalid', `Parameter ${name} expects an integer.`);
		}
		const value = Number(raw.trim());
		if (def.minimum !== undefined && value < def.minimum) {
			throw new CommandError('command_param_invalid', `Parameter ${name} must be ≥ ${def.minimum}.`);
		}
		if (def.maximum !== undefined && value > def.maximum) {
			throw new CommandError('command_param_invalid', `Parameter ${name} must be ≤ ${def.maximum}.`);
		}
		return value;
	}
	// string
	if (def.enum && !def.enum.includes(raw)) {
		throw new CommandError('command_param_invalid', `Parameter ${name} must be one of: ${def.enum.join(', ')}.`);
	}
	if (def.minLength !== undefined && raw.length < def.minLength) {
		throw new CommandError('command_param_invalid', `Parameter ${name} must be at least ${def.minLength} characters.`);
	}
	if (def.maxLength !== undefined && raw.length > def.maxLength) {
		throw new CommandError('command_param_invalid', `Parameter ${name} must be at most ${def.maxLength} characters.`);
	}
	return raw;
}

/**
 * Resolve every step's argv through substitution + both validation gates.
 * Throws CommandError on any invalid input; never partially resolves.
 */
export function resolveSteps(
	recipe: CommandRecipeV1,
	params: Record<string, string>,
): ResolvedCommandStepV1[] {
	const resolvedParams: ResolvedParams = {};
	for (const [name, def] of Object.entries(recipe.parameters)) {
		const raw = params[name];
		if (raw === undefined) {
			if ('required' in def && def.required) {
				throw new CommandError('command_param_required', `Missing required parameter --param ${name}=…`);
			}
			// Absent optional parameters stay absent; substituteParams throws
			// command_missing_param only if a placeholder actually references them.
			continue;
		}
		resolvedParams[name] = coerceParam(name, def, raw);
	}

	return recipe.steps.map((step) => {
		const argv = substituteParams(step.argv, resolvedParams, step.id);
		validateRecipeWpCliCommand(argv);
		return {
			id: step.id,
			argv,
			risk: classifyWpCliRisk(argv),
			...(step.expect ? { expect: step.expect } : {}),
		};
	});
}

export interface PlanCommandInput {
	site_id: string;
	/** Canonical local WordPress root from the configured site record. */
	local_wp_root?: string | undefined;
	recipe: CommandRecipeV1;
	params?: Record<string, string> | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	now?: Date | undefined;
}

export type PlanCommandResult =
	| { kind: 'read'; steps: ResolvedCommandStepV1[]; risk: CommandRisk }
	| { kind: 'write'; plan: CommandPlanV1 };

/**
 * Build a runnable outcome for a recipe. Read-only recipes resolve directly;
 * write recipes produce a persisted one-use plan with a 10-minute TTL.
 */
export function planCommand(input: PlanCommandInput): PlanCommandResult {
	// Planning is part of the local WP-CLI execution boundary. Refuse before
	// resolving or persisting anything when the site has no valid local root.
	requireValidLocalWpRoot(input.local_wp_root);
	const steps = resolveSteps(input.recipe, input.params ?? {});
	const risk: CommandRisk = steps.some((step) => step.risk === 'write') ? 'write' : 'read';

	if (risk === 'read') {
		return { kind: 'read', steps, risk };
	}

	const now = input.now ?? new Date();
	const created_at = now.toISOString();
	const expires_at = new Date(now.getTime() + PLAN_TTL_MS).toISOString();
	const recipeHash = recipeSha256(input.recipe);

	const planSeed = {
		schema_version: COMMAND_SCHEMA_VERSION,
		recipe_sha256: recipeHash,
		site_id: input.site_id,
		recipe_slug: input.recipe.slug,
		risk,
		steps,
		created_at,
		expires_at,
	} satisfies Omit<CommandPlanV1, 'plan_id' | 'plan_sha256' | 'consumed_at'>;

	const plan_id = randomUUID();
	const plan_sha256 = createHash('sha256').update(canonicalJson(planHashPayload(planSeed))).digest('hex');
	const plan: CommandPlanV1 = { ...planSeed, plan_id, plan_sha256, consumed_at: null };

	savePlan(input.site_id, plan, input.env);
	return { kind: 'write', plan };
}

/**
 * Verify a caller-supplied plan reference against the stored plan:
 * existence, TTL, site binding, recipe binding, and hash integrity.
 * Returns the stored plan when everything matches.
 */
export function verifyPlanReference(
	siteId: string,
	recipe: CommandRecipeV1,
	planId: string,
	planSha256: string,
	env: NodeJS.ProcessEnv = process.env,
	now: Date = new Date(),
): CommandPlanV1 {
	const stored = loadPlan(siteId, planId, env);
	if (!stored) {
		throw new CommandError('command_plan_not_found', 'Plan not found. Run `command plan` again.');
	}
	if (stored.consumed_at) {
		throw new CommandError('command_plan_consumed', 'This plan was already used. Plans are one-use.');
	}
	if (new Date(stored.expires_at).getTime() <= now.getTime()) {
		throw new CommandError('command_plan_expired', 'This plan expired. Run `command plan` again.');
	}
	if (stored.site_id !== siteId || stored.recipe_slug !== recipe.slug) {
		throw new CommandError('command_plan_site_mismatch', 'Plan does not belong to this site/command.');
	}
	if (stored.recipe_sha256 !== recipeSha256(recipe)) {
		throw new CommandError('command_plan_recipe_changed', 'The recipe changed after planning. Re-plan.');
	}

	const expected = createHash('sha256')
		.update(canonicalJson(planHashPayload(stored)))
		.digest('hex');
	if (expected !== planSha256) {
		throw new CommandError('command_plan_hash_mismatch', 'plan_sha256 does not match the stored plan.');
	}
	return stored;
}
