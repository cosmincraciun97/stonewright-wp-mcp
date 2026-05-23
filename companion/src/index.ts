/**
 * Stonewright Companion — entry point.
 *
 * Boots the MCP server in two modes simultaneously:
 *   1. stdio — always active; used by Claude Code / local MCP clients.
 *   2. Streamable HTTP — activated when PORT is set; guarded by origin +
 *      bearer auth + request-body size limit. Binds to 127.0.0.1 by default;
 *      set `COMPANION_BIND_HOST=0.0.0.0` (or another interface) to override.
 *
 * The proxy route (/proxy) is additionally activated when MCP_PROXY_TARGET is set.
 *
 * Required environment variables in NORMAL (non-dev) mode:
 *   - COMPANION_BEARER_TOKEN
 *   - COMPANION_ALLOWED_ORIGINS (comma-separated list)
 * Set STONEWRIGHT_DEV_INSECURE=1 to bypass these checks (dev only).
 */

import 'dotenv/config';
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:http';
import type { IncomingMessage, ServerResponse } from 'node:http';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import type { Transport } from '@modelcontextprotocol/sdk/shared/transport.js';
import { log } from './lib/log.js';
import { buildHttpGuard, loadGuardConfig, readBodyWithLimit, type GuardConfig } from './lib/security.js';
import { createMcpServer } from './mcp-server.js';
import { handleProxy, proxyConfig, getProxyConfig } from './mcp-proxy.js';
import { closeBrowser } from './playwright-runner.js';
import { dispatchQaRoute } from './http-api.js';
import { CONTRACT_VERSION } from './contracts/version.js';
import { checkReadiness } from './first-run.js';

// ---------------------------------------------------------------------------
// Stdio transport (always on)
// ---------------------------------------------------------------------------

async function startStdio(): Promise<void> {
	const server = createMcpServer();
	const transport = new StdioServerTransport();
	await server.connect(transport);
	log.info('MCP stdio transport ready');
}

// ---------------------------------------------------------------------------
// HTTP transport (optional)
// ---------------------------------------------------------------------------

interface StartedHttpServer {
	close: () => Promise<void>;
	address: () => ReturnType<ReturnType<typeof createServer>['address']>;
	config: GuardConfig;
}

/**
 * Boots the HTTP transport on the given port.
 *
 * Loads {@link GuardConfig} eagerly and THROWS if the configuration is bad
 * (missing token / origins outside dev mode). The caller is responsible for
 * surfacing the error — `main()` logs and exits non-zero.
 */
