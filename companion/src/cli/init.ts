/**
 * Interactive Direct-mode setup: validates App Password and prints MCP config.
 * Usage: node dist/index.js init   (or npx @stonewright/companion init)
 */
import { createInterface } from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { mkdirSync, writeFileSync, readFileSync, existsSync } from 'node:fs';
import { APP_VERSION } from '../version.js';

async function ask(rl: ReturnType<typeof createInterface>, q: string): Promise<string> {
	const a = await rl.question(q);
	return a.trim();
}

export async function runInit(): Promise<number> {
	const rl = createInterface({ input, output });
	try {
		console.log(`Stonewright companion ${APP_VERSION} — Direct mode setup\n`);
		const url = await ask(rl, 'WordPress site URL (http(s)://…): ');
		const username = await ask(rl, 'Username: ');
		const password = await ask(rl, 'Application Password: ');

		if (!url || !username || !password) {
			console.error('URL, username, and Application Password are required.');
			return 1;
		}

		const base = url.replace(/\/+$/, '');
		const auth = Buffer.from(`${username}:${password.replace(/\s+/g, '')}`).toString('base64');
		const meUrl = `${base}/wp-json/wp/v2/users/me`;
		const res = await fetch(meUrl, {
			headers: { Authorization: `Basic ${auth}`, Accept: 'application/json' },
		});
		if (!res.ok) {
			console.error(`Auth failed: HTTP ${res.status}. Check URL, user, App Password, and WP_ENVIRONMENT_TYPE=local on HTTP.`);
			return 1;
		}
		const me = (await res.json()) as { name?: string; slug?: string };
		console.log(`Authenticated as ${me.name ?? me.slug ?? username}.\n`);

		const dir = join(homedir(), '.stonewright');
		mkdirSync(dir, { recursive: true });
		const sitesPath = join(dir, 'sites.json');
		let sites: { sites: Record<string, unknown> } = { sites: {} };
		if (existsSync(sitesPath)) {
			try {
				sites = JSON.parse(readFileSync(sitesPath, 'utf8')) as typeof sites;
			} catch {
				sites = { sites: {} };
			}
		}
		const key = 'default';
		sites.sites = sites.sites ?? {};
		sites.sites[key] = {
			url: base,
			username,
			applicationPassword: password,
		};
		writeFileSync(sitesPath, JSON.stringify(sites, null, 2) + '\n', { mode: 0o600 });
		console.log(`Wrote ${sitesPath}\n`);

		const pkg = `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${APP_VERSION}/stonewright-companion-${APP_VERSION}.tgz`;
		const config = {
			mcpServers: {
				stonewright: {
					command: 'npx',
					args: ['-y', '--package', pkg, 'stonewright-mcp'],
					env: {
						STONEWRIGHT_MODE: 'direct',
						STONEWRIGHT_WP_URL: base,
						STONEWRIGHT_WP_USERNAME: username,
						STONEWRIGHT_WP_APP_PASSWORD: password,
						STONEWRIGHT_MCP_TOOL_PROFILE: 'essential',
					},
				},
			},
		};

		console.log('Paste this into your MCP client config:\n');
		console.log(JSON.stringify(config, null, 2));
		console.log('\nThen restart the client and try: list my pages / stonewright-site-discover');
		return 0;
	} finally {
		rl.close();
	}
}
