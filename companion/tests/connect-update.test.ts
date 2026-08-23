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

const OLD_PACKAGE = 'https://github.com/example/stonewright-companion-1.0.0-beta.11.1.tgz';
const NEW_PACKAGE = 'https://github.com/example/stonewright-companion-1.0.0-beta.12.tgz';

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
	"unrelated": { "secret": "do-not-touch" },
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
		expect(result.diff).not.toContain('do-not-touch');
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
			pre_restart_process_start_id: 'process-old',
			pre_restart_catalog_digest: 'sha256:old-catalog',
			status: 'restart-required',
		}));
		expect(logs.join('')).toContain('restart-required');
		expect(logs.join('')).not.toContain('example-password');
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

	it('completes restart proof only for a new process, exact version, catalog digest, and observed tools', async () => {
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
		expect(verify).toBe(0);

		const after = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, { pending_restart?: unknown; last_restart_proof?: Record<string, unknown> }> }>;
		};
		expect(after.sites[0].clients.cursor.pending_restart).toBeUndefined();
		expect(after.sites[0].clients.cursor.last_restart_proof).toEqual(expect.objectContaining({
			status: 'verified',
			process_start_id: 'process-new',
			catalog_digest: 'sha256:new-catalog',
			expected_version: '1.0.0-beta.12',
			observed_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
		}));
	});

	it('rejects stale-process restart proof and leaves the pending receipt intact', async () => {
		const h = harness();
		capture();
		const configPath = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd({
			alias: 'site-a', url: 'https://site-a.example', username: 'editor', password: 'example-password',
			client: 'cursor', clientConfigPath: configPath,
		}, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials, skipAuth: true, packageSpec: OLD_PACKAGE });
		const registry = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as { sites: Array<Record<string, unknown>> };
		registry.sites[0].last_verification = { at: '2026-08-24T00:00:00.000Z', ok: true, process_start_id: 'same-process' };
		writeFileSync(h.sitesFile, `${JSON.stringify(registry, null, 2)}\n`, 'utf8');
		connectUpdate('site-a', { client: 'cursor', to: NEW_PACKAGE }, { sitesFile: h.sitesFile, homeDir: h.dir, credentials: h.credentials });

		const verify = await connectVerify('site-a', { client: 'cursor' }, {
			sitesFile: h.sitesFile,
			homeDir: h.dir,
			credentials: h.credentials,
			skipAuth: true,
			runtimeVerifier: () => Promise.resolve({
				ok: true,
				detail: 'stale host',
				companion_version: '1.0.0-beta.12',
				process_start_id: 'same-process',
				catalog_digest: 'sha256:new-catalog',
				client_observed_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				remote_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				task_start_available: true,
				status_available: true,
				refresh_required_tool_names: [],
			}),
		});
		expect(verify).toBe(1);
		expect(logs.join('')).toContain('restart_process_stale');
	});

	it('rejects restart proof when setup-profile was not visible and called', async () => {
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
				detail: 'setup-profile missing',
				companion_version: '1.0.0-beta.12',
				process_start_id: 'process-new',
				catalog_digest: 'sha256:new-catalog',
				client_observed_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				remote_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
				task_start_available: true,
				setup_profile_available: false,
				status_available: true,
				refresh_required_tool_names: [],
			}),
		});

		expect(verify).toBe(1);
		expect(logs.join('')).toContain('restart_setup_profile_missing');
	});
});
