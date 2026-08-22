/**
 * Commands v1 runner: sequential execution with stop-on-error, one-use plan
 * consumption for write recipes, recursive output redaction, expectation
 * verification, and a single audit event per action.
 *
 * The raw WP-CLI result object is never returned to callers — every string is
 * redacted through redactDirectAuditText() and bounded before it leaves this
 * module.
 */

import {
	assertNoSensitiveMaterial,
} from '../direct/sensitive-content.js';
import { appendDirectAudit, redactDirectAuditText } from '../direct/audit.js';
import type { SiteRecordV2 } from '../cli/connect/types.js';
import { runWpCli, type ExecFileRunner, type WpCliCommandResult } from '../wp-cli.js';
import { markPlanConsumed, requireValidLocalWpRoot } from './store.js';
import { CommandError, validateRecipeWpCliCommand } from './risk.js';
import { resolveSteps, verifyPlanReference } from './planner.js';
import type {
	CommandExpectationV1,
	CommandRecipeV1,
	CommandRunReceiptV1,
	StepOutcomeV1,
} from './types.js';

const FULL_OUTPUT_BYTES = 1024 * 1024;
const SUMMARY_OUTPUT_CHARS = 2000;

export interface RunCommandInput {
	site: SiteRecordV2;
	recipe: CommandRecipeV1;
	params?: Record<string, string> | undefined;
	plan_id?: string | undefined;
	plan_sha256?: string | undefined;
	response_mode?: 'summary' | 'full' | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	/** Injectable execFile runner for tests. */
	runner?: ExecFileRunner | undefined;
	now?: Date | undefined;
}

function capChars(value: string, maxChars: number): string {
	return value.length > maxChars ? value.slice(0, maxChars) : value;
}

function redactString(value: string, full: boolean): string {
	const redacted = redactDirectAuditText(value);
	if (full) {
		const bytes = Buffer.byteLength(redacted, 'utf8');
		return bytes <= FULL_OUTPUT_BYTES ? redacted : redacted.slice(0, FULL_OUTPUT_BYTES);
	}
	return capChars(redacted, SUMMARY_OUTPUT_CHARS);
}

function redactParsed(value: unknown, full: boolean, depth = 0): unknown {
	if (depth > 8) return '[truncated]';
	if (typeof value === 'string') return redactString(value, full);
	if (Array.isArray(value)) {
		return value.slice(0, 100).map((item) => redactParsed(item, full, depth + 1));
	}
	if (value && typeof value === 'object') {
		const out: Record<string, unknown> = {};
		for (const [key, val] of Object.entries(value as Record<string, unknown>).slice(0, 100)) {
			out[key] = redactParsed(val, full, depth + 1);
		}
		return out;
	}
	return value;
}

function evaluateExpectation(
	expect: CommandExpectationV1 | undefined,
	exitCode: number,
	stdout: string,
): 'passed' | 'failed' | 'none' {
	if (!expect) return 'none';
	if (expect.exit_code !== undefined && expect.exit_code !== exitCode) return 'failed';
	if (expect.stdout_equals !== undefined && expect.stdout_equals !== stdout) return 'failed';
	if (expect.stdout_contains !== undefined && !stdout.includes(expect.stdout_contains)) return 'failed';
	return 'passed';
}

function summarize(result: WpCliCommandResult, full: boolean): Pick<StepOutcomeV1, 'stdout_summary' | 'stderr_summary' | 'parsed_json' | 'error'> {
	const source = result as {
		stdout?: string;
		stderr?: string;
		parsed_json?: unknown;
		error?: string;
	};
	return {
		stdout_summary: redactString(String(source.stdout ?? ''), full),
		stderr_summary: redactString(String(source.stderr ?? ''), full),
		...(source.parsed_json !== undefined
			? { parsed_json: redactParsed(source.parsed_json, full) }
			: {}),
		...(source.error ? { error: redactString(String(source.error), false) } : {}),
	};
}

/**
 * Resolve steps and classify overall risk without persisting anything.
 * Shared by CLI/MCP to decide between direct run and approval flow.
 */
export function assessRecipe(
	recipe: CommandRecipeV1,
	params: Record<string, string> = {},
): { risk: 'read' | 'write'; steps: ReturnType<typeof resolveSteps> } {
	const steps = resolveSteps(recipe, params);
	const risk = steps.some((step) => step.risk === 'write') ? ('write' as const) : ('read' as const);
	return { risk, steps };
}

