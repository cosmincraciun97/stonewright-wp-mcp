/**
 * Shared state for the Direct write-time plugin guard: the per-site answer to
 * "is the Stonewright plugin active here?", the per-call context that carries
 * the override confirmation, and the pure decision made from both.
 *
 * Network probing lives in plugin-guard.ts. This module only depends on the audit
 * writer, so writes.ts can consult the cache synchronously.
 */
import { AsyncLocalStorage } from 'node:async_hooks';
import { appendDirectAudit, defaultAuditPath } from './audit.js';

/** How long a plugin-present or plugin-absent answer is trusted for one site. */
export const PLUGIN_PRESENCE_TTL_MS = 5 * 60_000;

export const PLUGIN_PRESENT_CODE = 'stonewright_direct_write_blocked_plugin_present';
export const PLUGIN_CHECK_FAILED_CODE = 'stonewright_direct_plugin_check_failed';
export const PLUGIN_OVERRIDE_AUDIT_CODE = 'direct_write_plugin_override';
/** Recovery switch: with confirm:true on the call it lets one Direct write through on a plugin site. */
export const PLUGIN_OVERRIDE_ENV = 'STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE';

export type PluginPresence = 'present' | 'absent' | 'unknown';

type CacheEntry = { present: boolean; at: number };

const cache = new Map<string, CacheEntry>();

/** One key per site: scheme and host are case-insensitive, a trailing slash is not part of the identity. */
export function pluginPresenceKey(siteUrl: string): string {
	const trimmed = siteUrl.trim().replace(/\/+$/, '');
	try {
		const url = new URL(trimmed);
		return `${url.protocol}//${url.host.toLowerCase()}${url.pathname.replace(/\/+$/, '')}`;
	} catch {
		return trimmed.toLowerCase();
	}
}

/** The cached answer for a site, or null when there is none or it has expired. */
export function cachedPluginPresence(siteUrl: string, now: number = Date.now()): PluginPresence | null {
	const entry = cache.get(pluginPresenceKey(siteUrl));
	if (!entry || now - entry.at >= PLUGIN_PRESENCE_TTL_MS || now < entry.at) {
		return null;
	}
	return entry.present ? 'present' : 'absent';
}

/** Record a definite answer (for example from the mode probe or the plugin bridge). */
export function rememberPluginPresence(siteUrl: string, present: boolean, now: number = Date.now()): void {
	cache.set(pluginPresenceKey(siteUrl), { present, at: now });
}

/** After an inconclusive probe, the warm-up leaves the site alone for this long. */
export const WARM_BACKOFF_MS = 60_000;
const warmBackoffUntil = new Map<string, number>();

export function warmBackoffActive(siteUrl: string, now: number = Date.now()): boolean {
	return (warmBackoffUntil.get(pluginPresenceKey(siteUrl)) ?? 0) > now;
}

export function setWarmBackoff(siteUrl: string, active: boolean, now: number = Date.now()): void {
	const key = pluginPresenceKey(siteUrl);
	if (active) warmBackoffUntil.set(key, now + WARM_BACKOFF_MS);
	else warmBackoffUntil.delete(key);
}

/** Drop the answer for one site, or for every site. Called on reconnect. */
export function forgetPluginPresence(siteUrl?: string): void {
	if (siteUrl === undefined) {
		cache.clear();
		warmBackoffUntil.clear();
		return;
	}
	cache.delete(pluginPresenceKey(siteUrl));
	warmBackoffUntil.delete(pluginPresenceKey(siteUrl));
}

export type DirectCallContext = {
	tool: string;
	/** confirm:true was passed on this call. */
	confirm: boolean;
	/** Warnings to show in the tool result (for example a used override). */
	warnings: string[];
	/** The override was already audited for this call. */
	overrideAudited: boolean;
};

const callContext = new AsyncLocalStorage<DirectCallContext>();

export function runWithDirectCallContext<T>(
	init: { tool: string; confirm: boolean },
	operation: () => T,
): T {
	return callContext.run({ ...init, warnings: [], overrideAudited: false }, operation);
}

