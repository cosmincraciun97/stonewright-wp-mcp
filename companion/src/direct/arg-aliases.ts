/**
 * Plugin argument names for the Direct tools that exist under the same name in both modes.
 *
 * Direct keeps its own argument names (renaming one would break clients). For every tool whose name the
 * Stonewright plugin also uses, Direct additionally accepts the plugin's spelling, so a client that learned
 * the Plugin schema does not fail in a Direct session:
 *
 *  - an alias fills the Direct argument when that argument is absent;
 *  - an alias and its Direct argument given with different values are refused, nothing is sent to WordPress;
 *  - an argument that only the plugin has (and that Direct would otherwise drop silently) is refused with
 *    a message that names the Direct way, unless its value is empty.
 *
 * The advertised schema gains the alias fields. A Direct argument that was required becomes optional in the
 * schema and is checked here instead, because one of two names has to be accepted.
 */
import { z } from 'zod';
import { DirectSafetyBlockedError } from './writes.js';

export type ArgAlias = {
	/** The plugin's name for the argument. */
	alias: string;
	/** Direct's own name. It stays the documented one. */
	canonical: string;
	/** Converts the alias value to the Direct value (for example `role` to `roles`). */
	map?: (value: unknown) => unknown;
	/** Schema of the alias when it is not the Direct argument's own schema. */
	schema?: z.ZodTypeAny;
};

export type PluginOnlyArg = {
	arg: string;
	/** What to do in Direct instead. */
	instead: string;
	/** Values that are the plugin's default and mean nothing, for example `post_type: "post"`. */
	allow?: readonly unknown[];
};

const roleToRoles = (value: unknown): unknown => [value];
const capPerPage = (value: unknown): unknown => (typeof value === 'number' ? Math.min(value, 50) : value);

export const DIRECT_ARG_ALIASES: Readonly<Record<string, readonly ArgAlias[]>> = {
	'stonewright-comment-list': [{ alias: 'post_id', canonical: 'post' }],
	'stonewright-comment-create': [{ alias: 'post_id', canonical: 'post' }],
	'stonewright-menu-delete': [{ alias: 'menu_id', canonical: 'id' }],
	'stonewright-media-upload': [{ alias: 'alt', canonical: 'alt_text' }],
	'stonewright-user-create': [
		{ alias: 'user_login', canonical: 'username' },
		{ alias: 'user_email', canonical: 'email' },
		{ alias: 'user_pass', canonical: 'password' },
		{ alias: 'role', canonical: 'roles', map: roleToRoles, schema: z.string().min(1) },
		{ alias: 'display_name', canonical: 'name' },
	],
	'stonewright-user-update': [
		{ alias: 'user_email', canonical: 'email' },
		{ alias: 'user_pass', canonical: 'password' },
		{ alias: 'role', canonical: 'roles', map: roleToRoles, schema: z.string().min(1) },
		{ alias: 'display_name', canonical: 'name' },
	],
	'stonewright-user-list': [
		{ alias: 'role', canonical: 'roles' },
		{ alias: 'number', canonical: 'per_page', map: capPerPage, schema: z.number().int().min(1).max(100) },
	],
	'stonewright-blueprint-get': [{ alias: 'blueprint_id', canonical: 'id' }],
	'stonewright-blueprint-apply': [
		{ alias: 'blueprint_id', canonical: 'id' },
		{ alias: 'page_title', canonical: 'title' },
		{ alias: 'mode', canonical: 'status' },
	],
	'stonewright-wc-sales-report': [
		{ alias: 'date_from', canonical: 'date_min' },
		{ alias: 'date_to', canonical: 'date_max' },
	],
};

const LOCAL_MEMORY_NOTE =
	'Direct local memory stores topic, correction (or text), kind, tags and an optional draft_skill; it has no field for it.';

export const DIRECT_PLUGIN_ONLY_ARGS: Readonly<Record<string, readonly PluginOnlyArg[]>> = {
	'stonewright-content-create-post': [
		{ arg: 'tags', instead: 'create the post, then assign tag ids with stonewright-content-update (tags are ids over REST).' },
		{ arg: 'post_type', instead: 'use stonewright-content-create with type.', allow: ['post'] },
	],
	'stonewright-media-upload': [
		{ arg: 'base64', instead: 'pass path or url.' },
	],
	'stonewright-menu-create': [
		{ arg: 'items', instead: 'create the menu, then add items with stonewright-menu-items action=create.' },
	],
	'stonewright-blueprint-apply': [
		{ arg: 'palette_override', instead: 'Direct renders the blueprint as shipped; edit the page afterwards.' },
		{ arg: 'brand_kit', instead: 'Direct renders the blueprint as shipped; edit the page afterwards.' },
	],
	'stonewright-wc-sales-report': [
		{ arg: 'statuses', instead: 'Direct returns the WooCommerce sales report as it is; use stonewright-wc-orders with status for a filtered list.' },
		{ arg: 'max_orders', instead: 'Direct returns the WooCommerce sales report as it is.' },
	],
	'stonewright-memory-list': [
		{ arg: 'type', instead: 'Direct local memory has kinds (correction, lesson, preference, fact), not these types; omit it.' },
	],
	'stonewright-learning-record': [
		{ arg: 'lesson', instead: LOCAL_MEMORY_NOTE },
		{ arg: 'severity', instead: LOCAL_MEMORY_NOTE },
		{ arg: 'trigger', instead: LOCAL_MEMORY_NOTE },
		{ arg: 'update_skill', instead: 'pass draft_skill with slug, name, description, triggers and body.' },
		{ arg: 'skill_slug', instead: 'pass draft_skill with slug, name, description, triggers and body.' },
		{ arg: 'skill_title', instead: 'pass draft_skill with slug, name, description, triggers and body.' },
		{ arg: 'skill_content', instead: 'pass draft_skill with slug, name, description, triggers and body.' },
	],
};

