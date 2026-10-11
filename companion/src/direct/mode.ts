import type { FallbackPolicy } from '../cli/connect/types.js';
import { mayFallbackToDirect } from '../cli/connect/mode-policy.js';
import {
	classifyMcpHttpPayload,
	isHtmlPayload,
	parseJsonObject,
	readBoundedBody,
} from '../wordpress-mcp-http.js';

export type StonewrightRuntimeMode = 'auto' | 'direct' | 'plugin';
export type ResolvedRuntimeMode = 'direct' | 'plugin';
/** Configured mode policy surface (env mapping). */
export type ConfiguredRuntimeMode = 'direct-only' | 'plugin-only' | 'auto';

export type PluginRouteState = 'not_checked' | 'present' | 'missing' | 'inconclusive';

export interface EndpointProbe {
	status: number | null;
	present: boolean | null;
	route_state: PluginRouteState;
	body_kind: 'json_rpc' | 'wp_rest_error' | 'html' | 'empty' | 'unknown' | null;
	rest_code: string | null;
	plugin_namespace_detected: boolean | null;
	/** True when the REST index lists the Stonewright plugin's own namespace (stonewright/v1). */
	stonewright_plugin_detected: boolean | null;
}

export interface ProbeResult {
	mode: ResolvedRuntimeMode;
	requested: StonewrightRuntimeMode;
	configured: ConfiguredRuntimeMode;
	endpoint: string | null;
	pluginEndpointStatus: number | null;
	pluginRouteState: PluginRouteState;
	pluginNamespaceDetected: boolean | null;
	/** The Stonewright plugin itself is active on the site (its stonewright/v1 namespace is listed). */
	stonewrightPluginDetected: boolean | null;
	/** Direct was requested but the Stonewright plugin is active: Direct writes are refused. */
	modeConflict: boolean;
	reason: string;
	errorCode: string | null;
}

export function resolveRequestedMode(env: NodeJS.ProcessEnv = process.env): StonewrightRuntimeMode {
	const raw = (env['STONEWRIGHT_MODE'] ?? 'auto').trim().toLowerCase();
	if (raw === 'direct' || raw === 'direct-only') {
		return 'direct';
	}
	if (raw === 'plugin' || raw === 'plugin-only') {
		return 'plugin';
	}
	if (raw === 'auto') {
		return 'auto';
	}
	return 'auto';
}

/** Map env STONEWRIGHT_MODE to configured policy modes. */
export function resolveConfiguredMode(env: NodeJS.ProcessEnv = process.env): ConfiguredRuntimeMode {
	const requested = resolveRequestedMode(env);
	if (requested === 'direct') return 'direct-only';
	if (requested === 'plugin') return 'plugin-only';
	return 'auto';
}

export function siteBaseFromEnv(env: NodeJS.ProcessEnv = process.env): string | null {
	const candidates = [
		env['STONEWRIGHT_WP_URL'],
		env['WP_API_URL'],
		env['STONEWRIGHT_MCP_URL'],
	];
	for (const raw of candidates) {
		const value = (raw ?? '').trim();
		if (!value) continue;
		try {
			const url = new URL(value);
			// Strip /wp-json/... suffix if present
			let path = url.pathname.replace(/\/+$/, '');
			path = path.replace(/\/wp-json(?:\/.*)?$/i, '');
			return `${url.protocol}//${url.host}${path === '/' ? '' : path}`;
		} catch {
			// continue
		}
	}
	return null;
}

export function pluginMcpEndpoint(siteBase: string): string {
	return `${siteBase.replace(/\/+$/, '')}/wp-json/mcp/stonewright`;
}

export function restIndexUrl(siteBase: string): string {
	return `${siteBase.replace(/\/+$/, '')}/wp-json/`;
}

export function restIndexFromMcpEndpoint(endpoint: string): string | null {
	try {
		const url = new URL(endpoint);
		if (!/\/wp-json\/mcp\/stonewright\/?$/i.test(url.pathname)) {
			return null;
		}
		url.pathname = url.pathname.replace(/\/mcp\/stonewright\/?$/i, '/');
		url.search = '';
		url.hash = '';
		return url.toString();
	} catch {
		return null;
	}
}

const EMPTY_PROBE: EndpointProbe = {
	status: null,
	present: null,
	route_state: 'not_checked',
	body_kind: null,
	rest_code: null,
	plugin_namespace_detected: null,
	stonewright_plugin_detected: null,
};

