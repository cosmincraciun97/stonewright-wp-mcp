/**
 * Commands v1 — local parameterized WP-CLI recipes.
 *
 * schema_version 1 delivers tokenized WP-CLI steps and verification only.
 * Typed ability steps are deliberately out of scope until a public
 * AbilityExecutionAdapter exists for both Plugin and Direct modes.
 */

export type CommandParameterV1 =
	| { type: 'string'; required?: boolean; enum?: string[]; minLength?: number; maxLength?: number }
	| { type: 'integer'; required?: boolean; minimum?: number; maximum?: number }
	| { type: 'boolean'; required?: boolean };

export type CommandExpectationV1 = {
	exit_code?: number;
	stdout_equals?: string;
	stdout_contains?: string;
};

export type WpCliStepV1 = {
	id: string;
	kind: 'wp_cli';
	argv: string[];
	parse_json?: boolean;
	response_mode?: 'summary' | 'full';
	expect?: CommandExpectationV1;
};

export type CommandRecipeV1 = {
	schema_version: 1;
	slug: string;
	title: string;
	description: string;
	parameters: Record<string, CommandParameterV1>;
	steps: WpCliStepV1[];
};

export type CommandRisk = 'read' | 'write';

export type ResolvedCommandStepV1 = {
	id: string;
	argv: string[];
	risk: CommandRisk;
	expect?: CommandExpectationV1;
};

export type CommandPlanV1 = {
	schema_version: 1;
	plan_id: string;
	plan_sha256: string;
	recipe_sha256: string;
	site_id: string;
	recipe_slug: string;
	risk: CommandRisk;
	steps: ResolvedCommandStepV1[];
	created_at: string;
	expires_at: string;
	consumed_at: string | null;
};

export type StepOutcomeV1 = {
	id: string;
	risk: CommandRisk;
	exit_code: number;
	duration_ms: number;
	ok: boolean;
	stdout_summary: string;
	stderr_summary: string;
	parsed_json?: unknown;
	error?: string;
	expectation_status: 'passed' | 'failed' | 'none';
};

export type CommandRunReceiptV1 = {
	ok: boolean;
	site_id: string;
	site_alias: string;
	recipe_slug: string;
	risk: CommandRisk;
	verification_status: 'passed' | 'failed' | 'not_verified';
	completed_steps: number;
	failed_step: string | null;
	partial_apply: boolean;
	steps: StepOutcomeV1[];
	error?: string;
};
