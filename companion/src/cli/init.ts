/**
 * Interactive Direct-mode setup: validates App Password and prints MCP config.
 * Usage: node dist/index.js init   (or npx @stonewright/companion init)
 */
import { createInterface } from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { chmodSync, mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { APP_VERSION } from '../version.js';

async function ask(rl: ReturnType<typeof createInterface>, q: string): Promise<string> {
	const a = await rl.question(q);
	return a.trim();
}

/** console.log-compatible CLI output (always terminates with a newline). */
function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

/** console.error-compatible CLI output on stderr. */
function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

export async function runInit(): Promise<number> {
	const rl = createInterface({ input, output });
	try {
		writeOut(`Stonewright companion ${APP_VERSION} — Direct mode setup\n`);
		const url = await ask(rl, 'WordPress site URL (http(s)://…): ');
		const username = await ask(rl, 'Username: ');
		const password = await ask(rl, 'Application Password: ');

		if (!url || !username || !password) {
			writeErr('URL, username, and Application Password are required.');
			return 1;
		}

		const base = url.replace(/\/+$/, '');
		const auth = Buffer.from(`${username}:${password.replace(/\s+/g, '')}`).toString('base64');
		const meUrl = `${base}/wp-json/wp/v2/users/me`;
		const res = await fetch(meUrl, {
			headers: { Authorization: `Basic ${auth}`, Accept: 'application/json' },
		});
		if (!res.ok) {
			writeErr(`Auth failed: HTTP ${res.status}. Check URL, user, App Password, and WP_ENVIRONMENT_TYPE=local on HTTP.`);
			return 1;
		}
		const me = (await res.json()) as { name?: string; slug?: string };
		writeOut(`Authenticated as ${me.name ?? me.slug ?? username}.\n`);

		const dir = join(homedir(), '.stonewright');
		mkdirSync(dir, { recursive: true, mode: 0o700 });
		if (process.platform !== 'win32') {
			chmodSync(dir, 0o700);
		}
		const sitesPath = join(dir, 'sites.json');
		let sites: { sites: Record<string, unknown> } = { sites: {} };
		if (existsSync(sitesPath)) {
			try {
				sites = JSON.parse(readFileSync(sitesPath, 'utf8')) as typeof sites;
			} catch {
				writeErr(`Existing ${sitesPath} is invalid JSON. It was not changed.`);
				return 1;
			}
		}
		const key = 'default';
		sites.sites = sites.sites ?? {};
		sites.sites[key] = {
			url: base,
			username,
			appPassword: password,
		};
		writeFileSync(sitesPath, JSON.stringify(sites, null, 2) + '\n', { mode: 0o600 });
		if (process.platform !== 'win32') {
			chmodSync(sitesPath, 0o600);
		}
		writeOut(`Wrote ${sitesPath}\n`);

		const pkg = `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${APP_VERSION}/stonewright-companion-${APP_VERSION}.tgz`;
		const config = {
			mcpServers: {
				stonewright: {
					command: 'npx',
					args: ['-y', '--package', pkg, 'stonewright-mcp'],
					env: {
						STONEWRIGHT_MODE: 'direct',
						STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
					},
				},
			},
		};

		writeOut('Paste this secret-free block into your MCP client config:\n');
		writeOut(JSON.stringify(config, null, 2));
		writeOut(
			'\nCredentials stay only in ~/.stonewright/sites.json. Restart the client, call stonewright-task-start, then stonewright-site-discover.',
		);
		return 0;
	} finally {
		rl.close();
	}
}
