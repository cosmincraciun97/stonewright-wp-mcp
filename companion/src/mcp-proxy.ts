/**
 * MCP HTTP proxy.
 *
 * Forwards MCP JSON-RPC requests to a remote HTTP MCP server using the
 * Streamable HTTP transport defined in the MCP spec. Authentication is done
 * via a Bearer token injected into the upstream Authorization header.
 *
 * This module is optional — it is only activated when MCP_PROXY_TARGET is set.
 */

import type { IncomingMessage, ServerResponse } from 'node:http';
import { log } from './lib/log.js';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

export interface ProxyConfig {
	/** Base URL of the upstream MCP server, e.g. https://mcp.example.com */
	target: string;
	/** Bearer token for the upstream server. */
	token: string;
	/** Request timeout in milliseconds. Default 30 000. */
	timeoutMs?: number;
}

function loadConfig(env: NodeJS.ProcessEnv = process.env): ProxyConfig | null {
	const target = env['MCP_PROXY_TARGET'] ?? '';
	if (!target) return null;
	return {
		target: target.replace(/\/$/, ''),
		token: env['MCP_PROXY_TOKEN'] ?? '',
		timeoutMs: Number(env['MCP_PROXY_TIMEOUT_MS'] ?? 30_000),
	};
}

/**
 * Eagerly-read export kept for backwards compatibility and for callers that
 * need a truthy check at import time (e.g. the startup log in index.ts).
 * Prefer {@link getProxyConfig} when you need the value inside a request
 * handler so that env-var changes made after module import are honoured
 * (important for tests and for environments that inject secrets late).
 */
export const proxyConfig: ProxyConfig | null = loadConfig();

let _cachedProxyConfig: ProxyConfig | null | undefined = undefined;

/**
 * Lazy getter for proxy configuration. Memoises on first call so the result
 * is consistent within a process lifetime but env vars are not required to be
 * set before the module is first imported.
 */
export function getProxyConfig(): ProxyConfig | null {
	if (_cachedProxyConfig === undefined) {
		_cachedProxyConfig = loadConfig();
	}
	return _cachedProxyConfig;
}

/** Reset the memoised config (test helper only). */
export function _resetProxyConfigCache(): void {
	_cachedProxyConfig = undefined;
}

// ---------------------------------------------------------------------------
// Proxy handler
// ---------------------------------------------------------------------------

/**
 * Handle an inbound MCP request by forwarding it to the upstream server.
 * The caller is responsible for having already applied auth/rate-limit guards
 * and for having buffered the body (the body-size limit lives in the entry
 * point so the request stream can be aborted before we copy it here).
 *
 * @param req       Inbound request.
 * @param res       Outbound response.
 * @param prebuffered  Body buffered upstream. Pass `null` for body-less methods.
 */
export async function handleProxy(
	req: IncomingMessage,
	res: ServerResponse,
	prebuffered: Buffer | null = null,
): Promise<void> {
	const config = getProxyConfig();
	if (!config) {
		res.writeHead(503, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Proxy not configured (MCP_PROXY_TARGET is unset)' }));
		return;
	}

	// Reject any path that resolves to a `..` traversal segment. We decode the
	// raw URL once via decodeURIComponent so that percent-encoded variants
	// (`%2e%2e`, mixed-case `%2E%2e`, encoded slashes around dots like
	// `%2f..%2f`, and `.%2e`) all surface as literal `..` flanked by literal
	// slashes BEFORE the regex check runs. We then keep an additional raw
	// substring scan for `%2e%2e` to defeat double-encoded payloads such as
	// `%252e%252e` where a single decode pass leaves the inner encoding in
	// place. After the guard we still canonicalise via `new URL` so the
	// concatenated upstream URL is well-formed.
	const rawPath = req.url ?? '/';
	let decodedPath: string;
	let safePath: string;
	try {
		decodedPath = decodeURIComponent(rawPath);
		safePath = new URL(rawPath, 'http://localhost').pathname;
	} catch {
		res.writeHead(400, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Invalid request path' }));
		return;
	}
	if (
		/(?:^|[/\\])\.\.(?:[/\\]|$)/.test(decodedPath) ||
		rawPath.toLowerCase().includes('%2e%2e')
	) {
		res.writeHead(400, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Path traversal not allowed' }));
		return;
	}

	const upstreamUrl = `${config.target}${safePath}`;
	log.debug('Proxying MCP request', { upstreamUrl, method: req.method });

	// Use the pre-buffered body when supplied; otherwise drain the request
	// stream. The body-limit middleware in index.ts always supplies a
	// pre-buffered body when invoked, so this fallback path is only reached
	// by callers that bypass the guard pipeline (none in production).
	let body: Buffer;
	if (prebuffered !== null) {
		body = prebuffered;
	} else {
		const bodyChunks: Buffer[] = [];
		await new Promise<void>((resolve, reject) => {
			req.on('data', (chunk: Buffer) => bodyChunks.push(chunk));
			req.on('end', resolve);
			req.on('error', reject);
		});
		body = Buffer.concat(bodyChunks);
	}

	// Build upstream headers — strip hop-by-hop, inject auth
	const upstreamHeaders: Record<string, string> = {
		'Content-Type': req.headers['content-type'] ?? 'application/json',
		'Accept': req.headers['accept'] ?? 'application/json, text/event-stream',
	};
	if (config.token) {
		upstreamHeaders['Authorization'] = `Bearer ${config.token}`;
	}
	// Forward MCP session header if present
	const sessionId = req.headers['mcp-session-id'];
	if (typeof sessionId === 'string') {
		upstreamHeaders['Mcp-Session-Id'] = sessionId;
	}

	const controller = new AbortController();
	const timer = setTimeout(
		() => controller.abort(),
		config.timeoutMs ?? 30_000,
	);

	let upstreamRes: Response;
	try {
		upstreamRes = await fetch(upstreamUrl, {
			method: req.method ?? 'POST',
			headers: upstreamHeaders,
			body: body.length > 0 ? body : null,
			signal: controller.signal,
		});
	} catch (err) {
		clearTimeout(timer);
		const msg = err instanceof Error ? err.message : String(err);
		log.error('Proxy upstream error', { error: msg });
		res.writeHead(502, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: `Upstream error: ${msg}` }));
		return;
	}
	clearTimeout(timer);

	// Forward status + headers
	const forwardHeaders: Record<string, string> = {};
	for (const [k, v] of upstreamRes.headers.entries()) {
		const lower = k.toLowerCase();
		// Skip hop-by-hop headers
		if (['connection', 'keep-alive', 'transfer-encoding', 'upgrade'].includes(lower)) continue;
		forwardHeaders[k] = v;
	}
	res.writeHead(upstreamRes.status, forwardHeaders);

	// Stream response body
	if (!upstreamRes.body) {
		res.end();
		return;
	}
	const reader = upstreamRes.body.getReader();
	try {
		let result = await reader.read();
		while (!result.done) {
			res.write(Buffer.from(result.value));
			result = await reader.read();
		}
	} finally {
		reader.releaseLock();
		res.end();
	}
}