export async function runCommand(input: RunCommandInput): Promise<CommandRunReceiptV1> {
	const env = input.env ?? process.env;
	const startedAt = input.now ?? new Date();
	const startMs = Date.now();

	const wpRoot = requireValidLocalWpRoot(input.site.local_wp_root);

	const { risk, steps } = assessRecipe(input.recipe, input.params ?? {});
	for (const step of steps) {
		assertNoSensitiveMaterial(JSON.stringify(step.argv), 'resolved command argv');
		validateRecipeWpCliCommand(step.argv);
	}

	if (risk === 'write') {
		if (!input.plan_id || !input.plan_sha256) {
			throw new CommandError(
				'command_approval_required',
				'This recipe writes. Create a plan (`command plan`) then run with --plan <id> --approve <sha256>.',
			);
		}
		verifyPlanReference(input.site.id, input.recipe, input.plan_id, input.plan_sha256, env, startedAt);
		// Consume atomically BEFORE the first write step; replay is refused.
		if (!markPlanConsumed(input.site.id, input.plan_id, env)) {
			throw new CommandError('command_plan_consumed', 'This plan was already used. Plans are one-use.');
		}
	}
	const full = input.response_mode === 'full';
	const outcomes: StepOutcomeV1[] = [];
	let failedStep: string | null = null;
	let verificationFailed = false;

	for (const step of steps) {
		const stepStart = Date.now();
		const stepDef = input.recipe.steps.find((s) => s.id === step.id);
		try {
			// Always fetch the full result so expectations evaluate against real
			// stdout; bounding to summary happens in our own summarize below.
			const result = await runWpCli(
				{
					command: step.argv,
					path: wpRoot,
					parseJson: stepDef?.parse_json === true,
					responseMode: 'full',
				},
				input.runner,
				env,
			);
			const rawStdout = String((result as { stdout?: string }).stdout ?? '');
			const expectation = evaluateExpectation(
				step.expect,
				result.exit_code,
				rawStdout,
			);
			if (expectation === 'failed') verificationFailed = true;
			const outcome: StepOutcomeV1 = {
				id: step.id,
				risk: step.risk,
				exit_code: result.exit_code,
				duration_ms: Date.now() - stepStart,
				ok: result.exit_code === 0 && expectation !== 'failed',
				expectation_status: expectation,
				...summarize(result, full),
			};
			outcomes.push(outcome);
			if (!outcome.ok) {
				failedStep = step.id;
				break;
			}
		} catch (err) {
			verificationFailed = true;
			failedStep = step.id;
			outcomes.push({
				id: step.id,
				risk: step.risk,
				exit_code: 1,
				duration_ms: Date.now() - stepStart,
				ok: false,
				stdout_summary: '',
				stderr_summary: '',
				error: redactString(err instanceof Error ? err.message : String(err), false),
				expectation_status: step.expect ? 'failed' : 'none',
			});
			break;
		}
	}

	const executedAll = outcomes.length === steps.length;
	// Partial apply only when at least one WRITE step actually succeeded
	// before the failure; read-only successes wrote nothing.
	const firstFailureIndex = failedStep === null ? -1 : outcomes.findIndex((o) => o.id === failedStep);
	const writeSucceededBeforeFailure = outcomes
		.slice(0, firstFailureIndex === -1 ? outcomes.length : firstFailureIndex)
		.some((o) => o.risk === 'write' && o.ok);
	const receipt: CommandRunReceiptV1 = {
		ok: executedAll && !verificationFailed,
		site_id: input.site.id,
		site_alias: input.site.alias,
		recipe_slug: input.recipe.slug,
		risk,
		verification_status: verificationFailed ? 'failed' : executedAll ? 'passed' : 'not_verified',
		completed_steps: outcomes.filter((o) => o.ok).length,
		failed_step: failedStep,
		partial_apply: risk === 'write' && failedStep !== null && writeSucceededBeforeFailure,
		steps: outcomes,
	};

	appendDirectAudit({
		tool: 'stonewright-command-run',
		site: input.site.canonical_url,
		resource: input.recipe.slug,
		status: receipt.ok ? 'ok' : 'error',
		eventType: 'command_recipe',
		operationClass: 'local_command_recipe',
		executionStatus: 'executed',
		verificationStatus: receipt.verification_status,
		causeKey: receipt.ok ? `${input.recipe.slug}|ok` : `${input.recipe.slug}|${failedStep ?? 'error'}`,
		durationMs: Date.now() - startMs,
	});
	return receipt;
}

export type CommandAction = 'save' | 'plan' | 'remove';

/** Save/plan/remove are audit events too (bounded, no argv). */
export function auditCommandAction(
	action: CommandAction,
	site: Pick<SiteRecordV2, 'canonical_url'>,
	slug: string,
	status: 'ok' | 'error',
	detail?: { code?: string; error?: string },
): void {
	appendDirectAudit({
		tool: `stonewright-command-${action}`,
		site: site.canonical_url,
		resource: slug,
		status,
		eventType: 'command_recipe',
		operationClass: 'local_command_recipe',
		executionStatus: 'completed',
		...(detail?.code ? { code: detail.code } : {}),
		...(detail?.error ? { error: detail.error } : {}),
	});
}
