import { afterEach, describe, expect, it } from 'vitest';
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { MemoryCredentialStore } from '../src/credentials/index.js';
import {
	loadSitesConfig,
	resolveSite,
} from '../src/direct/sites-config.js';

describe('direct sites-config', () => {
	const dirs: string[] = [];

	afterEach(() => {
		for (const dir of dirs.splice(0)) {
			rmSync(dir, { recursive: true, force: true });
		}
	});

	function writeSites(payload: unknown, mode = 0o600): string {
		const dir = mkdtempSync(join(tmpdir(), 'sw-sites-'));
		dirs.push(dir);
		const file = join(dir, 'sites.json');
		writeFileSync(file, JSON.stringify(payload), 'utf8');
		chmodSync(file, mode);
		return file;
	}

	it('parses a valid multi-site file and resolves aliases', () => {
		const file = writeSites({
			default: 'site-a',
			sites: {
				'site-a': {
					url: 'https://site-a.example',
					username: 'editor',
					appPassword: 'xxxx xxxx xxxx xxxx xxxx xxxx',
				},
				'site-b': {
					url: 'https://site-b.example',
					username: 'editor',
					appPassword: 'test test test test test test',
				},
			},
		});

		const config = loadSitesConfig({ sitesFile: file });
		expect(config.default).toBe('site-a');
		expect(Object.keys(config.sites)).toEqual(['site-a', 'site-b']);

		const site = resolveSite(config, 'site-b');
		expect(site.alias).toBe('site-b');
		expect(site.url).toBe('https://site-b.example');
		expect(site.restBase).toBe('https://site-b.example/wp-json');
		expect(site.username).toBe('editor');
	});

	it('rejects invalid JSON with a path-aware error', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-sites-'));
		dirs.push(dir);
		const file = join(dir, 'sites.json');
		writeFileSync(file, '{not-json', 'utf8');
		chmodSync(file, 0o600);

		expect(() => loadSitesConfig({ sitesFile: file })).toThrow(/sites\.json|JSON|parse/i);
	});

	it('accepts the legacy applicationPassword key written by older init versions', () => {
		const file = writeSites({
			default: 'legacy',
			sites: {
				legacy: {
					url: 'https://legacy.example',
					username: 'editor',
					applicationPassword: 'legacy local credential',
				},
			},
		});

		const site = resolveSite(loadSitesConfig({ sitesFile: file }));
		expect(site.appPassword).toBe('legacy local credential');
	});

	it('rejects non-http(s) URLs', () => {
		const file = writeSites({
			default: 'bad',
			sites: {
				bad: {
					url: 'ftp://example.com',
					username: 'u',
					appPassword: 'p',
				},
			},
		});
		expect(() => loadSitesConfig({ sitesFile: file })).toThrow(/http/i);
	});

	it('falls back to env single-site when no file is present', () => {
		const config = loadSitesConfig({
			// Force file miss even when the developer machine has ~/.stonewright/sites.json.
			sitesFile: '/tmp/does-not-exist-sw-sites-env-fallback.json',
			env: {
				STONEWRIGHT_WP_URL: 'https://example.test/',
				STONEWRIGHT_WP_USERNAME: 'editor',
				STONEWRIGHT_WP_APP_PASSWORD: 'test-direct-app-password',
			},
		});
		const site = resolveSite(config);
		expect(site.alias).toBe('default');
		expect(site.url).toBe('https://example.test');
		expect(site.restBase).toBe('https://example.test/wp-json');
		expect(site.username).toBe('editor');
		expect(site.appPassword).toBe('test-direct-app-password');
	});

	it('throws when neither file nor env credentials exist', () => {
		expect(() => loadSitesConfig({ env: {}, sitesFile: '/tmp/does-not-exist-sw-sites.json' })).toThrow(
			/STONEWRIGHT_WP_URL|sites\.json|credentials/i,
		);
	});

	it('exposes disabledTools per site', () => {
		const file = writeSites({
			default: 'prod',
			sites: {
				prod: {
					url: 'https://prod.example',
					username: 'admin',
					appPassword: 'pass',
					disabledTools: ['stonewright-content-delete'],
				},
			},
		});
		const config = loadSitesConfig({ sitesFile: file });
		expect(config.sites.prod?.disabledTools).toEqual(['stonewright-content-delete']);
	});

	it('defers v2 secret resolution until resolveSite for the requested alias', () => {
		const store = new MemoryCredentialStore();
		const refA = 'memory://stonewright/site-a/app-password';
		const refB = 'memory://stonewright/site-b/app-password';
		store.set(refA, 'only-a');
		store.set(refB, 'only-b');

		const file = writeSites({
			schema_version: 2,
			default_site_id: 'id-a',
			sites: [
				{
					id: 'id-a',
					alias: 'site-a',
					environment: 'other',
					canonical_url: 'https://site-a.example',
					url_fingerprint: 'sha256:a',
					username_hint: 'editor',
					credential_ref: refA,
					auth_method: 'application-password',
					configured_mode: 'auto',
					preferred_active_mode: 'plugin',
					fallback_policy: 'direct-when-plugin-unavailable',
					companion_profile: 'essential-static',
					clients: {},
				},
				{
					id: 'id-b',
					alias: 'site-b',
					environment: 'other',
					canonical_url: 'https://site-b.example',
					url_fingerprint: 'sha256:b',
					username_hint: 'editor',
					credential_ref: refB,
					auth_method: 'application-password',
					configured_mode: 'auto',
					preferred_active_mode: 'plugin',
					fallback_policy: 'direct-when-plugin-unavailable',
					companion_profile: 'essential-static',
					clients: {},
				},
			],
		});

		const config = loadSitesConfig({
			sitesFile: file,
			credentials: { store, prefer: 'memory' },
		});
		expect(config.sites['site-a']?.appPassword).toBe('');
		expect(config.sites['site-b']?.appPassword).toBe('');

		const a = resolveSite(config, 'site-a');
		expect(a.appPassword).toBe('only-a');
		expect(config.sites['site-b']?.appPassword).toBe('');

		const b = resolveSite(config, 'site-b');
		expect(b.appPassword).toBe('only-b');
	});
});
