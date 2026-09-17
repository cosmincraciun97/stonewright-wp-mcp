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
}

export interface ProbeResult {
	mode: ResolvedRuntimeMode;
	requested: StonewrightRuntimeMode;
	configured: ConfiguredRuntimeMode;
	endpoint: string | null;
	pluginEndpointStatus: number | null;
	pluginRouteState: PluginRouteState;
	pluginNamespaceDetected: boolean | null;
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
};

function emptyProbeResult(
	partial: Omit<ProbeResult, 'pluginRouteState' | 'pluginNamespaceDetected' | 'errorCode'> & Partial<Pick<ProbeResult, 'pluginRouteState' | 'pluginNamespaceDetected' | 'errorCode'>>,
): ProbeResult {
	return {
		pluginRouteState: 'not_checked',
		pluginNamespaceDetected: null,
		errorCode: null,
		...partial,
	};
}

function isHtmlPayload(contentType: string, body: string): boolean {
	if (/text\/html|application\/xhtml\+xml/i.test(contentType)) {
		return true;
	}
	const trimmed = body.trimStart().slice(0, 64).toLowerCase();
	return trimmed.startsWith('<!doctype html') || trimmed.startsWith('<html');
}

function parseJsonObject(body: string): Record<string, unknown> | null {
	const trimmed = body.trim();
	if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) {
		return null;
	}
	try {
		const parsed: unknown = JSON.parse(trimmed);
		return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
			? parsed as Record<string, unknown>
			: null;
	} catch {
		return null;
	}
}

async function readBoundedBody(response: Response, limit = 4096): Promise<string> {
	try {
		const text = await response.clone().text();
		return text.slice(0, limit);
	} catch {
		return '';
	}
}

function classifyHttpResponse(status: number, contentType: string, body: string): EndpointProbe {
	const json = parseJsonObject(body);
	const restCode = typeof json?.code === 'string' ? json.code : null;
	const html = isHtmlPayload(contentType, body);
	const jsonRpc = json !== null && (json.jsonrpc === '2.0' || 'result' in json || 'error' in json && 'id' in json);

	if (status === 401 || status === 403 || status === 405) {
		return {
			status,
			present: true,
			route_state: 'present',
			body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
			rest_code: restCode,
			plugin_namespace_detected: null,
		};
	}

	if (status === 200) {
		if (json && restCode === 'rest_no_route') {
			return {
				status,
				present: false,
				route_state: 'missing',
				body_kind: 'wp_rest_error',
				rest_code: 'rest_no_route',
				plugin_namespace_detected: null,
			};
		}
		if (jsonRpc) {
			return {
				status,
				present: true,
				route_state: 'present',
				body_kind: 'json_rpc',
				rest_code: restCode,
				plugin_namespace_detected: null,
			};
		}
		if (html) {
			return {
				status,
				present: null,
				route_state: 'inconclusive',
				body_kind: 'html',
				rest_code: null,
				plugin_namespace_detected: null,
			};
		}
		return {
			status,
			present: true,
			route_state: 'present',
			body_kind: json ? 'wp_rest_error' : (body === '' ? 'empty' : 'unknown'),
			rest_code: restCode,
			plugin_namespace_detected: null,
		};
	}

	if (status === 404) {
		if (json && restCode === 'rest_no_route') {
			return {
				status: 404,
				present: false,
				route_state: 'missing',
				body_kind: 'wp_rest_error',
				rest_code: 'rest_no_route',
				plugin_namespace_detected: null,
			};
		}
		if (html) {
			return {
				status: 404,
				present: null,
				route_state: 'inconclusive',
				body_kind: 'html',
				rest_code: null,
				plugin_namespace_detected: null,
			};
		}
		return {
			status: 404,
			present: false,
			route_state: 'missing',
			body_kind: json ? 'wp_rest_error' : (body === '' ? 'empty' : 'unknown'),
			rest_code: restCode,
			plugin_namespace_detected: null,
		};
	}

	if (status >= 500) {
		return {
			status,
			present: null,
			route_state: 'inconclusive',
			body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
			rest_code: restCode,
			plugin_namespace_detected: null,
		};
	}

	return {
		status,
		present: true,
		route_state: 'present',
		body_kind: json ? 'wp_rest_error' : (html ? 'html' : (body === '' ? 'empty' : 'unknown')),
		rest_code: restCode,
		plugin_namespace_detected: null,
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
		const body = method === 'HEAD' ? '' : await readBoundedBody(response);
		return { failed: false, probe: classifyHttpResponse(response.status, contentType, body) };
	} catch {
		return { failed: true, probe: { ...EMPTY_PROBE, route_state: 'inconclusive' } };
	} finally {
		clearTimeout(timer);
	}
}

