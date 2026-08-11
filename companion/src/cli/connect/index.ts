/**
 * `stonewright connect <subcommand>` entrypoint.
 */
import {
	connectAdd,
	connectDetectClient,
	connectList,
	connectMigrate,
	connectRemove,
	connectRepair,
	connectUse,
	connectVerify,
	type ConnectContext,
} from './commands.js';
import type { ConfiguredMode, SiteEnvironment } from './types.js';

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function usage(): void {
	writeOut(`Stonewright connect — multi-site registry and client installer

Usage:
  stonewright connect detect-client
  stonewright connect add --alias <alias> --url <url> --username <user> --password <pass> [options]
  stonewright connect list
  stonewright connect use <alias>
  stonewright connect verify <alias> [--client <id>]
  stonewright connect repair <alias> [--client <id>]
  stonewright connect remove <alias> [--client <id>]
  stonewright connect migrate

add options:
  --alias <name>           Site alias (case-insensitive unique)
  --url <url>              WordPress site URL
  --username <user>        WordPress username
  --password <pass>        Application Password (never stored in sites.json)
  --env <environment>      local|development|staging|production|other
  --mode <mode>            direct-only|plugin-only|auto
  --client <id>            Bind MCP client (codex|cursor|claude-desktop|vscode-copilot|generic-mcp)
  --client-config <path>   Override client config path
  --profile <name>         Companion tool profile (default essential-static)
  --replace                Replace existing alias (shows affected clients)
  --default                Make this the default site
  --credential-env <VAR>   Store env://VAR ref instead of OS keychain
  --sites-file <path>      Override sites.json path
  --skip-auth              Skip live REST auth (tests / offline)
`);
}

function parseArgs(argv: string[]): { command: string; positionals: string[]; flags: Record<string, string | boolean> } {
	const flags: Record<string, string | boolean> = {};
	const positionals: string[] = [];
	let command = '';
	for (let i = 0; i < argv.length; i++) {
		const a = argv[i];
		if (!command && !a.startsWith('-')) {
			command = a;
			continue;
		}
		if (a === '--help' || a === '-h') {
			flags.help = true;
			continue;
		}
		if (a.startsWith('--')) {
			const key = a.slice(2);
			const next = argv[i + 1];
			if (next !== undefined && !next.startsWith('-')) {
				flags[key] = next;
				i += 1;
			} else {
				flags[key] = true;
			}
			continue;
		}
		positionals.push(a);
	}
	return { command, positionals, flags };
}

function flagString(flags: Record<string, string | boolean>, name: string): string | undefined {
	const v = flags[name];
	return typeof v === 'string' ? v : undefined;
}

function flagBool(flags: Record<string, string | boolean>, name: string): boolean {
	return flags[name] === true || flags[name] === 'true';
}

function buildCtx(flags: Record<string, string | boolean>): ConnectContext {
	const ctx: ConnectContext = {};
	const sitesFile = flagString(flags, 'sites-file');
	if (sitesFile) ctx.sitesFile = sitesFile;
	if (flagBool(flags, 'skip-auth')) ctx.skipAuth = true;
	return ctx;
}

export async function runConnect(argv: string[]): Promise<number> {
	const { command, positionals, flags } = parseArgs(argv);
	if (!command || flags.help) {
		usage();
		return command ? 0 : 1;
	}

	const ctx = buildCtx(flags);

	try {
		switch (command) {
			case 'detect-client':
				return connectDetectClient(ctx);
			case 'list':
				return connectList(ctx);
			case 'add': {
				const alias = flagString(flags, 'alias') ?? positionals[0];
				const url = flagString(flags, 'url');
				const username = flagString(flags, 'username');
				const password = flagString(flags, 'password');
				if (!alias || !url || !username) {
					writeErr('connect add requires --alias, --url, and --username');
					usage();
					return 1;
				}
				const addInput: Parameters<typeof connectAdd>[0] = {
					alias,
					url,
					username,
					environment: (flagString(flags, 'env') as SiteEnvironment | undefined) ?? 'other',
					mode: (flagString(flags, 'mode') as ConfiguredMode | undefined) ?? 'auto',
					replace: flagBool(flags, 'replace'),
					makeDefault: flagBool(flags, 'default'),
					yes: flagBool(flags, 'yes'),
				};
				if (password !== undefined) addInput.password = password;
				const client = flagString(flags, 'client');
				if (client !== undefined) addInput.client = client;
				const clientConfig = flagString(flags, 'client-config');
				if (clientConfig !== undefined) addInput.clientConfigPath = clientConfig;
				const credentialEnv = flagString(flags, 'credential-env');
				if (credentialEnv !== undefined) addInput.credentialEnv = credentialEnv;
				const profile = flagString(flags, 'profile');
				if (profile !== undefined) addInput.companionProfile = profile;
				return await connectAdd(addInput, ctx);
			}
			case 'use': {
				const alias = positionals[0] ?? flagString(flags, 'alias');
				if (!alias) {
					writeErr('connect use requires <alias>');
					return 1;
				}
				return connectUse(alias, ctx);
			}
			case 'verify': {
				const alias = positionals[0] ?? flagString(flags, 'alias');
				if (!alias) {
					writeErr('connect verify requires <alias>');
					return 1;
				}
				const client = flagString(flags, 'client');
				return await connectVerify(alias, client ? { client } : {}, ctx);
			}
			case 'repair': {
				const alias = positionals[0] ?? flagString(flags, 'alias');
				if (!alias) {
					writeErr('connect repair requires <alias>');
					return 1;
				}
				const client = flagString(flags, 'client');
				return connectRepair(alias, client ? { client } : {}, ctx);
			}
			case 'remove': {
				const alias = positionals[0] ?? flagString(flags, 'alias');
				if (!alias) {
					writeErr('connect remove requires <alias>');
					return 1;
				}
				const client = flagString(flags, 'client');
				return connectRemove(alias, client ? { client } : {}, ctx);
			}
			case 'migrate':
				return connectMigrate({ ...ctx, allowEnvRef: flagBool(flags, 'allow-env-ref') });
			default:
				writeErr(`Unknown connect command: ${command}`);
				usage();
				return 1;
		}
	} catch (err) {
		writeErr(err instanceof Error ? err.message : String(err));
		return 1;
	}
}
