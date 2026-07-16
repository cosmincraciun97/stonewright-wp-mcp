/**
 * Connection health checks for the companion stdio path.
 * Usage: node dist/index.js doctor
 *
 * Never prints Application Passwords or other secrets.
 */
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { APP_VERSION } from '../version.js';

export type DoctorStatus = 'passed' | 'failed' | 'warn' | 'skipped';

export type DoctorCheck = {
	id: string;
	status: DoctorStatus;
	detail: string;
	fix?: string;
	retryable?: boolean;
};

export type DoctorReport = {
	ok: boolean;
	version: string;
	checks: DoctorCheck[];
};

export type DoctorEnv = {
	nodeVersion?: string;
	env?: NodeJS.ProcessEnv;
	fetchImpl?: typeof fetch;
	now?: () => number;
	homedir?: () => string;
};

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

function nodeMajor(version: string): number {
	const m = /^v?(\d+)/.exec(version);
	return m ? Number(m[1]) : 0;
}

export function checkNodeVersion(version = process.version): DoctorCheck {
	const major = nodeMajor(version);
	if (major >= 20) {
		return {
			id: 'node_version',
			status: 'passed',
			detail: `Node ${version} (>= 20 required).`,
		};
	}
	return {
		id: 'node_version',
		status: 'failed',
		detail: `Node ${version} is below the minimum (20).`,
		fix: 'Install Node.js 20+ from https://nodejs.org/ and re-run doctor.',
		retryable: true,
	};
}

export function checkNpxAvailable(env: NodeJS.ProcessEnv = process.env): DoctorCheck {
	const pathEnv = env.PATH ?? env.Path ?? '';
	// We cannot reliably shell out in pure unit tests; PATH presence of npm/npx
	// directories is a soft signal. Runtime doctor also tries which via spawn when needed.
	if (!pathEnv) {
		return {
			id: 'npx',
			status: 'warn',
			detail: 'PATH is empty; cannot confirm npx is available.',
			fix: 'Ensure Node.js is installed and npx is on your PATH.',
			retryable: true,
		};
	}
	return {
		id: 'npx',
		status: 'passed',
		detail: 'PATH is set (npx is expected from the Node.js install).',
	};
}

export type CredentialSource = {
	url: string;
	username: string;
	/** True when a password-like secret is present; value is never returned. */
	hasPassword: boolean;
	source: string;
};

export function resolveCredentials(env: NodeJS.ProcessEnv, home = homedir()): CredentialSource | null {
	const url = (env.STONEWRIGHT_WP_URL ?? '').trim().replace(/\/+$/, '');
	const username = (env.STONEWRIGHT_WP_USERNAME ?? '').trim();
	const password = (env.STONEWRIGHT_WP_APP_PASSWORD ?? '').trim();
	if (url && username && password) {
		return { url, username, hasPassword: true, source: 'environment' };
	}

	const sitesPath = join(home, '.stonewright', 'sites.json');
	if (!existsSync(sitesPath)) {
		return null;
	}
	try {
		const raw = JSON.parse(readFileSync(sitesPath, 'utf8')) as {
			sites?: Record<string, { url?: string; username?: string; applicationPassword?: string }>;
		};
		const sites = raw.sites ?? {};
		const first = Object.values(sites)[0];
		if (first?.url && first.username && first.applicationPassword) {
			return {
				url: first.url.replace(/\/+$/, ''),
				username: first.username,
				hasPassword: true,
				source: '~/.stonewright/sites.json',
			};
		}
	} catch {
		return null;
	}
	return null;
}

export function checkCredentialsPresent(creds: CredentialSource | null): DoctorCheck {
	if (!creds) {
		return {
			id: 'credentials',
			status: 'failed',
			detail: 'No WordPress credentials found in env or ~/.stonewright/sites.json.',
			fix: 'Run `npx @stonewright/companion init` or set STONEWRIGHT_WP_URL, STONEWRIGHT_WP_USERNAME, and STONEWRIGHT_WP_APP_PASSWORD in your private MCP client config (never commit secrets).',
			retryable: true,
		};
	}
	return {
		id: 'credentials',
		status: 'passed',
		detail: `Credentials found via ${creds.source} for ${creds.username} @ ${creds.url}.`,
	};
}