type ProbeResultDefaults = 'pluginRouteState' | 'pluginNamespaceDetected' | 'stonewrightPluginDetected' | 'modeConflict' | 'errorCode';

function emptyProbeResult(
	partial: Omit<ProbeResult, ProbeResultDefaults> & Partial<Pick<ProbeResult, ProbeResultDefaults>>,
): ProbeResult {
	return {
		pluginRouteState: 'not_checked',
		pluginNamespaceDetected: null,
		stonewrightPluginDetected: null,
		modeConflict: false,
		errorCode: null,
		...partial,
	};
}

function classifyHttpResponse(
	status: number,
	contentType: string,
	body: string,
	truncated = false,
): EndpointProbe {
	const classified = classifyMcpHttpPayload(status, contentType, body, truncated);
	return {
		status: classified.status,
		present: classified.present,
		route_state: classified.route_state,
		body_kind: classified.body_kind,
		rest_code: classified.rest_code,
		plugin_namespace_detected: null,
		stonewright_plugin_detected: null,
	};
}

async function fetchOnce(
	endpoint: string,
	method: 'HEAD' | 'GET',
	fetchImpl: typeof fetch,
	timeoutMs: number,
): Promise<{ failed: boolean; probe: EndpointProbe }> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		const response = await fetchImpl(endpoint, {
			method,
			signal: controller.signal,
			headers: { accept: 'application/json' },
		});
		const contentType = response.headers.get('content-type') ?? '';
		const bounded = method === 'HEAD'
			? { text: '', truncated: false }
			: await readBoundedBody(response);
		return {
			failed: false,
			probe: classifyHttpResponse(response.status, contentType, bounded.text, bounded.truncated),
		};
	} catch {
		return { failed: true, probe: { ...EMPTY_PROBE, route_state: 'inconclusive' } };
	} finally {
		clearTimeout(timer);
	}
}

/** What a WordPress REST index says about the Stonewright plugin and any MCP adapter. */
export interface RestIndexSurface {
	/** Any MCP or Stonewright namespace or route is listed. */
	mcpSurface: boolean | null;
	/** The Stonewright plugin's own namespace (stonewright/v1) or routes are listed. */
	stonewrightPlugin: boolean | null;
}

const NAMESPACES_FIELD = /"namespaces"\s*:\s*(\[[^\]]*\])/;

function isStonewrightNamespace(name: string): boolean {
	return name === 'stonewright' || name.startsWith('stonewright/');
}

function isMcpNamespace(name: string): boolean {
	return name === 'mcp' || name.startsWith('mcp/');
}

/**
 * Read the plugin surface out of a REST index body. A full JSON document is
 * preferred; a body that was cut at the size cap is still usable because
 * WordPress lists `namespaces` before the (large) `routes` object.
 */
export function inspectRestIndex(text: string): RestIndexSurface {
	const unknown: RestIndexSurface = { mcpSurface: null, stonewrightPlugin: null };
	const json = parseJsonObject(text);
	let namespaces: string[] | null = null;
	let routes: string[] | null = null;
	if (json) {
		if (Array.isArray(json.namespaces)) {
			namespaces = json.namespaces.filter((value): value is string => typeof value === 'string');
		}
		routes = json.routes && typeof json.routes === 'object' && json.routes !== null
			? Object.keys(json.routes as Record<string, unknown>)
			: [];
	} else {
		const match = NAMESPACES_FIELD.exec(text);
		if (match?.[1]) {
			try {
				const parsed: unknown = JSON.parse(match[1]);
				if (Array.isArray(parsed)) {
					namespaces = parsed.filter((value): value is string => typeof value === 'string');
				}
			} catch {
				namespaces = null;
			}
		}
	}
	if (namespaces === null) {
		return unknown;
	}
	const routeList = routes ?? [];
	const stonewright = namespaces.some(isStonewrightNamespace)
		|| routeList.some((route) => route.startsWith('/stonewright/') || route === '/mcp/stonewright' || route.startsWith('/mcp/stonewright/'));
	const surface = stonewright
		|| namespaces.some(isMcpNamespace)
		|| routeList.some((route) => route.startsWith('/mcp/'));
	return { mcpSurface: surface, stonewrightPlugin: stonewright };
}