export function currentDirectCall(): DirectCallContext | undefined {
	return callContext.getStore();
}

/** Warnings collected during the current call, in order. */
export function directCallWarnings(): string[] {
	return [...(callContext.getStore()?.warnings ?? [])];
}

function overrideEnabled(env: NodeJS.ProcessEnv): boolean {
	const raw = (env[PLUGIN_OVERRIDE_ENV] ?? '').trim().toLowerCase();
	return raw === '1' || raw === 'on' || raw === 'true';
}

export type PluginGuardDecision =
	| { action: 'allow' }
	| { action: 'override'; presence: PluginPresence }
	| { action: 'block'; code: string; message: string };

const MODE_HINT =
	'Use Plugin mode for this site: set STONEWRIGHT_MODE=auto or plugin in the MCP client entry, then call stonewright-reconnect (or restart the client).';

/**
 * Decide what a Direct write may do on a site with the given presence answer.
 * The override needs the environment switch and confirm:true on the call.
 */
export function decidePluginGuard(args: {
	presence: PluginPresence;
	env: NodeJS.ProcessEnv;
	confirm: boolean;
}): PluginGuardDecision {
	if (args.presence === 'absent') {
		return { action: 'allow' };
	}
	if (overrideEnabled(args.env) && args.confirm) {
		return { action: 'override', presence: args.presence };
	}
	if (args.presence === 'present') {
		return {
			action: 'block',
			code: PLUGIN_PRESENT_CODE,
			message: `The Stonewright plugin is active on this site, so Direct writes are refused: they would skip the plugin's context token, confirmation, snapshot, Rescue ledger and audit. ${MODE_HINT} Reads still work in Direct.`,
		};
	}
	return {
		action: 'block',
		code: PLUGIN_CHECK_FAILED_CODE,
		message: `Direct could not confirm whether the Stonewright plugin is active on this site, so the write was not sent. Check that the site's REST index (/wp-json/) is reachable with these credentials, then retry. ${MODE_HINT}`,
	};
}

export function overrideWarning(presence: PluginPresence): string {
	return presence === 'present'
		? 'Recovery override used: this Direct write ran on a site where the Stonewright plugin is active, so it skipped the plugin context token, confirmation, snapshot, Rescue ledger and plugin audit. Use Plugin mode for normal work.'
		: 'Recovery override used: Direct could not confirm whether the Stonewright plugin is active on this site, and the write ran anyway. Use Plugin mode for normal work.';
}

/**
 * Record one use of the override: an audit row (once per call) and a warning
 * for the tool result. A failed audit write propagates, so the write is not sent.
 */
export function noteOverrideUse(args: {
	tool?: string | undefined;
	alias: string;
	url: string;
	presence: PluginPresence;
	env: NodeJS.ProcessEnv;
}): void {
	const call = callContext.getStore();
	if (call?.overrideAudited) {
		return;
	}
	const tool = call?.tool ?? args.tool ?? 'stonewright-direct';
	appendDirectAudit(
		{
			tool,
			site: args.alias,
			targetIdentity: args.url,
			status: 'ok',
			code: PLUGIN_OVERRIDE_AUDIT_CODE,
			category: 'SAFETY',
			severity: 'warning',
			eventType: 'direct_plugin_guard_override',
			payload: { plugin_presence: args.presence },
		},
		defaultAuditPath(args.env),
	);
	if (call) {
		call.overrideAudited = true;
		call.warnings.push(overrideWarning(args.presence));
	}
}

/**
 * Synchronous gate used by assertWriteAllowed: refuses when a fresh answer says
 * the plugin is present. An unknown answer passes here; the REST client check
 * is the authoritative one and fails closed.
 */
export function cachedPluginDecision(
	siteUrl: string,
	env: NodeJS.ProcessEnv,
	now: number = Date.now(),
): PluginGuardDecision {
	const presence = cachedPluginPresence(siteUrl, now);
	if (presence !== 'present') {
		return { action: 'allow' };
	}
	return decidePluginGuard({
		presence,
		env,
		confirm: callContext.getStore()?.confirm === true,
	});
}
