/**
 * Commands v1 public surface. WP-CLI tokenized recipes only — no shell, no
 * PHP steps, no filesystem steps, no arbitrary ability handlers.
 */

export * from './types.js';
export { parseCommandRecipe, commandRecipeSchema, isPlaceholderToken, placeholderName } from './schema.js';
export {
	MAX_ARGV_TOKENS,
	MAX_PARAMETERS,
	MAX_RECIPE_BYTES,
	MAX_STEPS,
	MAX_TOKEN_CHARS,
	SLUG_PATTERN,
	PLAN_TTL_MS,
	COMMAND_TOOL_PROFILES,
} from './limits.js';
export { CommandError, classifyWpCliRisk, validateRecipeWpCliCommand, substituteParams } from './risk.js';
export {
	storePaths,
	saveRecipe,
	listRecipes,
	getRecipe,
	removeRecipe,
	savePlan,
	loadPlan,
	markPlanConsumed,
	validateLocalWpRoot,
	requireValidLocalWpRoot,
	revalidateStoredRoot,
	withSiteLock,
	type RecipeSummary,
} from './store.js';
export { planCommand, verifyPlanReference, resolveSteps, recipeSha256 } from './planner.js';
export { runCommand, assessRecipe, auditCommandAction } from './runner.js';
