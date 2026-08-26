export type StonewrightRuntimeMode = 'auto' | 'direct' | 'plugin';
export type ResolvedRuntimeMode = 'direct' | 'plugin';
/** Configured mode policy surface (env mapping). */
export type ConfiguredRuntimeMode = 'direct-only' | 'plugin-only' | 'auto';

export interface ProbeResult {
	mode: ResolvedRuntimeMode;
	requested: StonewrightRuntimeMode;
	configured: ConfiguredRuntimeMode;
	endpoint: string | null;
	pluginEndpointStatus: number | null;
	reason: string;
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

/**
 * Probe the Stonewright plugin MCP endpoint.
 * Route present (200/401/403/405) => plugin mode.
 * Explicit 404 => Direct mode.
 * Network errors => treat as unknown/plugin so existing proxy recovery stays intact.
 *
 * Each HEAD/GET attempt uses its own AbortController so a timed-out HEAD cannot
 * abort a subsequent GET. Route reachability is never proof of authentication
 * or a successful MCP initialize.
 */
export async function probePluginEndpoint(
	endpoint: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs = 5_000,
): Promise<{ status: number | null; present: boolean | null }> {
	const routeReachable = (status: number) =>
		status === 200 || status === 401 || status === 403 || status === 405;
	const routeMissing = (status: number) => status === 404;

	const attempt = async (method: 'HEAD' | 'GET'): Promise<{ status: number | null; present: boolean | null; failed: boolean }> => {
		const controller = new AbortController();
		const timer = setTimeout(() => controller.abort(), timeoutMs);
		try {
			const response = await fetchImpl(endpoint, {
				method,
				signal: controller.signal,
				headers: { accept: 'application/json' },
			});
			if (routeReachable(response.status)) {
				return { status: response.status, present: true, failed: false };
			}
			if (routeMissing(response.status)) {
				return { status: 404, present: false, failed: false };
			}
			// Ambiguous non-404 response: prefer plugin path (unchanged recovery).
			return { status: response.status, present: true, failed: false };
		} catch {
			return { status: null, present: null, failed: true };
		} finally {
			clearTimeout(timer);
		}
	};

	const head = await attempt('HEAD');
	if (!head.failed) {
		return { status: head.status, present: head.present };
	}

	const get = await attempt('GET');
	if (!get.failed) {
		return { status: get.status, present: get.present };
	}

	// Unreachable: keep plugin proxy path so existing error/status behavior is preserved.
	return { status: null, present: null };
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
		return {
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=direct (direct-only); plugin path not probed.',
		};
	}

	// plugin-only: prefer plugin path; caller fails closed (no Direct tools) if unavailable.
	// force_probe:true still executes a real probe without changing configured mode.
	if (requested === 'plugin' && !forceProbe) {
		return {
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=plugin (plugin-only); Direct tools will not be registered as fallback.',
		};
	}

	// auto (or plugin-only + force_probe): prefer healthy plugin; fall back Direct when endpoint is explicitly absent (auto only).
	if (!endpoint) {
		return {
			mode: 'plugin',
			requested,
			configured,
			endpoint: null,
			pluginEndpointStatus: null,
			reason: 'No site URL configured; plugin proxy path remains available for local recovery tools.',
		};
	}

	const probe = await probePluginEndpoint(endpoint, args.fetchImpl ?? fetch, args.timeoutMs ?? 5_000);

	if (requested === 'plugin') {
		// Plugin-only never falls back to Direct, even when the route is missing.
		if (probe.present === true) {
			return {
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				reason: `Plugin MCP endpoint responded with HTTP ${probe.status ?? 'ok'}.`,
			};
		}
		if (probe.present === false) {
			return {
				mode: 'plugin',
				requested,
				configured,
				endpoint,
				pluginEndpointStatus: probe.status,
				reason: 'Plugin MCP endpoint returned 404 under plugin-only force_probe; Direct fallback remains disabled.',
			};
		}
		return {
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			reason: 'Plugin MCP endpoint probe inconclusive under plugin-only force_probe; Direct fallback remains disabled.',
		};
	}

	if (probe.present === false) {
		return {
			mode: 'direct',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			reason: 'Plugin MCP endpoint returned 404; registering Direct REST tools (auto fallback).',
		};
	}

	if (probe.present === true) {
		return {
			mode: 'plugin',
			requested,
			configured,
			endpoint,
			pluginEndpointStatus: probe.status,
			reason: `Plugin MCP endpoint responded with HTTP ${probe.status ?? 'ok'}.`,
		};
	}

	return {
		mode: 'plugin',
		requested,
		configured,
		endpoint,
		pluginEndpointStatus: probe.status,
		reason: 'Plugin MCP endpoint probe inconclusive; using plugin proxy path.',
	};
}