export async function startHttp(port: number): Promise<StartedHttpServer> {
	const config = loadGuardConfig(); // may throw — intentional
	const httpGuard = buildHttpGuard(config);

	const server = createMcpServer();
	const mcpTransport = new StreamableHTTPServerTransport({
		sessionIdGenerator: () => crypto.randomUUID(),
	});
	await server.connect(mcpTransport as Transport);

	async function handleHttpRequest(req: IncomingMessage, res: ServerResponse): Promise<void> {
		const url = req.url ?? '/';

		// Health check — no auth required; advertises contract_version
		if (url === '/health') {
			res.writeHead(200, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ status: 'ok', contract_version: CONTRACT_VERSION, version: '1.0.0-alpha.1' }));
			return;
		}

		// Apply security guards to all other routes
		const allowed = await httpGuard(req, res);
		if (!allowed) return; // guard already wrote the response

		// Proxy route — buffer body with the configured size limit.
		// getProxyConfig() is called here (not the module-level proxyConfig) so
		// that env vars set after import are honoured (e.g. in tests).
		if (url.startsWith('/proxy') && getProxyConfig()) {
			const body = await readBodyWithLimit(req, res, config.maxBodyBytes);
			if (body === null) return; // 413 written by readBodyWithLimit
			await handleProxy(req, res, body);
			return;
		}

		// QA REST routes — POST only, body already size-limited.
		if (['/screenshot', '/diff', '/axe', '/layout', '/lighthouse', '/prompt-to-spec'].includes(url)) {
			if (req.method !== 'POST') {
				res.writeHead(405, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ error: 'Method not allowed' }));
				return;
			}
			const body = await readBodyWithLimit(req, res, config.maxBodyBytes);
			if (body === null) return; // 413
			let parsed: unknown;
			try {
				parsed = body.length > 0 ? JSON.parse(body.toString('utf8')) : {};
			} catch {
				res.writeHead(400, { 'Content-Type': 'application/json' });
				res.end(JSON.stringify({ error: 'Invalid JSON body' }));
				return;
			}
			const handled = await dispatchQaRoute(url, req, res, parsed);
			if (handled) return;
		}

		// MCP route — only buffer POST bodies (GET / DELETE drive SSE streams
		// the MCP transport manages itself).
		if (url === '/mcp' || url.startsWith('/mcp/')) {
			if (req.method === 'POST') {
				const body = await readBodyWithLimit(req, res, config.maxBodyBytes);
				if (body === null) return; // 413 written
				let parsedBody: unknown = undefined;
				if (body.length > 0) {
					try {
						parsedBody = JSON.parse(body.toString('utf8'));
					} catch (err) {
						res.writeHead(400, { 'Content-Type': 'application/json' });
						res.end(JSON.stringify({ error: 'Invalid JSON body' }));
						log.warn('Rejected MCP POST with invalid JSON', {
							error: err instanceof Error ? err.message : String(err),
						});
						return;
					}
				}
				await mcpTransport.handleRequest(req, res, parsedBody);
				return;
			}
			await mcpTransport.handleRequest(req, res);
			return;
		}

		res.writeHead(404, { 'Content-Type': 'application/json' });
		res.end(JSON.stringify({ error: 'Not found' }));
	}

	// createServer expects a synchronous (void-returning) handler. Wrap the
	// async pipeline in a void IIFE and surface unhandled rejections so they
	// produce a 500 instead of an unhandled-promise-rejection process warning.
	const httpServer = createServer((req: IncomingMessage, res: ServerResponse) => {
		handleHttpRequest(req, res).catch((err: unknown) => {
			log.error('Unhandled HTTP handler error', {
				error: err instanceof Error ? err.message : String(err),
			});
			if (!res.headersSent) {
				try {
					res.writeHead(500, { 'Content-Type': 'application/json' });
					res.end(JSON.stringify({ error: 'Internal server error' }));
				} catch {
					// Socket may already be closed; nothing else we can do.
				}
			}
		});
	});

	await new Promise<void>((resolve, reject) => {
		httpServer.listen(port, config.bindHost, () => {
			log.info('MCP HTTP transport ready', {
				port,
				host: config.bindHost,
				devInsecure: config.devInsecure,
				maxBodyBytes: config.maxBodyBytes,
			});
			resolve();
		});
		httpServer.once('error', reject);
	});

	// Graceful shutdown wiring — caller manages process-level signals when
	// startHttp() is invoked from main(); tests just call .close().
	return {
		close: () =>
			new Promise<void>((resolve, reject) => {
				httpServer.close((err) => (err ? reject(err) : resolve()));
			}),
		address: () => httpServer.address(),
		config,
	};
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
	log.info('Stonewright companion starting');

	// First-run readiness check — verifies Playwright browsers are installed
	// and prints clear setup instructions if they are not.
	const readiness = checkReadiness();
	if (!readiness.ok) {
		// Write to stderr so it is always visible even in stdio MCP mode.
		process.stderr.write(readiness.instructions);
		process.exit(1);
	}

	const portRaw = process.env['PORT'];
	const port = portRaw ? Number(portRaw) : null;

	await startStdio();

	if (port && Number.isFinite(port)) {
		const httpServer = await startHttp(port);

		const shutdown = async (): Promise<void> => {
			log.info('Shutting down');
			await closeBrowser();
			await httpServer.close().catch(() => undefined);
			process.exit(0);
		};
		process.once('SIGTERM', () => void shutdown());
		process.once('SIGINT', () => void shutdown());
	}

	if (proxyConfig) {
		log.info('MCP proxy enabled', { target: proxyConfig.target });
	}
}

// Only run main() when this file is the entrypoint. Tests import the module
// to invoke `startHttp()` directly — main() must not race with the test setup
// in that case.
//
// realpathSync is used so symlink-installed bins (npm link, pnpm) resolve to
// the same physical file as import.meta.url — the naive endsWith check breaks
// when the argv[1] path differs from the module's canonical location.
function isMainModule(): boolean {
	try {
		return (
			realpathSync(fileURLToPath(import.meta.url)) === realpathSync(process.argv[1] ?? '')
		);
	} catch {
		return false;
	}
}

if (isMainModule()) {
	main().catch((err: unknown) => {
		log.error('Fatal startup error', { error: err instanceof Error ? err.message : String(err) });
		process.exit(1);
	});
}
