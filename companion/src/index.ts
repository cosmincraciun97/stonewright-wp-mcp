/**
 * Stonewright Companion - entry point.
 *
 * Boots the MCP server in two modes simultaneously:
 *   1. stdio - always active; used by Claude Code / local MCP clients.
 *   2. Streamable HTTP - activated when PORT is set; guarded by origin +
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
import { CONTRACT_VERSION } from './contracts/version.js';
import { APP_VERSION } from './version.js';
import {
	runWpCli,
	runWpCliBatch,
	wpCliDiscover,
	wpCliStatus,
	wpCliEnsureReady,
	type WpCliBatchRunInput,
	type WpCliRunInput,
} from './wp-cli.js';

// ---------------------------------------------------------------------------
// Stdio transport (always on)
// ---------------------------------------------------------------------------

async function startStdio(): Promise<void> {
	const server = await createMcpServer();
	const transport = new StdioServerTransport();
	await server.connect(transport);
	log.info('MCP stdio transport ready');
	// Non-blocking WP-CLI readiness probe — logs result, never blocks stdio startup.
	void wpCliEnsureReady({ env: process.env }).then((result) => {
		if (result.ensured) {
			log.info('WP-CLI ready', { source: result.source, installed: result.installed, path: result.installPath });
		} else {
			log.warn('WP-CLI not available after bootstrap attempt', { source: result.source, error: result.error });
		}
	}).catch((err: unknown) => {
		log.warn('WP-CLI bootstrap probe failed', { error: err instanceof Error ? err.message : String(err) });
	});
}

// ---------------------------------------------------------------------------
// HTTP transport (optional)
// ---------------------------------------------------------------------------

export interface StartedHttpServer {
	close: () => Promise<void>;
	address: () => ReturnType<ReturnType<typeof createServer>['address']>;
	config: GuardConfig;
}

export interface OptionalHttpStartResult {
	requested: boolean;
	started: boolean;
	port: number | null;
	server?: StartedHttpServer;
	error?: string;
}

/**
 * Boots the HTTP transport on the given port.
 *
 * Loads {@link GuardConfig} eagerly and THROWS if the configuration is bad
 * (missing token / origins outside dev mode). The caller is responsible for
 * surfacing the error - `main()` logs and exits non-zero.
 */
