/**
 * Commands v1 hard limits. Shared by schema, risk, store, and planner so no
 * layer can drift from the contract.
 */

export const COMMAND_SCHEMA_VERSION = 1;
export const MAX_RECIPE_BYTES = 64 * 1024;
export const MAX_PARAMETERS = 20;
export const MAX_STEPS = 25;
export const MAX_ARGV_TOKENS = 64;
export const MAX_TOKEN_CHARS = 512;
export const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]{0,63}$/;
/** One-use plan lifetime for write recipes. */
export const PLAN_TTL_MS = 10 * 60 * 1000;

/**
 * Single source of truth for which MCP tool profiles expose the three
 * command tools. Registration (mcp-server) and visibility advertisement
 * (setup-profile) must both derive from this list.
 */
export const COMMAND_TOOL_PROFILES: readonly string[] = ['wp-cli', 'site-admin', 'full', 'discover-execute'];