async function probeRestIndex(
	mcpEndpoint: string,
	fetchImpl: typeof fetch,
	timeoutMs: number,
): Promise<RestIndexSurface> {
	const unknown: RestIndexSurface = { mcpSurface: null, stonewrightPlugin: null };
	const index = restIndexFromMcpEndpoint(mcpEndpoint);
	if (!index) {
		return unknown;
	}
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	try {
		const response = await fetchImpl(index, {
			method: 'GET',
			signal: controller.signal,
			headers: { accept: 'application/json' },
		});
		const contentType = response.headers.get('content-type') ?? '';
		const bounded = await readBoundedBody(response, 65_536);
		if (isHtmlPayload(contentType, bounded.text)) {
			return unknown;
		}
		return inspectRestIndex(bounded.text);
	} catch {
		return unknown;
	} finally {
		clearTimeout(timer);
	}
}

/** Read-only check of a site's public REST index for the Stonewright plugin surface. */
export async function probeStonewrightPlugin(
	siteBase: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs = 5_000,
): Promise<RestIndexSurface> {
	return probeRestIndex(pluginMcpEndpoint(siteBase), fetchImpl, timeoutMs);
}

/**
 * Probe the Stonewright plugin MCP endpoint.
 * HEAD 404 is not proof the route is absent — follow with GET.
 * WordPress rest_no_route JSON is missing; HTML/CDN 404 is inconclusive.
 * 401/403 means the route exists and auth failed — never Direct fallback.
 *
 * Each HEAD/GET attempt uses its own AbortController so a timed-out HEAD cannot
 * abort a subsequent GET. Route reachability is never proof of authentication
 * or a successful MCP initialize.
 */
export async function probePluginEndpoint(
	endpoint: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs = 5_000,
): Promise<EndpointProbe> {
	const head = await fetchOnce(endpoint, 'HEAD', fetchImpl, timeoutMs);
	if (!head.failed && head.probe.status !== null && head.probe.status !== 404) {
		if (head.probe.status === 405 || head.probe.status === 200 || head.probe.status === 401 || head.probe.status === 403) {
			return { ...head.probe, present: true, route_state: 'present' };
		}
		if (head.probe.status < 500) {
			return head.probe;
		}
	}

	const get = await fetchOnce(endpoint, 'GET', fetchImpl, timeoutMs);
	if (!get.failed) {
		let surface: RestIndexSurface = { mcpSurface: null, stonewrightPlugin: null };
		if (get.probe.route_state === 'missing' || get.probe.route_state === 'inconclusive') {
			surface = await probeRestIndex(endpoint, fetchImpl, timeoutMs);
		}
		return {
			...get.probe,
			plugin_namespace_detected: surface.mcpSurface,
			stonewright_plugin_detected: surface.stonewrightPlugin,
		};
	}

	if (!head.failed && head.probe.status === 404) {
		return {
			status: 404,
			present: null,
			route_state: 'inconclusive',
			body_kind: 'empty',
			rest_code: null,
			plugin_namespace_detected: null,
			stonewright_plugin_detected: null,
		};
	}

	return { ...EMPTY_PROBE, route_state: 'inconclusive' };
}

/**
 * Read-only public REST index probe for Direct backend readiness.
 * Does not send credentials. HTML, truncation, and socket failures are not reachability.
 */
export async function probePublicRestIndex(
	siteBase: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs = 5_000,
): Promise<{ reachable: boolean; status: number | null }> {
	const index = restIndexUrl(siteBase);
	const result = await fetchOnce(index, 'GET', fetchImpl, timeoutMs);
	if (result.failed) {
		return { reachable: false, status: result.probe.status };
	}
	if (result.probe.route_state === 'inconclusive' || result.probe.body_kind === 'html') {
		return { reachable: false, status: result.probe.status };
	}
	const status = result.probe.status;
	if (status === 200 || status === 401 || status === 403) {
		return { reachable: true, status };
	}
	return { reachable: false, status };
}