/**
 * The schema a tool is registered with: the alias fields are added, and a Direct argument that has an
 * alias and was required becomes optional (the check moves to resolveArgAliases). Tools without
 * aliases get their schema back unchanged.
 */
export function withArgAliases(
	tool: string,
	shape: Record<string, z.ZodTypeAny>,
): Record<string, z.ZodTypeAny> {
	const aliases = DIRECT_ARG_ALIASES[tool] ?? [];
	const pluginOnly = DIRECT_PLUGIN_ONLY_ARGS[tool] ?? [];
	if (aliases.length === 0 && pluginOnly.length === 0) return shape;
	const next: Record<string, z.ZodTypeAny> = { ...shape };
	for (const rule of aliases) {
		const canonical = shape[rule.canonical];
		if (!canonical) {
			throw new Error(`Direct alias table: ${tool} has no argument "${rule.canonical}"`);
		}
		if (rule.alias in shape) {
			throw new Error(`Direct alias table: ${tool} already has an argument "${rule.alias}"`);
		}
		next[rule.alias] = (rule.schema ?? canonical).optional();
		if (!canonical.isOptional()) next[rule.canonical] = canonical.optional();
	}
	for (const { arg } of pluginOnly) {
		if (arg in shape) {
			// Direct implements it itself: nothing to refuse.
			continue;
		}
		next[arg] = z.unknown().optional();
	}
	return next;
}

function sameValue(a: unknown, b: unknown): boolean {
	return JSON.stringify(a) === JSON.stringify(b);
}

function isEmpty(value: unknown): boolean {
	if (value === undefined || value === null || value === '') return true;
	if (Array.isArray(value)) return value.length === 0;
	if (typeof value === 'object') return Object.keys(value as Record<string, unknown>).length === 0;
	return false;
}

export type ArgAliasResult =
	| { ok: true; input: Record<string, unknown> }
	| { ok: false; kind: 'conflict' | 'plugin_only' | 'missing'; code: string; message: string };

/**
 * Applies the alias table to one call. `originalShape` is the schema as the tool code declares it, so a
 * missing required argument is still refused.
 */
export function resolveArgAliases(
	tool: string,
	input: Record<string, unknown>,
	originalShape: Record<string, z.ZodTypeAny>,
): ArgAliasResult {
	const aliases = DIRECT_ARG_ALIASES[tool] ?? [];
	const pluginOnly = DIRECT_PLUGIN_ONLY_ARGS[tool] ?? [];
	if (aliases.length === 0 && pluginOnly.length === 0) return { ok: true, input };
	const out: Record<string, unknown> = { ...input };

	for (const rule of pluginOnly) {
		if (rule.arg in originalShape) continue;
		const value = out[rule.arg];
		delete out[rule.arg];
		if (isEmpty(value) || (rule.allow ?? []).some((allowed) => sameValue(allowed, value))) continue;
		return {
			ok: false,
			kind: 'plugin_only',
			code: 'plugin_only_argument',
			message: `${tool}: "${rule.arg}" is a Plugin-mode argument and Direct would drop it silently, so the call is refused: ${rule.instead} Nothing was changed.`,
		};
	}

	for (const rule of aliases) {
		const aliasValue = out[rule.alias];
		delete out[rule.alias];
		if (aliasValue === undefined) continue;
		const mapped = rule.map ? rule.map(aliasValue) : aliasValue;
		const own = out[rule.canonical];
		if (own !== undefined && !sameValue(own, mapped)) {
			return {
				ok: false,
				kind: 'conflict',
				code: 'argument_alias_conflict',
				message: `${tool}: "${rule.alias}" (the Plugin name) and "${rule.canonical}" were both given with different values. Send one of them. Nothing was changed.`,
			};
		}
		out[rule.canonical] = mapped;
	}

	for (const rule of aliases) {
		const canonical = originalShape[rule.canonical];
		if (canonical && !canonical.isOptional() && out[rule.canonical] === undefined) {
			return {
				ok: false,
				kind: 'missing',
				code: 'invalid_arguments',
				message: `${tool}: "${rule.canonical}" is required (the Plugin name "${rule.alias}" is also accepted).`,
			};
		}
	}
	return { ok: true, input: out };
}

/** The refusal for a conflict or a Plugin-only argument, as the registry reports it. */
export function argAliasRefusal(
	result: Extract<ArgAliasResult, { ok: false }>,
	tool: string,
	site: string,
): DirectSafetyBlockedError {
	return new DirectSafetyBlockedError(result.code, result.message, tool, site);
}
