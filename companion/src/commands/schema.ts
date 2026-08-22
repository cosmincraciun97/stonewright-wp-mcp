/**
 * Commands v1 recipe schema validation.
 *
 * Hard limits are contract: recipe JSON ≤ 64 KiB, slug pattern, ≤ 20
 * parameters, ≤ 25 steps, ≤ 64 argv tokens per step, ≤ 512 chars per token,
 * additionalProperties false at every level, whole-token {{parameter}}
 * placeholders only, and a mandatory read-only verification step after any
 * write step.
 */

import { z } from 'zod';
import {
	COMMAND_SCHEMA_VERSION,
	MAX_ARGV_TOKENS,
	MAX_PARAMETERS,
	MAX_RECIPE_BYTES,
	MAX_STEPS,
	MAX_TOKEN_CHARS,
	SLUG_PATTERN,
} from './limits.js';
import { classifyWpCliRisk } from './risk.js';
import type { CommandRecipeV1 } from './types.js';

const expectationSchema = z
	.object({
		exit_code: z.number().int().min(0).max(255).optional(),
		stdout_equals: z.string().max(4096).optional(),
		stdout_contains: z.string().max(4096).optional(),
	})
	.strict();

const parameterSchema = z.discriminatedUnion('type', [
	z
		.object({
			type: z.literal('string'),
			required: z.boolean().optional(),
			enum: z.array(z.string().min(1).max(256)).min(1).max(32).optional(),
			minLength: z.number().int().min(0).max(4096).optional(),
			maxLength: z.number().int().min(1).max(4096).optional(),
		})
		.strict(),
	z
		.object({
			type: z.literal('integer'),
			required: z.boolean().optional(),
			minimum: z.number().int().optional(),
			maximum: z.number().int().optional(),
		})
		.strict(),
	z.object({ type: z.literal('boolean'), required: z.boolean().optional() }).strict(),
]);

const stepSchema = z
	.object({
		id: z.string().regex(/^[a-z0-9][a-z0-9-]{0,63}$/),
		kind: z.literal('wp_cli'),
		argv: z.array(z.string().max(MAX_TOKEN_CHARS)).min(1).max(MAX_ARGV_TOKENS),
		parse_json: z.boolean().optional(),
		response_mode: z.enum(['summary', 'full']).optional(),
		expect: expectationSchema.optional(),
	})
	.strict();

export const commandRecipeSchema = z
	.object({
		schema_version: z.literal(COMMAND_SCHEMA_VERSION),
		slug: z.string().regex(SLUG_PATTERN),
		title: z.string().min(1).max(200),
		description: z.string().min(1).max(2000),
		// Placeholder tokens only match [a-z0-9_]+; parameter names must be
		// expressible as whole-token placeholders.
		parameters: z.record(z.string().regex(/^[a-z0-9_]{1,64}$/), parameterSchema),
		steps: z.array(stepSchema).min(1).max(MAX_STEPS),
	})
	.strict()
	.refine(
		(recipe) => new Set(recipe.steps.map((step) => step.id)).size === recipe.steps.length,
		{ message: 'Step ids must be unique within a recipe.' },
	);

export type RecipeParseResult =
	| { ok: true; recipe: CommandRecipeV1 }
	| { ok: false; error: string };

/** Whole-token placeholder form: {{parameter_name}} and nothing else inside. */
export function isPlaceholderToken(token: string): boolean {
	return /^\{\{[a-z0-9_]+\}\}$/.test(token);
}

export function placeholderName(token: string): string {
	return token.slice(2, -2);
}

function writeVerificationError(recipe: CommandRecipeV1): string | null {
	let sawWrite = false;
	for (const step of recipe.steps) {
		if (classifyWpCliRisk(step.argv) === 'write') sawWrite = true;
	}
	if (!sawWrite) return null;
	const last = recipe.steps[recipe.steps.length - 1];
	if (classifyWpCliRisk(last.argv) !== 'read' || !last.expect) {
		return 'A recipe with write steps must end with a read-only verification step carrying an expect block.';
	}
	return null;
}

function placeholderErrors(recipe: CommandRecipeV1): string | null {
	for (const step of recipe.steps) {
		for (const token of step.argv) {
			if (token.includes('\u0000')) {
				return `Step "${step.id}" contains a NUL byte in argv.`;
			}
			if (token.includes('{{') && !isPlaceholderToken(token)) {
				return `Step "${step.id}" uses partial interpolation; placeholders must be whole tokens like {{name}}.`;
			}
			if (isPlaceholderToken(token) && !(placeholderName(token) in recipe.parameters)) {
				return `Step "${step.id}" references unknown parameter {{${placeholderName(token)}}}.`;
			}
		}
	}
	if (Object.keys(recipe.parameters).length > MAX_PARAMETERS) {
		return `At most ${MAX_PARAMETERS} parameters are allowed.`;
	}
	return null;
}

/**
 * Validate raw JSON (object or string) as a CommandRecipeV1. Enforces byte
 * size, strict schema, placeholder hygiene, and the write→read rule.
 */
export function parseCommandRecipe(raw: unknown): RecipeParseResult {
	let serialized: string;
	try {
		serialized = typeof raw === 'string' ? raw : JSON.stringify(raw);
	} catch {
		return { ok: false, error: 'Recipe must be valid JSON.' };
	}
	const bytes = Buffer.byteLength(serialized, 'utf8');
	if (bytes > MAX_RECIPE_BYTES) {
		return { ok: false, error: `Recipe JSON exceeds ${MAX_RECIPE_BYTES} bytes (${bytes}).` };
	}

	let candidate: unknown;
	try {
		candidate = typeof raw === 'string' ? JSON.parse(raw) : raw;
	} catch (err) {
		return { ok: false, error: `Invalid recipe JSON: ${err instanceof Error ? err.message : String(err)}` };
	}

	const parsed = commandRecipeSchema.safeParse(candidate);
	if (!parsed.success) {
		const issue = parsed.error.issues[0];
		const path = issue.path.join('.');
		return { ok: false, error: `Invalid recipe: ${path ? `${path}: ` : ''}${issue.message}` };
	}

	const recipe = parsed.data as CommandRecipeV1;

	const placeholderError = placeholderErrors(recipe);
	if (placeholderError) return { ok: false, error: placeholderError };

	const orderingError = writeVerificationError(recipe);
	if (orderingError) return { ok: false, error: orderingError };

	return { ok: true, recipe };
}