export async function resolveRuntimeMode(args: {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
	/** When true, probe even in plugin-only mode. Never changes configured mode. */
	forceProbe?: boolean;
	/** Per-site fallback policy from the registry; absent means the mode's default. */
	fallbackPolicy?: FallbackPolicy | undefined;
}): Promise<ProbeResult> {
	const env = args.env ?? process.env;
	const requested = resolveRequestedMode(env);
	const configured = resolveConfiguredMode(env);
	const siteBase = siteBaseFromEnv(env);
	const endpoint = siteBase ? pluginMcpEndpoint(siteBase) : null;
	const fetchImpl = args.fetchImpl ?? fetch;
	const timeoutMs = args.timeoutMs ?? 5_000;

	// direct-only: never switch to the plugin, but still look for it. A live
	// plugin turns Direct into a read-only session (writes are refused).
	if (requested === 'direct') {
		const surface = endpoint
			? await probeRestIndex(endpoint, fetchImpl, timeoutMs)
			: { mcpSurface: null, stonewrightPlugin: null };
		const conflict = surface.stonewrightPlugin === true;
		return emptyProbeResult({
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: null,
			pluginNamespaceDetected: surface.mcpSurface,
			stonewrightPluginDetected: surface.stonewrightPlugin,
			modeConflict: conflict,
			reason: conflict
				? 'STONEWRIGHT_MODE=direct (direct-only), but the Stonewright plugin is active on this site. Direct reads work; Direct writes are refused. Set STONEWRIGHT_MODE=auto or plugin and call stonewright-reconnect to use Plugin mode.'
				: 'STONEWRIGHT_MODE=direct (direct-only); the plugin MCP route is not used.',
		});
	}

	// plugin-only and auto both probe when a site URL exists. Plugin-only never
	// falls back to Direct; the caller fails closed when the route is missing.
	if (!endpoint) {
		return emptyProbeResult({
			mode: 'plugin',
			requested,
			configured,
			endpoint: null,
			pluginEndpointStatus: null,
			reason: 'No site URL configured; plugin proxy path remains available for local recovery tools.',
		});
	}

	const probe = await probePluginEndpoint(endpoint, fetchImpl, timeoutMs);
	const detected = {
		pluginNamespaceDetected: probe.plugin_namespace_detected,
		stonewrightPluginDetected: probe.stonewright_plugin_detected,
	};

	if (requested === 'plugin') {
		// Plugin-only never falls back to Direct, even when the route is missing.
		if (probe.present === true) {
			return emptyProbeResult({
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				pluginRouteState: 'present',
				...detected,
				reason: `Plugin MCP endpoint responded with HTTP ${probe.status ?? 'ok'}.`,
			});
		}
		if (probe.present === false) {
			return emptyProbeResult({
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				pluginRouteState: 'missing',
				...detected,
				errorCode: 'plugin_route_missing',
				reason: 'Plugin MCP endpoint returned 404 under plugin-only mode; Direct fallback remains disabled.',
			});
		}
		return emptyProbeResult({
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			pluginRouteState: probe.route_state,
			...detected,
			reason: 'Plugin MCP endpoint probe inconclusive under plugin-only mode; Direct fallback remains disabled.',
		});
	}

	if (probe.present === false) {
		// The plugin is live but its MCP route is not (adapter inactive, route
		// filtered): that is a plugin connection problem, never a reason for Direct.
		if (probe.stonewright_plugin_detected === true) {
			return emptyProbeResult({
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				pluginRouteState: 'missing',
				...detected,
				errorCode: 'plugin_route_missing',
				reason: 'The Stonewright plugin is active on this site, but its MCP route (/wp-json/mcp/stonewright) is missing, so Direct is not used. Enable or reactivate the MCP Adapter, then call stonewright-reconnect with force_probe=true.',
			});
		}
		if (!mayFallbackToDirect(configured, args.fallbackPolicy ?? 'direct-when-plugin-unavailable')) {
			return emptyProbeResult({
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				pluginRouteState: 'missing',
				...detected,
				errorCode: 'plugin_route_missing',
				reason: 'Plugin MCP endpoint returned 404 and the site fallback_policy is never, so Direct is not used.',
			});
		}
		const namespaceNote = probe.plugin_namespace_detected === true
			? ' WordPress REST lists an MCP namespace, but /mcp/stonewright is missing.'
			: '';
		return emptyProbeResult({
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			pluginRouteState: 'missing',
			...detected,
			errorCode: 'plugin_route_missing',
			reason: `Plugin MCP endpoint returned 404; registering Direct REST tools (auto fallback).${namespaceNote}`,
		});
	}

	if (probe.present === true) {
		return emptyProbeResult({
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			pluginRouteState: 'present',
			...detected,
			reason: `Plugin MCP endpoint responded with HTTP ${probe.status ?? 'ok'}.`,
		});
	}

	return emptyProbeResult({
		mode: 'plugin',
		requested,
		configured,
		endpoint,
		pluginEndpointStatus: probe.status,
		pluginRouteState: probe.route_state,
		...detected,
		reason: 'Plugin MCP endpoint probe inconclusive; using plugin proxy path.',
	});
}
