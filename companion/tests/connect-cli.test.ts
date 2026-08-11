import { afterEach, describe, expect, it } from 'vitest';
import { existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { MemoryCredentialStore } from '../src/credentials/index.js';
import {
	connectAdd,
	connectList,
	connectRemove,
	connectRepair,
	connectUse,
	connectVerify,
	resolveConnectPassword,
	testCredentialOptions,
} from '../src/cli/connect/commands.js';
import { runInit } from '../src/cli/init.js';
import { codexAdapter, cursorAdapter } from '../src/cli/clients/index.js';
import { ClientConfigError } from '../src/cli/clients/types.js';
import { writeWithRollback } from '../src/cli/clients/atomic-config.js';

describe('connect CLI acceptance matrix', () => {
	const dirs: string[] = [];
	const logs: string[] = [];
	const origWrite = process.stdout.write.bind(process.stdout);
	const origErr = process.stderr.write.bind(process.stderr);

	afterEach(() => {
		process.stdout.write = origWrite;
		process.stderr.write = origErr;
		logs.length = 0;
		for (const d of dirs.splice(0)) {
			rmSync(d, { recursive: true, force: true });
		}
	});

	function capture(): void {
		const captureWrite = ((chunk: string | Uint8Array) => {
			logs.push(String(chunk));
			return true;
		}) as typeof process.stdout.write;
		process.stdout.write = captureWrite;
		process.stderr.write = captureWrite;
	}

	function harness() {
		const dir = mkdtempSync(join(tmpdir(), 'sw-connect-'));
		dirs.push(dir);
		const sitesFile = join(dir, 'sites.json');
		const homeDir = dir;
		const store = new MemoryCredentialStore();
		const credentials = testCredentialOptions(store);
		mkdirSync(join(dir, '.cursor'), { recursive: true });
		mkdirSync(join(dir, '.codex'), { recursive: true });
		return { dir, sitesFile, homeDir, store, credentials };
	}

	it('first site + first client; same site rerun no duplicate', async () => {
		const h = harness();
		capture();
		const clientConfig = join(h.dir, '.cursor', 'mcp.json');

		const code1 = await connectAdd(
			{
				alias: 'site-a',
				url: 'https://site-a.example/',
				username: 'editor',
				password: 'test-app-password-one',
				environment: 'staging',
				client: 'cursor',
				clientConfigPath: clientConfig,
				makeDefault: true,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(code1).toBe(0);

		const code2 = await connectAdd(
			{
				alias: 'site-a',
				url: 'https://site-a.example/',
				username: 'editor',
				password: 'test-app-password-one',
				environment: 'staging',
				client: 'cursor',
				clientConfigPath: clientConfig,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		// Without --replace, second add must refuse (no silent duplicate)
		expect(code2).toBe(1);

		const code3 = await connectAdd(
			{
				alias: 'site-a',
				url: 'https://site-a.example/',
				username: 'editor',
				password: 'test-app-password-one',
				environment: 'staging',
				client: 'cursor',
				clientConfigPath: clientConfig,
				replace: true,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(code3).toBe(0);

		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: unknown[];
		};
		expect(reg.sites).toHaveLength(1);

		const mcp = JSON.parse(readFileSync(clientConfig, 'utf8')) as {
			mcpServers: Record<string, unknown>;
		};
		const names = Object.keys(mcp.mcpServers);
		expect(names).toHaveLength(1);
		expect(names[0]).toMatch(/^stonewright-site-a/);
		// No password in client config
		expect(JSON.stringify(mcp)).not.toContain('test-app-password');
	});

	it('same URL different alias rejected; prod/staging distinct aliases ok', async () => {
		const h = harness();
		capture();
		const code1 = await connectAdd(
			{
				alias: 'shop-staging',
				url: 'https://shop.example/',
				username: 'editor',
				password: 'example-pw',
				environment: 'staging',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(code1).toBe(0);

		const codeDup = await connectAdd(
			{
				alias: 'shop-clone',
				url: 'https://shop.example/',
				username: 'editor',
				password: 'example-pw',
				environment: 'staging',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(codeDup).toBe(1);
		expect(logs.join('')).toMatch(/duplicate_site/);

		const codeProd = await connectAdd(
			{
				alias: 'shop-prod',
				url: 'https://shop.example/',
				username: 'editor',
				password: 'example-pw',
				environment: 'production',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(codeProd).toBe(0);

		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as { sites: unknown[] };
		expect(reg.sites).toHaveLength(2);
	});

	it('two clients one site; two sites one client', async () => {
		const h = harness();
		capture();
		const cursorCfg = join(h.dir, '.cursor', 'mcp.json');
		const codexCfg = join(h.dir, '.codex', 'config.toml');

		await connectAdd(
			{
				alias: 'site-a',
				url: 'https://a.example/',
				username: 'u',
				password: 'example-p1',
				client: 'cursor',
				clientConfigPath: cursorCfg,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		// second client on same site via repair (does not remove the first binding)
		const repairCode = connectRepair(
			'site-a',
			{ client: 'codex' },
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				clientConfigPath: codexCfg,
			},
		);
		expect(repairCode).toBe(0);

		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ clients: Record<string, unknown> }>;
		};
		expect(Object.keys(reg.sites[0].clients).sort()).toEqual(['codex', 'cursor']);

		// two sites one client (cursor)
		const cursorCfg2 = join(h.dir, 'cursor-b.json');
		await connectAdd(
			{
				alias: 'site-b',
				url: 'https://b.example/',
				username: 'u',
				password: 'example-p2',
				client: 'cursor',
				clientConfigPath: cursorCfg2,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);

		const cursorA = JSON.parse(readFileSync(cursorCfg, 'utf8')) as {
			mcpServers: Record<string, unknown>;
		};
		const cursorB = JSON.parse(readFileSync(cursorCfg2, 'utf8')) as {
			mcpServers: Record<string, unknown>;
		};
		expect(Object.keys(cursorA.mcpServers).some((k) => k.includes('site-a'))).toBe(true);
		expect(Object.keys(cursorB.mcpServers).some((k) => k.includes('site-b'))).toBe(true);

		const codexText = readFileSync(codexCfg, 'utf8');
		expect(codexText).toMatch(/\[mcp_servers\.stonewright-site-a/);
	});

	it('remove one site without touching another', async () => {
		const h = harness();
		capture();
		await connectAdd(
			{
				alias: 'keep-me',
				url: 'https://keep.example/',
				username: 'u',
				password: 'example-p',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		await connectAdd(
			{
				alias: 'drop-me',
				url: 'https://drop.example/',
				username: 'u',
				password: 'example-p',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		const code = connectRemove('drop-me', {}, { sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials });
		expect(code).toBe(0);
		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ alias: string }>;
		};
		expect(reg.sites.map((s) => s.alias)).toEqual(['keep-me']);
	});

	it('direct-only never probes plugin; plugin-only never falls back', async () => {
		const h = harness();
		capture();
		let probed = false;
		const fetchImpl = ((input: string | URL) => {
			const url = String(input);
			if (url.includes('/mcp/stonewright')) {
				probed = true;
			}
			return Promise.resolve(
				new Response(JSON.stringify({ namespaces: ['wp/v2'] }), { status: 200 }),
			);
		}) as typeof fetch;

		await connectAdd(
			{
				alias: 'direct-site',
				url: 'https://direct.example/',
				username: 'u',
				password: 'example-p',
				mode: 'direct-only',
			},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
			},
		);
		probed = false;
		const v1 = await connectVerify(
			'direct-site',
			{},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
				fetchImpl,
			},
		);
		expect(v1).toBe(0);
		expect(probed).toBe(false);
		expect(logs.join('')).toMatch(/direct-only never probes|may_probe_plugin=false/);

		await connectAdd(
			{
				alias: 'plugin-site',
				url: 'https://plugin.example/',
				username: 'u',
				password: 'example-p',
				mode: 'plugin-only',
			},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
			},
		);

		const fetch404 = (() =>
			Promise.resolve(new Response('missing', { status: 404 }))) as typeof fetch;
		const v2 = await connectVerify(
			'plugin-site',
			{},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
				fetchImpl: fetch404,
			},
		);
		// plugin-only with 404 fails closed
		expect(v2).toBe(1);
		expect(logs.join('')).toMatch(/plugin_probe|fail closed|plugin-only/i);
	});

	it('init refuses silent default overwrite', async () => {
		const h = harness();
		capture();
		await connectAdd(
			{
				alias: 'default',
				url: 'https://first.example/',
				username: 'u',
				password: 'example-p',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);

		process.env.STONEWRIGHT_CREDENTIAL_STORE = 'memory';
		const code = await runInit([
			'--url',
			'https://second.example/',
			'--username',
			'u',
			'--password',
			'example-p2',
			'--sites-file',
			h.sitesFile,
			'--skip-auth',
		]);
		delete process.env.STONEWRIGHT_CREDENTIAL_STORE;
		expect(code).toBe(1);
		expect(logs.join('')).toMatch(/Refusing silent overwrite|--replace/i);

		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ canonical_url: string }>;
		};
		expect(reg.sites[0].canonical_url).toBe('https://first.example');
	});

	it('connect use + list', async () => {
		const h = harness();
		capture();
		await connectAdd(
			{
				alias: 'a',
				url: 'https://a.example/',
				username: 'u',
				password: 'example-p',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		await connectAdd(
			{
				alias: 'b',
				url: 'https://b.example/',
				username: 'u',
				password: 'example-p',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(connectUse('b', { sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials })).toBe(0);
		logs.length = 0;
		expect(connectList({ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials })).toBe(0);
		const out = logs.join('');
		expect(out).toMatch(/"is_default": true/);
		expect(out).toMatch(/"alias": "b"/);
	});

	it('config parse failure rolls back', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-cfg-'));
		dirs.push(dir);
		const path = join(dir, 'mcp.json');
		writeFileSync(path, '{\n  "mcpServers": {}\n}\n', 'utf8');
		const adapter = cursorAdapter();
		// Force validate failure by writing invalid then...
		expect(() =>
			writeWithRollback({
				path,
				nextContents: '{not-json',
				validate: (p) => {
					JSON.parse(readFileSync(p, 'utf8'));
				},
			}),
		).toThrow(ClientConfigError);
		// Original restored
		expect(JSON.parse(readFileSync(path, 'utf8'))).toEqual({ mcpServers: {} });
		void adapter;
	});

	it('codex adapter upsert is idempotent and format-preserving for other keys', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-toml-'));
		dirs.push(dir);
		const path = join(dir, 'config.toml');
		writeFileSync(
			path,
			`# keep this comment\nmodel = "gpt-5"\n\n[mcp_servers.other]\ncommand = "echo"\nargs = ["hi"]\n`,
			'utf8',
		);
		const adapter = codexAdapter();
		adapter.upsert(path, {
			serverName: 'stonewright-site-a',
			command: 'npx',
			args: ['-y', 'stonewright-mcp'],
			env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_SITE_ALIAS: 'site-a' },
		});
		adapter.upsert(path, {
			serverName: 'stonewright-site-a',
			command: 'npx',
			args: ['-y', 'stonewright-mcp'],
			env: { STONEWRIGHT_MODE: 'auto', STONEWRIGHT_SITE_ALIAS: 'site-a' },
		});
		const text = readFileSync(path, 'utf8');
		expect(text).toContain('# keep this comment');
		expect(text).toContain('model = "gpt-5"');
		expect(text).toContain('[mcp_servers.other]');
		expect(text.match(/\[mcp_servers\.stonewright-site-a\]/g)?.length).toBe(1);
		expect(existsSync(path)).toBe(true);
	});

	it('resolveConnectPassword prefers password-env and prompt over missing argv', async () => {
		const fromEnv = await resolveConnectPassword(
			{ passwordEnv: 'SW_TEST_PASS' },
			{ SW_TEST_PASS: 'example-from-env' },
		);
		expect(fromEnv).toEqual({ password: 'example-from-env', source: 'password-env' });

		const fromPrompt = await resolveConnectPassword({
			promptPassword: () => Promise.resolve('example-from-prompt'),
		});
		expect(fromPrompt).toEqual({ password: 'example-from-prompt', source: 'prompt' });

		const fromArgv = await resolveConnectPassword({ password: 'example-from-argv' });
		expect(fromArgv).toEqual({ password: 'example-from-argv', source: 'argv' });
	});

	it('connect add accepts --password-env style input without argv password', async () => {
		const h = harness();
		capture();
		const code = await connectAdd(
			{
				alias: 'env-pass-site',
				url: 'https://env-pass.example/',
				username: 'editor',
				passwordEnv: 'SW_CONNECT_TEST_PW',
			},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
				env: { SW_CONNECT_TEST_PW: 'example-password-from-env' },
			},
		);
		expect(code).toBe(0);
		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ alias: string; credential_ref: string }>;
		};
		expect(reg.sites[0]?.alias).toBe('env-pass-site');
		expect(reg.sites[0]?.credential_ref).toMatch(/memory:\/\//);
		expect(JSON.stringify(reg)).not.toContain('example-password-from-env');
	});

	it('persists Step 1 expectations and one-time browser consent per site and client', async () => {
		const h = harness();
		capture();
		const clientConfig = join(h.dir, '.cursor', 'mcp.json');
		const code = await connectAdd(
			{
				alias: 'profiled-site',
				url: 'https://profiled.example/',
				username: 'editor',
				password: 'example-password',
				mode: 'plugin-only',
				client: 'cursor',
				clientConfigPath: clientConfig,
				pluginEnabled: true,
				wordpressMode: 'production-safe',
				wordpressToolSurface: 'full',
				elementorV4Atomic: true,
				browserProvider: 'recommended',
				browserScanConsent: 'granted',
				browserInstallConsent: 'denied',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(code).toBe(0);
		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{
				plugin_expectations: Record<string, unknown>;
				clients: Record<string, { browser: Record<string, unknown> }>;
			}>;
		};
		expect(reg.sites[0]?.plugin_expectations).toEqual(expect.objectContaining({
			enabled_requested: true,
			wordpress_mode: 'production-safe',
			wordpress_tool_surface: 'full',
			elementor_v4_atomic: true,
		}));
		expect(reg.sites[0]?.clients.cursor?.browser).toEqual({
			provider: 'recommended',
			scan_consent: 'granted',
			install_consent: 'denied',
		});

		const replaced = await connectAdd(
			{
				alias: 'profiled-site',
				url: 'https://profiled.example/',
				username: 'editor',
				password: 'example-replacement-value',
				mode: 'plugin-only',
				client: 'cursor',
				clientConfigPath: clientConfig,
				replace: true,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(replaced).toBe(0);
		const after = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as typeof reg;
		expect(after.sites[0]?.plugin_expectations).toEqual(reg.sites[0]?.plugin_expectations);
		expect(after.sites[0]?.clients.cursor?.browser).toEqual(reg.sites[0]?.clients.cursor?.browser);
	});

	it('rolls back client config and newly stored credential when registry persistence fails', async () => {
		const h = harness();
		capture();
		const clientConfig = join(h.dir, '.cursor', 'mcp.json');
		writeFileSync(clientConfig, '{\n  "mcpServers": {}\n}\n', 'utf8');
		const original = readFileSync(clientConfig, 'utf8');
		const code = await connectAdd(
			{
				alias: 'rollback-site',
				url: 'https://rollback.example/',
				username: 'editor',
				password: 'example-password',
				client: 'cursor',
				clientConfigPath: clientConfig,
			},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
				saveRegistryImpl: () => { throw new Error('synthetic registry failure'); },
			},
		);
		expect(code).toBe(1);
		expect(readFileSync(clientConfig, 'utf8')).toBe(original);
		expect(h.store.get('memory://stonewright/rollback-site/app-password')).toBeNull();
		expect(existsSync(h.sitesFile)).toBe(false);
	});

	it('does not leave a credential when client binding is unsupported', async () => {
		const h = harness();
		capture();
		const code = await connectAdd(
			{
				alias: 'unsupported-client-site',
				url: 'https://unsupported.example/',
				username: 'editor',
				password: 'example-password',
				client: 'not-a-client',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		expect(code).toBe(1);
		expect(h.store.get('memory://stonewright/unsupported-client-site/app-password')).toBeNull();
		expect(existsSync(h.sitesFile)).toBe(false);
	});

	it('restores the previous credential when replacement persistence fails', async () => {
		const h = harness();
		capture();
		await connectAdd(
			{
				alias: 'replace-rollback',
				url: 'https://replace-rollback.example/',
				username: 'editor',
				password: 'example-old-value',
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		const code = await connectAdd(
			{
				alias: 'replace-rollback',
				url: 'https://replace-rollback.example/',
				username: 'editor',
				password: 'example-new-value',
				replace: true,
			},
			{
				sitesFile: h.sitesFile,
				homeDir: h.homeDir,
				credentials: h.credentials,
				skipAuth: true,
				saveRegistryImpl: () => { throw new Error('synthetic registry failure'); },
			},
		);
		expect(code).toBe(1);
		expect(h.store.get('memory://stonewright/replace-rollback/app-password')).toBe('example-old-value');
	});

	it('restores client config when remove cannot persist the registry', async () => {
		const h = harness();
		capture();
		const clientConfig = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd(
			{
				alias: 'remove-rollback',
				url: 'https://remove-rollback.example/',
				username: 'editor',
				password: 'example-password',
				client: 'cursor',
				clientConfigPath: clientConfig,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		const original = readFileSync(clientConfig, 'utf8');
		const code = connectRemove('remove-rollback', {}, {
			sitesFile: h.sitesFile,
			homeDir: h.homeDir,
			credentials: h.credentials,
			saveRegistryImpl: () => { throw new Error('synthetic registry failure'); },
		});
		expect(code).toBe(1);
		expect(readFileSync(clientConfig, 'utf8')).toBe(original);
	});

	it('stores live runtime proof without claiming a structural config check is runtime proof', async () => {
		const h = harness();
		capture();
		const clientConfig = join(h.dir, '.cursor', 'mcp.json');
		await connectAdd(
			{
				alias: 'verified-site',
				url: 'https://verified.example/',
				username: 'editor',
				password: 'example-password',
				mode: 'plugin-only',
				client: 'cursor',
				clientConfigPath: clientConfig,
			},
			{ sitesFile: h.sitesFile, homeDir: h.homeDir, credentials: h.credentials, skipAuth: true },
		);
		const code = await connectVerify('verified-site', { client: 'cursor' }, {
			sitesFile: h.sitesFile,
			homeDir: h.homeDir,
			credentials: h.credentials,
			skipAuth: true,
			fetchImpl: (() => Promise.resolve(new Response('{}', { status: 200 }))) as typeof fetch,
			runtimeVerifier: (site, _password, entry) => {
				expect(entry?.env.STONEWRIGHT_SITE_ALIAS).toBe('verified-site');
				return Promise.resolve({
					ok: true,
					detail: 'spawned runtime verified',
					companion_version: '1.2.3',
					active_alias: site.alias,
					remote_tool_names: ['stonewright-task-start', 'stonewright-wordpress-mcp-status'],
					task_start_available: true,
					status_available: true,
				});
			},
		});
		expect(code).toBe(0);
		const reg = JSON.parse(readFileSync(h.sitesFile, 'utf8')) as {
			sites: Array<{ last_verification: Record<string, unknown> }>;
		};
		expect(reg.sites[0]?.last_verification).toEqual(expect.objectContaining({
			ok: true,
			active_alias: 'verified-site',
			companion_version: '1.2.3',
			remote_tool_count: 2,
			task_start_available: true,
			status_available: true,
		}));
		expect(String(reg.sites[0]?.last_verification.surface_digest)).toMatch(/^sha256:/);
	});
});