function restIndexHasPluginSurface(json: Record<string, unknown> | null): boolean | null {
	if (!json) {
		return null;
	}
	const namespaces = Array.isArray(json.namespaces)
		? json.namespaces.filter((value): value is string => typeof value === 'string')
		: [];
	if (namespaces.some((name) => name === 'mcp' || name.startsWith('mcp/') || name === 'stonewright/v1')) {
		return true;
	}
	const routes = json.routes && typeof json.routes === 'object' && json.routes !== null
		? Object.keys(json.routes as Record<string, unknown>)
		: [];
	if (routes.some((route) => route.startsWith('/mcp/') || route.startsWith('/stonewright/'))) {
		return true;
	}
	return false;
}

async function probeRestIndex(
	mcpEndpoint: string,
	fetchImpl: typeof fetch,
	timeoutMs: number,
): Promise<boolean | null> {
	const index = restIndexFromMcpEndpoint(mcpEndpoint);
	if (!index) {
		return null;
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
		const body = await readBoundedBody(response, 65_536);
		if (isHtmlPayload(contentType, body)) {
			return null;
		}
		return restIndexHasPluginSurface(parseJsonObject(body));
	} catch {
		return null;
	} finally {
		clearTimeout(timer);
	}
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
		let namespace: boolean | null = null;
		if (get.probe.route_state === 'missing' || get.probe.route_state === 'inconclusive') {
			namespace = await probeRestIndex(endpoint, fetchImpl, timeoutMs);
		}
		return { ...get.probe, plugin_namespace_detected: namespace };
	}

	if (!head.failed && head.probe.status === 404) {
		return {
			status: 404,
			present: null,
			route_state: 'inconclusive',
			body_kind: 'empty',
			rest_code: null,
			plugin_namespace_detected: null,
		};
	}

	return { ...EMPTY_PROBE, route_state: 'inconclusive' };
}

export async function resolveRuntimeMode(args: {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
	/** When true, probe even in plugin-only mode. Never changes configured mode. */
	forceProbe?: boolean;
}): Promise<ProbeResult> {
	const env = args.env ?? process.env;
	const requested = resolveRequestedMode(env);
	const configured = resolveConfiguredMode(env);
	const siteBase = siteBaseFromEnv(env);
	const endpoint = siteBase ? pluginMcpEndpoint(siteBase) : null;
	const forceProbe = args.forceProbe === true;

	// direct-only: never probe/switch to plugin.
	if (requested === 'direct') {
		return emptyProbeResult({
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=direct (direct-only); plugin path not probed.',
		});
	}

	// plugin-only: prefer plugin path; caller fails closed (no Direct tools) if unavailable.
	// force_probe:true still executes a real probe without changing configured mode.
	if (requested === 'plugin' && !forceProbe) {
		return emptyProbeResult({
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=plugin (plugin-only); Direct tools will not be registered as fallback.',
		});
	}

	// auto (or plugin-only + force_probe): prefer healthy plugin; fall back Direct when endpoint is explicitly absent (auto only).
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

	const probe = await probePluginEndpoint(endpoint, args.fetchImpl ?? fetch, args.timeoutMs ?? 5_000);

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
				pluginNamespaceDetected: probe.plugin_namespace_detected,
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
				pluginNamespaceDetected: probe.plugin_namespace_detected,
				errorCode: 'plugin_route_missing',
				reason: 'Plugin MCP endpoint returned 404 under plugin-only force_probe; Direct fallback remains disabled.',
			});
		}
		return emptyProbeResult({
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			pluginRouteState: probe.route_state,
			pluginNamespaceDetected: probe.plugin_namespace_detected,
			reason: 'Plugin MCP endpoint probe inconclusive under plugin-only force_probe; Direct fallback remains disabled.',
		});
	}

	if (probe.present === false) {
		const namespaceNote = probe.plugin_namespace_detected === true
			? ' WordPress REST lists an MCP or Stonewright namespace, but /mcp/stonewright is missing.'
			: '';
		return emptyProbeResult({
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			pluginRouteState: 'missing',
			pluginNamespaceDetected: probe.plugin_namespace_detected,
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
			pluginNamespaceDetected: probe.plugin_namespace_detected,
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
		pluginNamespaceDetected: probe.plugin_namespace_detected,
		reason: 'Plugin MCP endpoint probe inconclusive; using plugin proxy path.',
	});
}
