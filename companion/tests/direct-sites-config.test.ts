import { afterEach, describe, expect, it } from 'vitest';
import { chmodSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
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
			default: 'transavia',
			sites: {
				transavia: {
					url: 'http://transavia-local.local',
					username: 'cosmin',
					appPassword: 'xxxx xxxx xxxx xxxx xxxx xxxx',
				},
				'client-b': {
					url: 'https://client-b.example',
					username: 'admin',
					appPassword: 'yyyy yyyy yyyy yyyy yyyy yyyy',
				},
			},
		});

		const config = loadSitesConfig({ sitesFile: file });
		expect(config.default).toBe('transavia');
		expect(Object.keys(config.sites)).toEqual(['transavia', 'client-b']);

		const site = resolveSite(config, 'client-b');
		expect(site.alias).toBe('client-b');
		expect(site.url).toBe('https://client-b.example');
		expect(site.restBase).toBe('https://client-b.example/wp-json');
		expect(site.username).toBe('admin');
	});

	it('rejects invalid JSON with a path-aware error', () => {
		const dir = mkdtempSync(join(tmpdir(), 'sw-sites-'));
		dirs.push(dir);
		const file = join(dir, 'sites.json');
		writeFileSync(file, '{not-json', 'utf8');
		chmodSync(file, 0o600);

		expect(() => loadSitesConfig({ sitesFile: file })).toThrow(/sites\.json|JSON|parse/i);
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
				STONEWRIGHT_WP_APP_PASSWORD: 'aa bb cc dd ee ff',
			},
		});
		const site = resolveSite(config);
		expect(site.alias).toBe('default');
		expect(site.url).toBe('https://example.test');
		expect(site.restBase).toBe('https://example.test/wp-json');
		expect(site.username).toBe('editor');
		expect(site.appPassword).toBe('aa bb cc dd ee ff');
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
});