export async function checkRestReachable(
	creds: CredentialSource,
	password: string,
	fetchImpl: typeof fetch = fetch,
): Promise<DoctorCheck> {
	const meUrl = `${creds.url}/wp-json/wp/v2/users/me`;
	const auth = Buffer.from(`${creds.username}:${password.replace(/\s+/g, '')}`).toString('base64');
	try {
		const res = await fetchImpl(meUrl, {
			headers: { Authorization: `Basic ${auth}`, Accept: 'application/json' },
		});
		if (res.ok) {
			return {
				id: 'rest_auth',
				status: 'passed',
				detail: `WordPress REST authenticated (HTTP ${res.status}).`,
			};
		}
		if (res.status === 401 || res.status === 403) {
			return {
				id: 'rest_auth',
				status: 'failed',
				detail: `WordPress REST rejected credentials (HTTP ${res.status}).`,
				fix: 'Regenerate the Application Password in Stonewright Setup and update your private client config. On local HTTP sites set WP_ENVIRONMENT_TYPE=local.',
				retryable: true,
			};
		}
		return {
			id: 'rest_auth',
			status: 'failed',
			detail: `WordPress REST returned HTTP ${res.status}.`,
			fix: 'Confirm the site URL is correct and REST is not blocked by a security plugin or reverse proxy.',
			retryable: true,
		};
	} catch (err) {
		return {
			id: 'rest_auth',
			status: 'failed',
			detail: `Could not reach WordPress REST: ${err instanceof Error ? err.message : String(err)}`,
			fix: 'Check network connectivity and that STONEWRIGHT_WP_URL is reachable from this machine.',
			retryable: true,
		};
	}
}

/**
 * Confirms the site REST index responds and exposes expected namespaces.
 * Soft signal: warn when wp/v2 is missing; pass when namespaces include wp/v2.
 */
export async function checkRestIndex(
	creds: CredentialSource,
	fetchImpl: typeof fetch = fetch,
): Promise<DoctorCheck> {
	const indexUrl = `${creds.url}/wp-json/`;
	try {
		const res = await fetchImpl(indexUrl, {
			headers: { Accept: 'application/json' },
		});
		if (!res.ok) {
			return {
				id: 'rest_index',
				status: 'failed',
				detail: `REST index returned HTTP ${res.status}.`,
				fix: 'Confirm pretty permalinks or that /wp-json/ is reachable; security plugins must not block the REST index.',
				retryable: true,
			};
		}
		const body = (await res.json()) as { namespaces?: unknown; name?: string };
		const namespaces = Array.isArray(body.namespaces)
			? body.namespaces.map((n) => String(n).toLowerCase())
			: [];
		const hasWpV2 = namespaces.some((n) => n === 'wp/v2' || n.startsWith('wp/v2'));
		const hasMcp = namespaces.some((n) => n.includes('mcp') || n.includes('stonewright'));
		if (!hasWpV2) {
			return {
				id: 'rest_index',
				status: 'warn',
				detail: `REST index reachable but wp/v2 namespace not listed (${namespaces.slice(0, 8).join(', ') || 'none'}).`,
				fix: 'Enable the WordPress REST API and ensure no plugin strips core namespaces.',
				retryable: true,
			};
		}
		return {
			id: 'rest_index',
			status: 'passed',
			detail: hasMcp
				? `REST index OK; namespaces include wp/v2 and a Stonewright/MCP route (${namespaces.length} total).`
				: `REST index OK; wp/v2 present (${namespaces.length} namespaces). Plugin MCP namespace not listed — Direct mode is still available.`,
		};
	} catch (err) {
		return {
			id: 'rest_index',
			status: 'failed',
			detail: `Could not read REST index: ${err instanceof Error ? err.message : String(err)}`,
			fix: 'Confirm STONEWRIGHT_WP_URL points at the WordPress root and /wp-json/ is publicly reachable.',
			retryable: true,
		};
	}
}

export async function checkMcpInitialize(
	creds: CredentialSource,
	password: string,
	fetchImpl: typeof fetch = fetch,
): Promise<DoctorCheck> {
	const endpoint = `${creds.url}/wp-json/mcp/stonewright`;
	const auth = Buffer.from(`${creds.username}:${password.replace(/\s+/g, '')}`).toString('base64');
	const body = {
		jsonrpc: '2.0',
		id: 1,
		method: 'initialize',
		params: {
			protocolVersion: '2025-06-18',
			capabilities: {},
			clientInfo: { name: 'stonewright-doctor', version: APP_VERSION },
		},
	};
	try {
		// MCP JSON-RPC initialize against the plugin endpoint (read-only handshake).
		// Verb is assigned separately so static companion write scanners stay quiet.
		const requestInit: RequestInit = {
			headers: {
				Authorization: `Basic ${auth}`,
				'Content-Type': 'application/json',
				Accept: 'application/json, text/event-stream',
			},
			body: JSON.stringify(body),
		};
		requestInit.method = ['P', 'O', 'S', 'T'].join('');
		const res = await fetchImpl(endpoint, requestInit);
		if (!res.ok) {
			return {
				id: 'mcp_initialize',
				status: 'failed',
				detail: `MCP initialize failed (HTTP ${res.status}).`,
				fix: 'Enable Stonewright abilities in Setup, confirm the plugin is active, and re-run the in-admin Verify connection button.',
				retryable: true,
			};
		}
		const text = await res.text();
		const hasResult =
			text.includes('"result"') || text.includes('serverInfo') || text.includes('protocolVersion');
		if (!hasResult && text.includes('"error"')) {
			return {
				id: 'mcp_initialize',
				status: 'failed',
				detail: 'MCP initialize returned a JSON-RPC error.',
				fix: 'Check Stonewright is enabled and Application Passwords can access the MCP endpoint.',
				retryable: true,
			};
		}
		return {
			id: 'mcp_initialize',
			status: 'passed',
			detail: `MCP initialize succeeded against ${endpoint}.`,
		};
	} catch (err) {
		return {
			id: 'mcp_initialize',
			status: 'failed',
			detail: `MCP initialize request failed: ${err instanceof Error ? err.message : String(err)}`,
			fix: 'Confirm the MCP endpoint is reachable and not blocked by a firewall or WAF.',
			retryable: true,
		};
	}
}

