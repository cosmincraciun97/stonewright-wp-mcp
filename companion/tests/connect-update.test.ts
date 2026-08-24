import { afterEach, describe, expect, it } from 'vitest';
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { codexAdapter, cursorAdapter } from '../src/cli/clients/index.js';
import { MemoryCredentialStore } from '../src/credentials/index.js';
import {
	connectAdd,
	connectUpdate,
	connectVerify,
	testCredentialOptions,
} from '../src/cli/connect/commands.js';
import { runConnect } from '../src/cli/connect/index.js';

const OLD_PACKAGE = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.11.1/stonewright-companion-1.0.0-beta.11.1.tgz';
const NEW_PACKAGE = 'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.12/stonewright-companion-1.0.0-beta.12.tgz';

describe('connect update', () => {
	const dirs: string[] = [];
	const logs: string[] = [];
	const originalOut = process.stdout.write.bind(process.stdout);
	const originalErr = process.stderr.write.bind(process.stderr);

	afterEach(() => {
		process.stdout.write = originalOut;
		process.stderr.write = originalErr;
		logs.length = 0;
		for (const dir of dirs.splice(0)) rmSync(dir, { recursive: true, force: true });
	});

	function capture(): void {
		const write = ((chunk: string | Uint8Array) => {
			logs.push(String(chunk));
			return true;
		}) as typeof process.stdout.write;
		process.stdout.write = write;
		process.stderr.write = write;
	}

	function harness() {
		const dir = mkdtempSync(join(tmpdir(), 'sw-connect-update-'));
		dirs.push(dir);
		mkdirSync(join(dir, '.codex'), { recursive: true });
		mkdirSync(join(dir, '.cursor'), { recursive: true });
		const store = new MemoryCredentialStore();
		return {
			dir,
			sitesFile: join(dir, 'sites.json'),
			credentials: testCredentialOptions(store),
		};
	}

	it('replaces exactly one Codex package token and preserves every surrounding byte', () => {
		const h = harness();
		const path = join(h.dir, '.codex', 'config.toml');
		const before = `# private config stays byte-identical\nmodel = "gpt-5"\n\n[mcp_servers.stonewright-site-a]\ncommand = "npx" # keep inline\nargs = [ "-y", "--package", "${OLD_PACKAGE}", "stonewright-mcp" ] # keep spacing\nunknown = "keep"\n\n[mcp_servers.stonewright-site-a.env]\nPRIVATE_TOKEN = "do-not-touch"\nSTONEWRIGHT_SITE_ALIAS = "site-a"\n\n[mcp_servers.other]\ncommand = "echo"\nargs = ["${OLD_PACKAGE}"]\n`;
		writeFileSync(path, before, 'utf8');

		const result = codexAdapter().updatePackageReference(path, 'stonewright-site-a', NEW_PACKAGE);
		const after = readFileSync(path, 'utf8');

		expect(result.previousPackageSpec).toBe(OLD_PACKAGE);
		expect(result.packageSpec).toBe(NEW_PACKAGE);
		expect(result.beforeSha256).toMatch(/^sha256:/);
		expect(result.afterSha256).toMatch(/^sha256:/);
		expect(after).toBe(before.replace(OLD_PACKAGE, NEW_PACKAGE));
		expect(result.diff).not.toContain('PRIVATE_TOKEN');
		expect(codexAdapter().read(path, 'stonewright-site-a')?.args).toContain(NEW_PACKAGE);
	});

	it('replaces exactly one JSONC package string and preserves comments, whitespace, order, and credentials', () => {
		const h = harness();
		const path = join(h.dir, '.cursor', 'mcp.json');
		const before = `{
	// keep this comment
	"unrelated": { "note": "untouched-value" },
	"mcpServers": {
		"stonewright-site-a": {
			"command": "npx",
			"args": ["-y", "--package", "${OLD_PACKAGE}", "stonewright-mcp"],
			"unknown": true
		},
		"other": { "command": "echo", "args": ["${OLD_PACKAGE}"] }
	}
}
`;
		writeFileSync(path, before, 'utf8');

		const result = cursorAdapter().updatePackageReference(path, 'stonewright-site-a', NEW_PACKAGE);
		const after = readFileSync(path, 'utf8');

		expect(after).toBe(before.replace(OLD_PACKAGE, NEW_PACKAGE));
		expect(result.previousPackageSpec).toBe(OLD_PACKAGE);
		expect(result.diff).not.toContain('untouched-value');
		expect(cursorAdapter().read(path, 'stonewright-site-a')?.args).toContain(NEW_PACKAGE);
	});

	it('fails closed for zero, multiple, and duplicate ambiguous package references', () => {
		const h = harness();
		const adapter = codexAdapter();
		const path = join(h.dir, '.codex', 'config.toml');

		writeFileSync(path, '[mcp_servers.site-a]\ncommand = "npx"\nargs = ["-y", "other-package"]\n', 'utf8');
		expect(() => adapter.updatePackageReference(path, 'site-a', NEW_PACKAGE)).toThrowError(/package_reference_not_found/);

		writeFileSync(path, `[mcp_servers.site-a]\ncommand = "npx"\nargs = ["${OLD_PACKAGE}", "@stonewright/companion@1.0.0-beta.11.1"]\n`, 'utf8');
		expect(() => adapter.updatePackageReference(path, 'site-a', NEW_PACKAGE)).toThrowError(/package_reference_multiple/);

		writeFileSync(path, `[mcp_servers.site-a]\ncommand = "npx"\nargs = ["${OLD_PACKAGE}"]\n\n[mcp_servers.site-a]\ncommand = "npx"\nargs = ["${OLD_PACKAGE}"]\n`, 'utf8');
		expect(() => adapter.updatePackageReference(path, 'site-a', NEW_PACKAGE)).toThrowError(/server_entry_ambiguous/);
	});

	it('rejects non-exact or unofficial target package references', () => {
		const h = harness();
		const path = join(h.dir, '.codex', 'config.toml');
		writeFileSync(path, `[mcp_servers.site-a]\ncommand = "npx"\nargs = ["${OLD_PACKAGE}"]\n`, 'utf8');

		for (const invalid of [
			'@stonewright/companion@latest',
			'@stonewright/companion@1.0.0-beta.12?download=1',
			'https://evil.example/releases/download/v1.0.0-beta.12/stonewright-companion-1.0.0-beta.12.tgz',
			'https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-beta.12/stonewright-companion-1.0.0-beta.11.1.tgz',
			`${NEW_PACKAGE}?download=1`,
			`${NEW_PACKAGE}#archive`,
		]) {
			expect(() => codexAdapter().updatePackageReference(path, 'site-a', invalid)).toThrowError(/package_reference_invalid/);
		}

		expect(() => codexAdapter().updatePackageReference(path, 'site-a', '@stonewright/companion@1.0.0-beta.12')).not.toThrow();
	});

	it('never crosses a TOML server header with an inline comment', () => {
		const h = harness();
		const path = join(h.dir, '.codex', 'config.toml');
		const otherPackage = '@stonewright/companion@1.0.0-beta.10';
		const before = `[mcp_servers.site-a]\ncommand = "npx"\n\n[mcp_servers.other] # keep inline header comment\ncommand = "npx"\nargs = ["${otherPackage}"]\n`;
		writeFileSync(path, before, 'utf8');

		expect(() => codexAdapter().updatePackageReference(path, 'site-a', NEW_PACKAGE)).toThrowError(/package_reference_not_found/);
		expect(readFileSync(path, 'utf8')).toBe(before);
	});

	it('edits only the real TOML args array and ignores fake keys in comments and strings', () => {
		const h = harness();
		const path = join(h.dir, '.codex', 'config.toml');
		const before = `[mcp_servers.site-a]\ncommand = "npx"\nmessage = "args = [\\"${OLD_PACKAGE}\\"]"\nunicode = "rocket: \\U0001F680"\nliteral = 'args = ["${OLD_PACKAGE}"]'\nmultiline = """\nargs = ["${OLD_PACKAGE}"]\n[mcp_servers.fake]\n"""\nliteral_multiline = '''\nargs = ["${OLD_PACKAGE}"]\n'''\n# args = ["${OLD_PACKAGE}"]\nargs = [\n  "-y",\n  "--package",\n  "${OLD_PACKAGE}", # only this executable token changes\n  "stonewright-mcp",\n]\n\n[mcp_servers.other]\ncommand = "echo"\nargs = ["${OLD_PACKAGE}"]\n`;
		writeFileSync(path, before, 'utf8');

		const result = codexAdapter().updatePackageReference(path, 'site-a', NEW_PACKAGE);
		const after = readFileSync(path, 'utf8');
		const realTokenOffset = before.lastIndexOf(`  "${OLD_PACKAGE}", # only this executable token changes`);
		const expected = `${before.slice(0, realTokenOffset)}${before.slice(realTokenOffset).replace(OLD_PACKAGE, NEW_PACKAGE)}`;

		expect(result.previousPackageSpec).toBe(OLD_PACKAGE);
		expect(after).toBe(expected);
		expect(codexAdapter().read(path, 'site-a')?.args).toContain(NEW_PACKAGE);
		expect(codexAdapter().read(path, 'other')?.args).toContain(OLD_PACKAGE);
	});

	it('redacts invalid JSONC literals from connect update errors', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });
		const secretLiteral = 'PRIVATE_SECRET_SHOULD_NEVER_APPEAR';
		const valid = readFileSync(configPath, 'utf8');
		writeFileSync(configPath, valid.replace(/\n}\s*$/, `,\n  "broken": ${secretLiteral}\n}\n`), 'utf8');
		logs.length = 0;

		const code = await runConnect([
			'update', 'site-a', '--client', 'cursor', '--to', NEW_PACKAGE, '--sites-file', h.sitesFile,
		]);
		const output = logs.join('');

		expect(code).toBe(1);
		expect(output).toContain('config_parse_failure');
		expect(output).toMatch(/line\s+\d+.*column\s+\d+/i);
		expect(output).not.toContain(secretLiteral);
	});

	it('persists a pending restart receipt only after verified config write', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a',
			url: 'https://site-a.example',
			username: 'editor',
			password: 'example-password',
			client: 'cursor',
			clientConfigPath: configPath,
		}, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
			skipAuth: true,
			packageSpec: OLD_PACKAGE,
		});

		const registryBefore = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ last_verification?: Record<string, unknown> }>;
		};
		registryBefore.sites[0].last_verification = {
			at: '2026-08-24T00:00:00.000Z',
			ok: true,
			attestation_scope: 'spawned-runtime',
			process_start_id: 'process-old',
			catalog_digest: 'sha256:old-catalog',
		};
		writeFileSync(h.sitesFile, `${JSON.stringify(registryBefore, null, 2)}\n`, 'utf8');

		const code = connectUpdate('site-a', { client: 'cursor', to: NEW_PACKAGE }, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
		});
		expect(code).toBe(0);

		const registry = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: Record<string, unknown> }> }>;
		};
		expect(registry.sites[0].clients.cursor.pending_restart).toEqual(expect.objectContaining({
			expected_package: NEW_PACKAGE,
			expected_version: '1.0.0-beta.12',
			pre_restart_process_start_id: null,
			pre_restart_catalog_digest: null,
			status: 'restart-required',
		}));
		const challenge = registry.sites[0].clients.cursor.pending_restart?.attestation_challenge;
		expect(challenge).toMatch(/^[A-Za-z0-9_-]{40,}$/);
		expect(logs.join('')).toContain('restart-required');
		expect(logs.join('')).not.toContain('example-password');
		expect(logs.join('')).not.toContain(String(challenge));
	});

	it('exposes the dedicated connect update CLI syntax', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });

		const code = await runConnect([
			'update', 'site-a', '--client', 'cursor', '--to', NEW_PACKAGE, '--sites-file', h.sitesFile,
		]);

		expect(code).toBe(0);
		expect(readFileSync(configPath, 'utf8')).toContain(NEW_PACKAGE);
		expect(logs.join('')).toContain('restart-required');
	});

	it('rolls the config back and leaves the registry unchanged when registry persistence fails', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });
		const configBefore = readFileSync(configPath, 'utf8');
		const registryBefore = readFileSync(h.sitesFile, 'utf8');

		const code = connectUpdate('site-a', { client: 'cursor', to: NEW_PACKAGE }, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
			saveRegistryImpl: () => { throw new Error('synthetic registry failure'); },
		});

		expect(code).toBe(1);
		expect(readFileSync(configPath, 'utf8')).toBe(configBefore);
		expect(readFileSync(h.sitesFile, 'utf8')).toBe(registryBefore);
	});

	it('never accepts an injected CLI runtime assertion as active-client restart proof', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });
		const registry = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as { sites: Array<Record<string, unknown>> };
		registry.sites[0].last_verification = { at: '2026-08-24T00:00:00.000Z', ok: true, process_start_id: 'process-old' };
		writeFileSync(h.sitesFile, `${JSON.stringify(registry, null, 2)}\n`, 'utf8');
		expect(connectUpdate('site-a', { client: 'cursor', to: NEW_PACKAGE }, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials })).toBe(0);

		const verify = await connectVerify('site-a', { client: 'cursor' }, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
			skipAuth: true,
			runtimeVerifier: () => Promise.resolve({
				ok: true,
				detail: 'official client attestation',
				attestation_scope: 'active-client',
				companion_version: '1.0.0-beta.12',
				process_start_id: 'process-new',
				catalog_digest: 'sha256:new-catalog',
				client_observed_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				remote_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				task_start_available: true,
				setup_profile_available: true,
				status_available: true,
				refresh_required_tool_names: [],
			}),
		});
		expect(verify).toBe(1);

		const after = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown; last_restart_proof?: Record<string, unknown> }> }>;
		};
		expect(after.sites[0].clients.cursor.pending_restart).toBeDefined();
		expect(after.sites[0].clients.cursor.last_restart_proof).toBeUndefined();
		expect(logs.join('')).toContain('restart_active_client_attestation_required');
	});

	it('spawned runtime verification leaves the actual host restart pending', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });
		expect(connectUpdate('site-a', { client: 'cursor', to: NEW_PACKAGE }, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials })).toBe(0);

		const verify = await connectVerify('site-a', { client: 'cursor' }, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
			skipAuth: true,
			runtimeVerifier: () => Promise.resolve({
				ok: true,
				detail: 'spawned package runtime verified',
				attestation_scope: 'spawned-runtime',
				companion_version: '1.0.0-beta.12',
				process_start_id: 'spawned-process',
				catalog_digest: 'sha256:spawned-catalog',
				client_observed_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				remote_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				task_start_available: true,
				setup_profile_available: true,
				status_available: true,
				refresh_required_tool_names: [],
			}),
		});

		expect(verify).toBe(1);
		const after = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown; last_restart_proof?: unknown }> }>;
		};
		expect(after.sites[0].clients.cursor.pending_restart).toBeDefined();
		expect(after.sites[0].clients.cursor.last_restart_proof).toBeUndefined();
		expect(logs.join('')).toContain('restart_active_client_attestation_required');
		expect(logs.join('')).toContain('call task-start, setup-profile, status, and client-surface-check inside that active MCP host');
	});
});
