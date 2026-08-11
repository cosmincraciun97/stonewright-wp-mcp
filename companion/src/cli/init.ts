/**
 * Interactive Direct-mode setup — compatibility alias for `connect add`.
 * Refuses silent overwrite of an existing default site without --replace.
 *
 * Usage: node dist/index.js init
 */
import { createInterface } from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { existsSync } from 'node:fs';
import { APP_VERSION } from '../version.js';
import { connectAdd } from './connect/commands.js';
import { findSiteByAlias, loadRegistry } from './connect/registry.js';
import { MemoryCredentialStore } from '../credentials/index.js';

async function ask(rl: ReturnType<typeof createInterface>, q: string): Promise<string> {
	const a = await rl.question(q);
	return a.trim();
}

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

function parseInitFlags(argv: string[]): {
	replace: boolean;
	alias?: string | undefined;
	url?: string | undefined;
	username?: string | undefined;
	password?: string | undefined;
	nonInteractive: boolean;
	sitesFile?: string | undefined;
	credentialEnv?: string | undefined;
	client?: string | undefined;
	skipAuth: boolean;
} {
	const flags: Record<string, string | boolean> = {};
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		if (!a.startsWith('--')) continue;
		const key = a.slice(2);
		const next = argv[i + 1];
		if (next !== undefined && !next.startsWith('-')) {
			flags[key] = next;
			i += 1;
		} else {
			flags[key] = true;
		}
	}
	const str = (k: string) => (typeof flags[k] === 'string' ? (flags[k]) : undefined);
	const out: ReturnType<typeof parseInitFlags> = {
		replace: flags.replace === true || flags.replace === 'true',
		nonInteractive: flags.yes === true || Boolean(str('url') && str('username') && str('password')),
		skipAuth: flags['skip-auth'] === true,
	};
	const alias = str('alias');
	if (alias !== undefined) out.alias = alias;
	const url = str('url');
	if (url !== undefined) out.url = url;
	const username = str('username');
	if (username !== undefined) out.username = username;
	const password = str('password');
	if (password !== undefined) out.password = password;
	const sitesFile = str('sites-file');
	if (sitesFile !== undefined) out.sitesFile = sitesFile;
	const credentialEnv = str('credential-env');
	if (credentialEnv !== undefined) out.credentialEnv = credentialEnv;
	const client = str('client');
	if (client !== undefined) out.client = client;
	return out;
}

export async function runInit(argv: string[] = []): Promise<number> {
	const flags = parseInitFlags(argv);
	const home = homedir();
	const sitesFile = flags.sitesFile ?? join(home, '.stonewright', 'sites.json');

	writeOut(`Stonewright companion ${APP_VERSION} — Direct mode setup (connect add)\n`);

	// Refuse silent default overwrite
	if (existsSync(sitesFile)) {
		try {
			const { registry } = loadRegistry({ sitesFile, homeDir: home });
			const defaultAlias = flags.alias ?? 'default';
			const existing = findSiteByAlias(registry, defaultAlias);
			const anySites = registry.sites.length > 0;
			if (existing && !flags.replace) {
				writeErr(
					`Site alias "${existing.alias}" already exists (id=${existing.id}). ` +
						`Clients: ${Object.keys(existing.clients).join(', ') || '(none)'}. ` +
						`Refusing silent overwrite — re-run with --replace, or use a different --alias.`,
				);
				return 1;
			}
			if (!existing && anySites && defaultAlias === 'default' && !flags.replace && !flags.alias) {
				// Historic init always wrote key "default". If any sites exist and user
				// would overwrite default path implicitly, require explicit alias or replace.
				const defaultSite = registry.default_site_id
					? registry.sites.find((s) => s.id === registry.default_site_id)
					: registry.sites[0];
				if (defaultSite && findSiteByAlias(registry, 'default')) {
					writeErr(
						`A "default" site already exists. Refusing silent overwrite — pass --replace or --alias <new>.`,
					);
					return 1;
				}
			}
		} catch {
			// Unreadable registry: connect add will surface a clearer error.
		}
	}

	let alias = flags.alias ?? 'default';
	let url = flags.url ?? '';
	let username = flags.username ?? '';
	let password = flags.password ?? '';

	if (!flags.nonInteractive) {
		const rl = createInterface({ input, output });
		try {
			if (!url) url = await ask(rl, 'WordPress site URL (http(s)://…): ');
			if (!username) username = await ask(rl, 'Username: ');
			if (!password) password = await ask(rl, 'Application Password: ');
			const aliasAnswer = await ask(rl, `Site alias [${alias}]: `);
			if (aliasAnswer) alias = aliasAnswer;
		} finally {
			rl.close();
		}
	}

	if (!url || !username || (!password && !flags.credentialEnv)) {
		writeErr('URL, username, and Application Password are required.');
		return 1;
	}

	// Prefer OS store; fall back to memory only when explicitly testing via credential-env absence on CI is handled by connectAdd error.
	const credentials =
		process.env.STONEWRIGHT_CREDENTIAL_STORE === 'memory'
			? { store: new MemoryCredentialStore(), prefer: 'memory' as const, allowMemoryFallback: true }
			: { allowMemoryFallback: false };

	const code = await connectAdd(
		{
			alias,
			url,
			username,
			password,
			environment: 'other',
			mode: 'auto',
			replace: flags.replace,
			makeDefault: true,
			...(flags.credentialEnv ? { credentialEnv: flags.credentialEnv } : {}),
			...(flags.client ? { client: flags.client } : {}),
			yes: true,
		},
		{
			sitesFile,
			homeDir: home,
			credentials,
			skipAuth: flags.skipAuth,
		},
	);

	if (code === 0) {
		const pkg = `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v${APP_VERSION}/stonewright-companion-${APP_VERSION}.tgz`;
		const config = {
			mcpServers: {
				[`stonewright-${alias === 'default' ? 'default' : alias}`]: {
					command: 'npx',
					args: ['-y', '--package', pkg, 'stonewright-mcp'],
					env: {
						STONEWRIGHT_MODE: 'auto',
						STONEWRIGHT_MCP_TOOL_PROFILE: 'essential-static',
						STONEWRIGHT_SITE_ALIAS: alias,
					},
				},
			},
		};
		writeOut('\nPaste this secret-free block into your MCP client config:\n');
		writeOut(JSON.stringify(config, null, 2));
		writeOut(
			'\nCredentials stay in the OS credential store (or env:// ref), never plaintext in new sites.json writes. Restart the client, call stonewright-task-start, then stonewright-site-discover.',
		);
		writeOut('Tip: prefer `stonewright connect add` for multi-site / multi-client installs.');
	}
	return code;
}
