import { afterEach, describe, expect, it } from 'vitest';
import { chmodSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { MemoryCredentialStore } from '../src/credentials/index.js';
import {
	buildSiteRecord,
	findDuplicateEndpoint,
	loadRegistry,
	migrateSitesFile,
	migrateV1ToV2,
	removeSite,
	saveRegistry,
	upsertSite,
	urlFingerprint,
} from '../src/cli/connect/registry.js';
import { ConnectError } from '../src/cli/connect/types.js';
import { aliasKey, mcpServerName, sanitizeAlias } from '../src/cli/connect/server-name.js';
import {
	configuredModeToEnv,
	envModeToConfigured,
	mayFallbackToDirect,
	mayProbePlugin,
	resolveActiveMode,
} from '../src/cli/connect/mode-policy.js';
import { loadSitesConfig, resolveSite } from '../src/direct/sites-config.js';

describe('multi-site registry schema v2', () => {
	const dirs: string[] = [];
	afterEach(() => {
		for (const d of dirs.splice(0)) {
			rmSync(d, { recursive: true, force: true });
		}
	});

	function tmpSites(): { dir: string; file: string; store: MemoryCredentialStore } {
		const dir = mkdtempSync(join(tmpdir(), 'sw-reg-'));
		dirs.push(dir);
		return { dir, file: join(dir, 'sites.json'), store: new MemoryCredentialStore() };
	}

	it('sanitizes aliases and builds stable mcp server names', () => {
		expect(sanitizeAlias('Site A Staging')).toBe('site-a-staging');
		expect(aliasKey('Site-A')).toBe(aliasKey('site-a'));
		const taken = new Set(['stonewright-site-a']);
		const name = mcpServerName('site-a', '01ABCDEF123456', taken);
		expect(name).toMatch(/^stonewright-site-a-/);
		expect(name).not.toBe('stonewright-site-a');
	});

	it('maps mode policy correctly', () => {
		expect(envModeToConfigured('direct')).toBe('direct-only');
		expect(envModeToConfigured('plugin')).toBe('plugin-only');
		expect(configuredModeToEnv('direct-only')).toBe('direct');
		expect(mayProbePlugin('direct-only')).toBe(false);
		expect(mayProbePlugin('auto')).toBe(true);
		expect(mayFallbackToDirect('plugin-only', 'never')).toBe(false);
		expect(mayFallbackToDirect('auto', 'direct-when-plugin-unavailable')).toBe(true);

		const direct = resolveActiveMode({
			configured: 'direct-only',
			fallbackPolicy: 'always-direct',
			probePresent: true,
		});
		expect(direct.active).toBe('direct');
		expect(direct.reason).toMatch(/probe skipped/i);

		const pluginOnly = resolveActiveMode({
			configured: 'plugin-only',
			fallbackPolicy: 'never',
			probePresent: false,
		});
		expect(pluginOnly.active).toBe('plugin');
		expect(pluginOnly.reason).toMatch(/fail closed/i);

		const autoFallback = resolveActiveMode({
			configured: 'auto',
			fallbackPolicy: 'direct-when-plugin-unavailable',
			probePresent: false,
		});
		expect(autoFallback.active).toBe('direct');
		expect(autoFallback.transition).toBe('plugin→direct');
	});

	it('adds first site and rejects same URL different alias', () => {
		const { file, store } = tmpSites();
		const ref = 'memory://stonewright/site-a-staging/app-password';
		store.set(ref, 'pass-one');

		let reg = {
			schema_version: 2 as const,
			default_site_id: null as string | null,
			sites: [] as ReturnType<typeof buildSiteRecord>[],
		};
		const site = buildSiteRecord({
			alias: 'site-a-staging',
			url: 'https://staging.example.com/',
			username: 'editor',
			credential_ref: ref,
			environment: 'staging',
		});
		reg = upsertSite(reg, site, { makeDefault: true });
		saveRegistry(reg, { sitesFile: file });

		const loaded = loadRegistry({ sitesFile: file });
		expect(loaded.registry.sites).toHaveLength(1);
		expect(loaded.registry.default_site_id).toBe(site.id);

		const clone = buildSiteRecord({
			alias: 'site-a-other',
			url: 'https://staging.example.com/',
			username: 'editor',
			credential_ref: ref,
			environment: 'staging',
		});
		expect(() => upsertSite(reg, clone)).toThrow(ConnectError);
		try {
			upsertSite(reg, clone);
		} catch (err) {
			expect(err).toBeInstanceOf(ConnectError);
			expect((err as ConnectError).code).toBe('duplicate_site');
			expect((err as ConnectError).details?.existing_alias).toBe('site-a-staging');
		}
	});

	it('allows same URL under distinct environments (prod vs staging)', () => {
		const ref = 'memory://x';
		const store = new MemoryCredentialStore();
		store.set(ref, 'example-p');
		void store;
		let reg = { schema_version: 2 as const, default_site_id: null as string | null, sites: [] as ReturnType<typeof buildSiteRecord>[] };
		const staging = buildSiteRecord({
			alias: 'site-a-staging',
			url: 'https://example.com/',
			username: 'editor',
			credential_ref: ref,
			environment: 'staging',
		});
		const prod = buildSiteRecord({
			alias: 'site-a-prod',
			url: 'https://example.com/',
			username: 'editor',
			credential_ref: ref,
			environment: 'production',
		});
		reg = upsertSite(reg, staging);
		reg = upsertSite(reg, prod);
		expect(reg.sites).toHaveLength(2);
		expect(urlFingerprint(staging.canonical_url, 'staging')).not.toBe(
			urlFingerprint(prod.canonical_url, 'production'),
		);
		expect(findDuplicateEndpoint(reg, prod.canonical_url, 'production', prod.id)).toBeUndefined();
	});

	it('rejects replace without --replace and shows clients on conflict', () => {
		const ref = 'memory://a';
		let reg = { schema_version: 2 as const, default_site_id: null as string | null, sites: [] as ReturnType<typeof buildSiteRecord>[] };
		const site = buildSiteRecord({
			alias: 'site-a',
			url: 'https://a.example/',
			username: 'u',
			credential_ref: ref,
			clients: {
				codex: { server_name: 'stonewright-site-a' },
			},
		});
		reg = upsertSite(reg, site);
		const again = buildSiteRecord({
			alias: 'Site-A',
			url: 'https://b.example/',
			username: 'u',
			credential_ref: ref,
		});
		try {
			upsertSite(reg, again);
			expect.fail('should throw');
		} catch (err) {
			expect((err as ConnectError).code).toBe('alias_exists');
			expect((err as ConnectError).details?.clients).toContain('codex');
		}
		reg = upsertSite(reg, { ...again, id: site.id, alias: 'site-a' }, { replace: true });
		expect(reg.sites).toHaveLength(1);
		expect(reg.sites[0].canonical_url).toBe('https://b.example');
	});

	it('removes one site without touching another', () => {
		const ref = 'memory://x';
		let reg = { schema_version: 2 as const, default_site_id: null as string | null, sites: [] as ReturnType<typeof buildSiteRecord>[] };
		const a = buildSiteRecord({
			alias: 'site-a',
			url: 'https://a.example/',
			username: 'u',
			credential_ref: ref,
		});
		const b = buildSiteRecord({
			alias: 'site-b',
			url: 'https://b.example/',
			username: 'u',
			credential_ref: ref,
		});
		reg = upsertSite(reg, a, { makeDefault: true });
		reg = upsertSite(reg, b);
		const { registry: next, removed } = removeSite(reg, 'site-a');
		expect(removed.alias).toBe('site-a');
		expect(next.sites.map((s) => s.alias)).toEqual(['site-b']);
		expect(next.default_site_id).toBe(b.id);
	});

	it('migrates v1 losslessly into credential store and leaves no plaintext password', () => {
		const { file, store } = tmpSites();
		writeFileSync(
			file,
			JSON.stringify({
				default: 'site-a',
				sites: {
					'site-a': {
						url: 'https://site-a.example/',
						username: 'editor',
						appPassword: 'example-legacy-app-password',
						disabledTools: ['stonewright-content-delete'],
					},
					'site-b': {
						url: 'https://site-b.example/',
						username: 'admin',
						applicationPassword: 'example-other-app-password',
					},
				},
			}),
			'utf8',
		);
		chmodSync(file, 0o600);

		const result = migrateSitesFile({
			sitesFile: file,
			credentials: { store, prefer: 'memory', allowMemoryFallback: true },
		});
		expect(result.migrated).toBe(true);
		expect(result.site_count).toBe(2);

		const raw = JSON.parse(readFileSync(file, 'utf8')) as {
			schema_version: number;
			sites: Array<{ credential_ref: string; alias: string; disabled_tools?: string[] }>;
		};
		expect(raw.schema_version).toBe(2);
		expect(JSON.stringify(raw)).not.toContain('example-legacy-app-password');
		expect(JSON.stringify(raw)).not.toContain('example-other-app-password');
		expect(raw.sites.find((s) => s.alias === 'site-a')?.disabled_tools).toEqual([
			'stonewright-content-delete',
		]);

		// Idempotent
		const again = migrateSitesFile({
			sitesFile: file,
			credentials: { store, prefer: 'memory', allowMemoryFallback: true },
		});
		expect(again.migrated).toBe(false);

		// Runtime load resolves secrets
		const config = loadSitesConfig({
			sitesFile: file,
			credentials: { store, prefer: 'memory' },
		});
		const site = resolveSite(config, 'site-a');
		expect(site.appPassword).toBe('example-legacy-app-password');
		expect(site.url).toBe('https://site-a.example');
	});

	it('stops migration and leaves original file when secure store unavailable', () => {
		const { file } = tmpSites();
		const original = {
			default: 'only',
			sites: {
				only: {
					url: 'https://only.example/',
					username: 'u',
					appPassword: 'example-must-remain',
				},
			},
		};
		writeFileSync(file, JSON.stringify(original), 'utf8');

		// Env store without allowEnvRef and without memory fallback fails set for keychain-style refs...
		// migrateV1ToV2 with prefer env and allowEnvRef false:
		expect(() =>
			migrateV1ToV2(original, {
				credentials: { prefer: 'env', allowMemoryFallback: false },
				allowEnvRef: false,
			}),
		).toThrow(/secure migration failed|secure_store_unavailable|No OS credential store/i);

		// File untouched when using migrateSitesFile with unavailable store
		// Use a store that throws on set:
		const failing = {
			kind: 'keychain' as const,
			isAvailable: () => false,
			set: () => {
				throw new Error('no keychain');
			},
			get: () => null,
			delete: () => undefined,
		};
		// createCredentialStore with allowMemoryFallback false and prefer keychain on non-available falls to env
		// which then throws secure_store_unavailable when allowEnvRef false
		expect(() =>
			migrateSitesFile({
				sitesFile: file,
				credentials: { prefer: 'env', allowMemoryFallback: false },
				allowEnvRef: false,
			}),
		).toThrow();

		const still = JSON.parse(readFileSync(file, 'utf8')) as typeof original;
		expect(still.sites.only.appPassword).toBe('example-must-remain');
		expect((still as { schema_version?: number }).schema_version).toBeUndefined();
		expect(existsSync(file)).toBe(true);
		void failing;
	});

	it('loadSitesConfig still reads legacy v1 without migration', () => {
		const { file } = tmpSites();
		writeFileSync(
			file,
			JSON.stringify({
				default: 'legacy',
				sites: {
					legacy: {
						url: 'https://legacy.example/',
						username: 'editor',
						appPassword: 'example-still-plain',
					},
				},
			}),
		);
		const config = loadSitesConfig({ sitesFile: file });
		expect(config.schemaVersion).toBe(1);
		expect(resolveSite(config).appPassword).toBe('example-still-plain');
	});
});