export async function startHttp(port: number): Promise<StartedHttpServer> {
	const config = loadGuardConfig(); // may throw - intentional
	const httpGuard = buildHttpGuard(config);

	const server = await createMcpServer();
	const mcpTransport = new StreamableHTTPServerTransport({
		sessionIdGenerator: () => crypto.randomUUID(),
	});
	await server.connect(mcpTransport as Transport);
	// Non-blocking WP-CLI readiness probe — auto-downloads phar if missing.
	void wpCliEnsureReady({ env: process.env }).then((result) => {
		if (result.ensured) {
			log.info('WP-CLI ready', { source: result.source, installed: result.installed, path: result.installPath });
		} else {
			log.warn('WP-CLI not available after bootstrap attempt', { source: result.source, error: result.error });
		}
	}).catch((err: unknown) => {
		log.warn('WP-CLI bootstrap probe failed', { error: err instanceof Error ? err.message : String(err) });
	});

	async function handleHttpRequest(req: IncomingMessage, res: ServerResponse): Promise<void> {
		const url = req.url ?? '/';

		// Health check - no auth required; advertises contract_version
		if (url === '/health') {
			res.writeHead(200, { 'Content-Type': 'application/json' });
			res.end(JSON.stringify({ status: 'ok', contract_version: CONTRACT_VERSION, version: APP_VERSION }));
			return;
		}

		// Apply security guards to all other routes
		const allowed = await httpGuard(req, res);
		if (!allowed) return; // guard already wrote the response

		if (url === '/wp-cli/status' || url === '/wp-cli/discover' || url === '/wp-cli/run' || url === '/wp-cli/batch') {
			if (req.method !== 'POST') {
				writeJson(res, 405, { error: 'Method not allowed' });
				return;
			}

			const body = await readJsonBody(req, res, config.maxBodyBytes);
			if (body === null) return;

			try {
				const input = stripUndefined(body);
				const result = url === '/wp-cli/status'
					? await wpCliStatus(input as Partial<WpCliRunInput>)
					: url === '/wp-cli/discover'
						? await wpCliDiscover(input as Partial<WpCliRunInput>)
						: url === '/wp-cli/batch'
							? await runWpCliBatch(input as unknown as WpCliBatchRunInput)
							: await runWpCli(input as unknown as WpCliRunInput);
				writeJson(res, 200, result);
			} catch (err) {
				writeJson(res, 400, { error: err instanceof Error ? err.message : String(err) });
			}
			return;
		}

		// Proxy route - buffer body with the configured size limit.
		// getProxyConfig() is called here (not the module-level proxyConfig) so
		// that env vars set after import are honoured (e.g. in tests).
		if (url.startsWith('/proxy') && getProxyConfig()) {
			const body = await readBodyWithLimit(req, res, config.maxBodyBytes);
			if (body === null) return; // 413 written by readBodyWithLimit
			await handleProxy(req, res, body);
			return;
		}

		// MCP route - only buffer POST bodies (GET / DELETE drive SSE streams
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

	// Graceful shutdown wiring - caller manages process-level signals when
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

export async function startOptionalHttpFromEnv(
	env: NodeJS.ProcessEnv = process.env,
	starter: (port: number) => Promise<StartedHttpServer> = startHttp,
): Promise<OptionalHttpStartResult> {
	const port = optionalHttpPort(env['PORT']);
	if (port === null) {
		return { requested: false, started: false, port: null };
	}

	try {
		const server = await starter(port);
		return { requested: true, started: true, port, server };
	} catch (err) {
		if (httpRequired(env)) {
			throw err;
		}

		const error = err instanceof Error ? err.message : String(err);
		log.warn('Optional companion HTTP transport failed; stdio MCP remains active', { port, error });
		return { requested: true, started: false, port, error };
	}
}

function optionalHttpPort(raw: string | undefined): number | null {
	if (!raw) return null;
	const port = Number(raw);
	return Number.isFinite(port) && port > 0 ? port : null;
}

function httpRequired(env: NodeJS.ProcessEnv): boolean {
	return ['1', 'true', 'yes', 'on'].includes((env['STONEWRIGHT_HTTP_REQUIRED'] ?? '').trim().toLowerCase());
}

async function readJsonBody(
	req: IncomingMessage,
	res: ServerResponse,
	maxBodyBytes: number,
): Promise<Record<string, unknown> | null> {
	const body = await readBodyWithLimit(req, res, maxBodyBytes);
	if (body === null) return null;
	if (body.length === 0) return {};

	try {
		const decoded = JSON.parse(body.toString('utf8')) as unknown;
		if (decoded && typeof decoded === 'object') {
			if (Array.isArray(decoded)) {
				if (decoded.length === 0) {
					return {};
				}
			} else {
				return decoded as Record<string, unknown>;
			}
		}
		writeJson(res, 400, { error: 'JSON body must be an object' });
		return null;
	} catch {
		writeJson(res, 400, { error: 'Invalid JSON body' });
		return null;
	}
}

function writeJson(res: ServerResponse, status: number, body: Record<string, unknown>): void {
	res.writeHead(status, { 'Content-Type': 'application/json' });
	res.end(JSON.stringify(body));
}

function stripUndefined(input: Record<string, unknown>): Record<string, unknown> {
	return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined));
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
	log.info('Stonewright companion starting');

	await startStdio();

	const optionalHttp = await startOptionalHttpFromEnv(process.env);
	if (optionalHttp.server) {
		const httpServer = optionalHttp.server;

		const shutdown = async (): Promise<void> => {
			log.info('Shutting down');
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
// to invoke `startHttp()` directly - main() must not race with the test setup
// in that case.
//
// realpathSync is used so symlink-installed bins (npm link, pnpm) resolve to
// the same physical file as import.meta.url - the naive endsWith check breaks
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
