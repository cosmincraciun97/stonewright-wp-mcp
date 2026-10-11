import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	elementorDataGet,
	elementorDataUpdate,
	elementorStatus,
} from '../src/direct/tools/elementor-direct.js';
import { resetLocalTargetCacheForTests } from '../src/direct/elementor-target.js';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';
import type { WpCliCommandResult } from '../src/wp-cli.js';

const ALIAS_URL = 'https://target-site.example';
const LOCAL_URL = 'http://local-install.test';

const tree = [{ id: 'abc', elType: 'container', elements: [] }];

function result(input: { command: string[] }, over: Partial<WpCliCommandResult> = {}): WpCliCommandResult {
	return {
		ok: true,
		available: true,
		command: input.command,
		cwd: '/tmp',
		stdout: '',
		stderr: '',
		exit_code: 0,
		duration_ms: 1,
		wp_cli_source: 'test',
		...over,
	} as WpCliCommandResult;
}

/** A local WP-CLI install whose `home` and `siteurl` are `localUrl`. */
function localInstall(localUrl: string) {
	const calls: string[] = [];
	const cli = vi.fn((input: { command: string[]; stdin?: string }): Promise<WpCliCommandResult> => {
		const key = input.command.join(' ');
		calls.push(key);
		if (key === 'option get home' || key === 'option get siteurl') {
			return Promise.resolve(result(input, { stdout: `${localUrl}\n` }));
		}
		if (key.includes('_elementor_data') && key.includes('get')) {
			return Promise.resolve(result(input, { stdout: JSON.stringify(tree), parsed_json: tree }));
		}
		if (key.includes('plugin list')) {
			return Promise.resolve(result(input, {
				parsed_json: [{ name: 'elementor', status: 'active', version: '3.20.0' }],
			}));
		}
		return Promise.resolve(result(input));
	});
	return { cli, calls };
}

function remoteRest(url = ALIAS_URL) {
	return {
		target: { alias: 'live', url },
		get: vi.fn((path: string) => {
			if (path.includes('/pages/42')) {
				return Promise.resolve({
					id: 42,
					meta: { _elementor_data: JSON.stringify(tree), _elementor_edit_mode: 'builder' },
				});
			}
			return Promise.reject(new Error('not found'));
		}),
		post: vi.fn(() => Promise.resolve({})),
	};
}