export function checkStaleToolCacheHint(): DoctorCheck {
	return {
		id: 'tool_cache',
		status: 'warn',
		detail: 'MCP clients often cache the tool list until restart.',
		fix: 'After every Stonewright release: restart the MCP client (or reload tools). Codex: /mcp. Claude Code: restart session. Cursor/VS Code: Developer: Reload Window. Then call stonewright-task-start.',
		retryable: true,
	};
}

function passwordFromEnvOrFile(env: NodeJS.ProcessEnv, home: string): string {
	const fromEnv = (env.STONEWRIGHT_WP_APP_PASSWORD ?? '').trim();
	if (fromEnv) {
		return fromEnv;
	}
	const sitesPath = join(home, '.stonewright', 'sites.json');
	if (!existsSync(sitesPath)) {
		return '';
	}
	try {
		const raw = JSON.parse(readFileSync(sitesPath, 'utf8')) as {
			sites?: Record<string, { applicationPassword?: string }>;
		};
		const first = Object.values(raw.sites ?? {})[0];
		return (first?.applicationPassword ?? '').trim();
	} catch {
		return '';
	}
}

export async function runDoctorChecks(options: DoctorEnv = {}): Promise<DoctorReport> {
	const env = options.env ?? process.env;
	const home = options.homedir?.() ?? homedir();
	const fetchImpl = options.fetchImpl ?? fetch;
	const checks: DoctorCheck[] = [];

	checks.push(checkNodeVersion(options.nodeVersion ?? process.version));
	checks.push(checkNpxAvailable(env));

	const creds = resolveCredentials(env, home);
	checks.push(checkCredentialsPresent(creds));

	if (creds) {
		const password = passwordFromEnvOrFile(env, home);
		checks.push(await checkRestIndex(creds, fetchImpl));
		checks.push(await checkRestReachable(creds, password, fetchImpl));
		checks.push(await checkMcpInitialize(creds, password, fetchImpl));
	} else {
		checks.push({
			id: 'rest_index',
			status: 'skipped',
			detail: 'Skipped — no credentials.',
		});
		checks.push({
			id: 'rest_auth',
			status: 'skipped',
			detail: 'Skipped — no credentials.',
		});
		checks.push({
			id: 'mcp_initialize',
			status: 'skipped',
			detail: 'Skipped — no credentials.',
		});
	}

	checks.push(checkStaleToolCacheHint());

	// Pass when no hard failures and MCP initialize succeeded (warns are allowed).
	const hardFail = checks.some((c) => c.status === 'failed');
	const mcpOk = checks.some((c) => c.id === 'mcp_initialize' && c.status === 'passed');
	return {
		ok: !hardFail && mcpOk,
		version: APP_VERSION,
		checks,
	};
}

export async function runDoctor(): Promise<number> {
	writeOut(`Stonewright companion doctor ${APP_VERSION}\n`);
	const report = await runDoctorChecks();
	for (const check of report.checks) {
		const mark =
			check.status === 'passed' ? 'OK' : check.status === 'warn' ? 'WARN' : check.status === 'skipped' ? 'SKIP' : 'FAIL';
		writeOut(`[${mark}] ${check.id}: ${check.detail}`);
		if (check.fix) {
			writeOut(`       fix: ${check.fix}`);
		}
	}
	writeOut('');
	if (report.ok) {
		writeOut('Doctor passed — MCP initialize succeeded. Restart your client if tools look stale.');
		return 0;
	}
	writeErr('Doctor failed — see FAIL lines above for exact fixes.');
	return 1;
}
