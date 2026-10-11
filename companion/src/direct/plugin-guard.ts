/**
 * Write-time plugin guard for Direct mode.
 *
 * Direct writes go straight to core REST. On a site where the Stonewright
 * plugin is active that skips the plugin's context token, confirmation,
 * snapshot, Rescue ledger and audit, so Direct refuses to write there and says
 * to use Plugin mode. The plugin is detected through the REST index
 * (/wp-json/): its own stonewright/v1 namespace is listed when it is active.
 *
 * The answer is cached per site (PLUGIN_PRESENCE_TTL_MS) and shared by
 * concurrent calls, so a write costs no extra request once it is known.
 * Reads are never checked.
 */
import { isHtmlPayload, readBoundedBody } from '../wordpress-mcp-http.js';
import { inspectRestIndex } from './mode.js';
import {
	cachedPluginPresence,
	currentDirectCall,
	decidePluginGuard,
	noteOverrideUse,
	rememberPluginPresence,
	setWarmBackoff,
	warmBackoffActive,
	type PluginPresence,
} from './plugin-presence.js';
import type { ResolvedSite } from './sites-config.js';
import { DirectSafetyBlockedError } from './writes.js';

export {
	PLUGIN_CHECK_FAILED_CODE,
	PLUGIN_OVERRIDE_AUDIT_CODE,
	PLUGIN_OVERRIDE_ENV,
	PLUGIN_PRESENCE_TTL_MS,
	PLUGIN_PRESENT_CODE,
	forgetPluginPresence,
	rememberPluginPresence,
	runWithDirectCallContext,
	type PluginPresence,
} from './plugin-presence.js';

export type GuardTarget = Pick<ResolvedSite, 'alias' | 'url' | 'restBase' | 'username' | 'appPassword'>;

export interface PluginGuardOptions {
	fetchImpl?: typeof fetch | undefined;
	timeoutMs?: number | undefined;
	env?: NodeJS.ProcessEnv | undefined;
	tool?: string | undefined;
}

type PresenceResolver = (
	target: GuardTarget,
	fetchImpl: typeof fetch,
	timeoutMs: number,
) => Promise<PluginPresence>;

let resolverOverride: PresenceResolver | null = null;

/** Test seam: replace the network probe. Pass null to restore it. */
export function setPluginPresenceResolverForTests(resolver: PresenceResolver | null): void {
	resolverOverride = resolver;
}

const PROBE_TIMEOUT_CAP_MS = 10_000;
const INDEX_BODY_LIMIT = 262_144;
const PROBE_ATTEMPTS = 2;

async function readIndexOnce(
	target: GuardTarget,
	fetchImpl: typeof fetch,
	timeoutMs: number,
): Promise<PluginPresence> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), Math.min(timeoutMs, PROBE_TIMEOUT_CAP_MS));
	try {
		const response = await fetchImpl(`${target.restBase}/`, {
			method: 'GET',
			signal: controller.signal,
			headers: {
				authorization: `Basic ${Buffer.from(`${target.username}:${target.appPassword}`).toString('base64')}`,
				accept: 'application/json',
			},
		});
		if (!response.ok) {
			return 'unknown';
		}
		const bounded = await readBoundedBody(response, INDEX_BODY_LIMIT);
		if (isHtmlPayload(response.headers.get('content-type') ?? '', bounded.text)) {
			return 'unknown';
		}
		const surface = inspectRestIndex(bounded.text);
		if (surface.stonewrightPlugin === null) {
			return 'unknown';
		}
		return surface.stonewrightPlugin ? 'present' : 'absent';
	} catch {
		return 'unknown';
	} finally {
		clearTimeout(timer);
	}
}

async function probePluginPresence(
	target: GuardTarget,
	fetchImpl: typeof fetch,
	timeoutMs: number,
): Promise<PluginPresence> {
	for (let attempt = 0; attempt < PROBE_ATTEMPTS; attempt += 1) {
		const presence = await readIndexOnce(target, fetchImpl, timeoutMs);
		if (presence !== 'unknown') {
			return presence;
		}
	}
	return 'unknown';
}

const inflight = new Map<string, Promise<PluginPresence>>();

/**
 * The plugin presence for a site: the cached answer while it is fresh,
 * otherwise one shared probe. An inconclusive probe is returned as 'unknown'
 * and is not cached, so the next write tries again.
 */
export async function resolvePluginPresence(
	target: GuardTarget,
	options: PluginGuardOptions = {},
): Promise<PluginPresence> {
	const cached = cachedPluginPresence(target.url);
	if (cached !== null) {
		return cached;
	}
	const key = target.url;
	const pending = inflight.get(key);
	if (pending) {
		return pending;
	}
	const fetchImpl = options.fetchImpl ?? fetch;
	const timeoutMs = options.timeoutMs ?? 30_000;
	const probe = (resolverOverride ?? probePluginPresence)(target, fetchImpl, timeoutMs)
		.then((presence) => {
			if (presence !== 'unknown') {
				rememberPluginPresence(target.url, presence === 'present');
			}
			return presence;
		})
		.finally(() => {
			inflight.delete(key);
		});
	inflight.set(key, probe);
	return probe;
}

/**
 * Fill the cache ahead of a possible write. Never throws and never blocks a
 * read. An inconclusive answer pauses further warm-ups for the site, so a site
 * whose index cannot be read does not cost every tool call two requests; the
 * write path still asks again and fails closed.
 */
export async function warmPluginPresence(
	target: GuardTarget,
	options: PluginGuardOptions = {},
): Promise<void> {
	if (warmBackoffActive(target.url)) {
		return;
	}
	try {
		const presence = await resolvePluginPresence(target, options);
		setWarmBackoff(target.url, presence === 'unknown');
	} catch {
		setWarmBackoff(target.url, true);
	}
}

/** The typed plugin bridge is how Direct talks to the plugin; it is not a core REST write. */
function isPluginBridgeCall(method: string, path: string): boolean {
	return (
		method === 'POST'
		&& path.startsWith('/stonewright/v1/direct/')
		&& !path.includes('..')
		&& !path.includes('//')
		&& !/%2e|%2f|%5c/i.test(path)
	);
}

/**
 * Refuse a Direct write when the Stonewright plugin is active on the site (or
 * cannot be ruled out). Allows the plugin bridge, and the recovery override
 * (environment switch plus confirm:true on the call), which is audited.
 */
export async function assertDirectWriteAllowedOnSite(
	target: GuardTarget,
	options: PluginGuardOptions & { method?: string; path?: string } = {},
): Promise<void> {
	if (options.method !== undefined && options.path !== undefined
		&& isPluginBridgeCall(options.method.toUpperCase(), options.path)) {
		return;
	}
	const env = options.env ?? process.env;
	const presence = await resolvePluginPresence(target, options);
	const call = currentDirectCall();
	const decision = decidePluginGuard({ presence, env, confirm: call?.confirm === true });
	if (decision.action === 'allow') {
		return;
	}
	if (decision.action === 'override') {
		noteOverrideUse({
			tool: options.tool,
			alias: target.alias,
			url: target.url,
			presence: decision.presence,
			env,
		});
		return;
	}
	throw new DirectSafetyBlockedError(
		decision.code,
		decision.message,
		options.tool ?? call?.tool ?? 'stonewright-direct',
		target.alias,
	);
}
