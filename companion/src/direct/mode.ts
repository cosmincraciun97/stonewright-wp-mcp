export type StonewrightRuntimeMode = 'auto' | 'direct' | 'plugin';
export type ResolvedRuntimeMode = 'direct' | 'plugin';

export interface ProbeResult {
	mode: ResolvedRuntimeMode;
	requested: StonewrightRuntimeMode;
	endpoint: string | null;
	pluginEndpointStatus: number | null;
	reason: string;
}

export function resolveRequestedMode(env: NodeJS.ProcessEnv = process.env): StonewrightRuntimeMode {
	const raw = (env['STONEWRIGHT_MODE'] ?? 'auto').trim().toLowerCase();
	if (raw === 'direct' || raw === 'plugin' || raw === 'auto') {
		return raw;
	}
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
 */
export async function probePluginEndpoint(
	endpoint: string,
	fetchImpl: typeof fetch = fetch,
	timeoutMs = 5_000,
): Promise<{ status: number | null; present: boolean | null }> {
	const controller = new AbortController();
	const timer = setTimeout(() => controller.abort(), timeoutMs);
	const isPresentStatus = (status: number) =>
		status === 200 || status === 401 || status === 403 || status === 405;

	try {
		const head = await fetchImpl(endpoint, {
			method: 'HEAD',
			signal: controller.signal,
			headers: { accept: 'application/json' },
		});
		if (isPresentStatus(head.status)) {
			return { status: head.status, present: true };
		}
		if (head.status === 404) {
			return { status: 404, present: false };
		}
		// Some hosts block HEAD; try GET.
		const get = await fetchImpl(endpoint, {
			method: 'GET',
			signal: controller.signal,
			headers: { accept: 'application/json' },
		});
		if (isPresentStatus(get.status)) {
			return { status: get.status, present: true };
		}
		if (get.status === 404) {
			return { status: 404, present: false };
		}
		// Ambiguous non-404 response: prefer plugin path (unchanged recovery).
		return { status: get.status, present: true };
	} catch {
		try {
			const get = await fetchImpl(endpoint, {
				method: 'GET',
				signal: controller.signal,
				headers: { accept: 'application/json' },
			});
			if (isPresentStatus(get.status)) {
				return { status: get.status, present: true };
			}
			if (get.status === 404) {
				return { status: 404, present: false };
			}
			return { status: get.status, present: true };
		} catch {
			// Unreachable: keep plugin proxy path so existing error/status behavior is preserved.
			return { status: null, present: null };
		}
	} finally {
		clearTimeout(timer);
	}
}

export async function resolveRuntimeMode(args: {
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	timeoutMs?: number;
}): Promise<ProbeResult> {
	const env = args.env ?? process.env;
	const requested = resolveRequestedMode(env);
	const siteBase = siteBaseFromEnv(env);
	const endpoint = siteBase ? pluginMcpEndpoint(siteBase) : null;

	if (requested === 'direct') {
		return {
			mode: 'direct',
			requested,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=direct',
		};
	}

	if (requested === 'plugin') {
		return {
			mode: 'plugin',
			requested,
			endpoint,
			pluginEndpointStatus: null,
			reason: 'STONEWRIGHT_MODE=plugin',
		};
	}

	// auto
	if (!endpoint) {
		return {
			mode: 'plugin',
			requested,
			endpoint: null,
			pluginEndpointStatus: null,
			reason: 'No site URL configured; plugin proxy path remains available for local recovery tools.',
		};
	}

	const probe = await probePluginEndpoint(endpoint, args.fetchImpl ?? fetch, args.timeoutMs ?? 5_000);
	if (probe.present === false) {
		return {
			mode: 'direct',
			requested,
			endpoint,
			pluginEndpointStatus: probe.status,
			reason: 'Plugin MCP endpoint returned 404; registering Direct REST tools.',
		};
	}

	if (probe.present === true) {
		return {
			mode: 'plugin',
			requested,
			endpoint,
			pluginEndpointStatus: probe.status,
			reason: `Plugin MCP endpoint responded with HTTP ${probe.status ?? 'ok'}.`,
		};
	}

	return {
		mode: 'plugin',
		requested,
		endpoint,
		pluginEndpointStatus: probe.status,
		reason: 'Plugin MCP endpoint probe inconclusive; using plugin proxy path.',
	};
}