describe('direct elementor target check', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-el-target-'));
		resetLocalTargetCacheForTests();
		markTaskStartSeen({ alias: 'live', url: ALIAS_URL, scopes: [] } as never);
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
	});

	it('data-get does not read from a local install that is another site', async () => {
		const { cli, calls } = localInstall(LOCAL_URL);
		const rest = remoteRest();

		const out = await elementorDataGet({}, { post_id: 42 }, cli as never, rest) as Record<string, unknown>;

		expect(out['ok']).toBe(true);
		expect(out['transport']).toBe('rest');
		expect(calls.some((c) => c.includes('post meta'))).toBe(false);
		expect(rest.get).toHaveBeenCalled();
		const refused = String(out['wp_cli_refused']);
		expect(refused).toContain('the local WordPress is not the target site');
		expect(refused).not.toContain('target-site.example');
		expect(refused).not.toContain('local-install.test');
	});

	it('data-update writes through REST, never through the other local install', async () => {
		const { cli, calls } = localInstall(LOCAL_URL);
		const rest = remoteRest();
		const env = { STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_WRITES: 'on' };

		const out = await elementorDataUpdate(
			env,
			{ post_id: 42, data: tree, confirm: true },
			cli as never,
			rest,
		) as Record<string, unknown>;

		expect(out['ok']).toBe(true);
		expect(out['transport']).toBe('rest');
		expect(calls.some((c) => c.includes('meta update'))).toBe(false);
		expect(rest.post).toHaveBeenCalledTimes(1);
		expect(String(out['wp_cli_refused'])).toContain('the local WordPress is not the target site');
	});

	it('status reports WP-CLI as unusable for the alias when the local install differs', async () => {
		const { cli } = localInstall(LOCAL_URL);
		const out = await elementorStatus({}, {}, cli as never, remoteRest()) as Record<string, unknown>;

		expect(out['wp_cli']).toBe(false);
		expect(out['rest_fallback']).toBe(true);
		expect(out['transport_preference']).toEqual(['rest']);
		expect(String(out['wp_cli_refused'])).toContain('the local WordPress is not the target site');
	});

	it('uses WP-CLI when the local install is the alias site', async () => {
		const { cli, calls } = localInstall(`${ALIAS_URL}/`);
		const rest = remoteRest();

		const out = await elementorDataGet({}, { post_id: 42 }, cli as never, rest) as Record<string, unknown>;

		expect(out['ok']).toBe(true);
		expect(out['transport']).toBe('wp-cli');
		expect(out['wp_cli_refused']).toBeUndefined();
		expect(calls.some((c) => c.includes('post meta get'))).toBe(true);
		expect(rest.get).not.toHaveBeenCalled();
	});

	it('treats a host with a different scheme or default port as the same site', async () => {
		const { cli } = localInstall('http://TARGET-SITE.example:80/');
		const out = await elementorDataGet({}, { post_id: 42 }, cli as never, remoteRest('http://target-site.example')) as Record<string, unknown>;
		expect(out['transport']).toBe('wp-cli');
	});

	it('accepts a match on siteurl when home differs', async () => {
		const calls: string[] = [];
		const cli = vi.fn((input: { command: string[] }): Promise<WpCliCommandResult> => {
			const key = input.command.join(' ');
			calls.push(key);
			if (key === 'option get home') return Promise.resolve(result(input, { stdout: 'https://front.example\n' }));
			if (key === 'option get siteurl') return Promise.resolve(result(input, { stdout: `${ALIAS_URL}\n` }));
			if (key.includes('_elementor_data') && key.includes('get')) {
				return Promise.resolve(result(input, { stdout: JSON.stringify(tree), parsed_json: tree }));
			}
			return Promise.resolve(result(input));
		});
		const out = await elementorDataGet({}, { post_id: 42 }, cli as never, remoteRest()) as Record<string, unknown>;
		expect(out['transport']).toBe('wp-cli');
	});

	it('refuses WP-CLI when the local site address cannot be read', async () => {
		const cli = vi.fn((input: { command: string[] }): Promise<WpCliCommandResult> => {
			const key = input.command.join(' ');
			if (key.startsWith('option get')) {
				return Promise.resolve(result(input, { ok: false, stderr: 'Error: This does not seem to be a WordPress installation.', exit_code: 1 }));
			}
			return Promise.resolve(result(input));
		});
		const out = await elementorDataGet({}, { post_id: 42 }, cli as never, remoteRest()) as Record<string, unknown>;
		expect(out['transport']).toBe('rest');
		expect(String(out['wp_cli_refused'])).toContain('could not be confirmed');
	});

	it('asks the local install once per alias and process', async () => {
		const { cli, calls } = localInstall(LOCAL_URL);
		const rest = remoteRest();
		await elementorDataGet({}, { post_id: 42 }, cli as never, rest);
		await elementorDataGet({}, { post_id: 42 }, cli as never, rest);
		await elementorStatus({}, {}, cli as never, rest);
		expect(calls.filter((c) => c.startsWith('option get'))).toHaveLength(2);
	});

	it('does not check a local install when there is no alias target', async () => {
		const { cli, calls } = localInstall(LOCAL_URL);
		const out = await elementorDataGet({}, { post_id: 42 }, cli as never) as Record<string, unknown>;
		expect(out['transport']).toBe('wp-cli');
		expect(calls.some((c) => c.startsWith('option get'))).toBe(false);
	});
});
