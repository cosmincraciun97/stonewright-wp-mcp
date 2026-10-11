import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { gateDirectWpCli, classifyWpCliArgv } from '../src/direct/wp-cli-gate.js';
import { markTaskStartSeen, resetTaskStartSeenForTests, DirectSafetyBlockedError } from '../src/direct/writes.js';
import { resetLocalTargetCacheForTests } from '../src/direct/elementor-target.js';
import type { SitesConfig } from '../src/direct/sites-config.js';
import type { WpCliCommandResult } from '../src/wp-cli.js';
import { createMcpServer } from '../src/mcp-server.js';
import { forgetPluginPresence, setPluginPresenceResolverForTests } from '../src/direct/plugin-guard.js';

const LOCAL = { alias: 'local', url: 'http://example.test' };
const REMOTE = { alias: 'remote', url: 'https://example.com' };

function config(target: { alias: string; url: string }): SitesConfig {
	return {
		default: target.alias,
		sites: { [target.alias]: { url: target.url, username: 'agent', appPassword: 'fixture-app-pass-0001' } },
		source: 'file',
	};
}

/** A WP-CLI stand-in: answers `option get home|siteurl` with `home`, counts every call. */
function runnerFor(home: string | null) {
	return vi.fn((input: { command: string[] }): Promise<WpCliCommandResult> => {
		const key = input.command.join(' ');
		const answer = key === 'option get home' || key === 'option get siteurl' ? home : null;
		return Promise.resolve({
			ok: answer !== null,
			available: true,
			command: input.command,
			cwd: '/tmp',
			stdout: answer ?? '',
			stderr: '',
			exit_code: answer === null ? 1 : 0,
			duration_ms: 1,
			wp_cli_source: 'test',
		});
	});
}

describe('classifyWpCliArgv', () => {
	it.each([
		[['post', 'get', '5'], 'read'],
		[['post', 'list', '--format=json'], 'read'],
		[['option', 'get', 'siteurl'], 'read'],
		[['plugin', 'list'], 'read'],
		[['theme', 'list'], 'read'],
		[['user', 'list'], 'read'],
		[['cache', 'flush'], 'read'],
		[['search-replace', 'a', 'b', '--dry-run'], 'read'],
		[['option', 'update', 'blogname', 'x'], 'write'],
		[['option', 'add', 'k', 'v'], 'write'],
		[['option', 'delete', 'k'], 'write'],
		[['post', 'meta', 'update', '5', 'k', 'v'], 'write'],
		[['post', 'update', '5', '--post_title=x'], 'write'],
		[['post', 'create', '--post_title=x'], 'write'],
		[['post', 'delete', '5'], 'write'],
		[['term', 'create', 'category', 'News'], 'write'],
		[['menu', 'item', 'add-custom', 'main', 'x', 'https://example.com'], 'write'],
		[['media', 'import', 'https://example.com/a.png'], 'write'],
		[['transient', 'delete', '--all'], 'write'],
		[['import', 'file.xml'], 'write'],
		[['wp', 'post', 'delete', '5'], 'write'],
		[['@prod', 'option', 'get', 'home'], 'write'],
		[['--path=/x', 'option', 'get', 'home'], 'write'],
		[['unheard', 'of'], 'write'],
	] as Array<[string[], string]>)('%j is a %s', (argv, expected) => {
		expect(classifyWpCliArgv(argv)).toBe(expected);
	});

	it.each([
		[['db', 'query', 'DELETE FROM wp_options']],
		[['db', 'reset', '--yes']],
		[['plugin', 'install', 'https://example.com/x.zip']],
		[['plugin', 'install', 'akismet']],
		[['eval', 'echo 1;']],
		[['config', 'set', 'WP_DEBUG', 'true']],
		[['core', 'update']],
		[['user', 'create', 'x', 'x@example.com']],
		[['role', 'create', 'boss', 'Boss']],
		[['option', 'update', 'default_role', 'administrator']],
	] as string[][])('%j is denied outright', (argv) => {
		expect(classifyWpCliArgv(argv)).toBe('denied');
	});
});

describe('gateDirectWpCli', () => {
	let stateDir: string;
	let saved: Record<string, string | undefined>;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-cli-gate-'));
		saved = { STONEWRIGHT_STATE_DIR: process.env['STONEWRIGHT_STATE_DIR'] };
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		resetLocalTargetCacheForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		resetLocalTargetCacheForTests();
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
	});

	function auditRows(): Array<Record<string, unknown>> {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
	}

	function gate(
		commands: string[][],
		over: { confirm?: boolean; env?: NodeJS.ProcessEnv; target?: { alias: string; url: string }; runner?: ReturnType<typeof runnerFor>; tool?: 'stonewright-wp-cli-run' | 'stonewright-wp-cli-job-start' } = {},
	) {
		const target = over.target ?? LOCAL;
		return gateDirectWpCli({
			tool: over.tool ?? 'stonewright-wp-cli-run',
			commands,
			...(over.confirm !== undefined ? { confirm: over.confirm } : {}),
			env: { STONEWRIGHT_STATE_DIR: stateDir, ...(over.env ?? {}) },
			target: {},
			runner: (over.runner ?? runnerFor(target.url)) as never,
			sitesConfig: config(target),
		});
	}

	describe('a write without the task-start latch', () => {
		it.each([
			['wp db query'],
			['wp option update'],
			['wp post meta update'],
			['wp plugin install url'],
		])('%s is refused and the runner is never called', async (label) => {
			const argv = {
				'wp db query': ['db', 'query', 'DELETE FROM wp_options'],
				'wp option update': ['option', 'update', 'blogname', 'x'],
				'wp post meta update': ['post', 'meta', 'update', '5', 'k', 'v'],
				'wp plugin install url': ['plugin', 'install', 'https://example.com/x.zip'],
			}[label] as string[];
			const runner = runnerFor(LOCAL.url);
			await expect(gate([argv], { runner })).rejects.toThrow();
			expect(runner).not.toHaveBeenCalled();
		});

		it('option update says that task-start is required', async () => {
			const runner = runnerFor(LOCAL.url);
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner }).then(() => null, (e: unknown) => e);
			expect(err).toBeInstanceOf(DirectSafetyBlockedError);
			expect(err).toMatchObject({ code: 'task_start_required' });
			expect(runner).not.toHaveBeenCalled();
		});
	});

	describe('the dangerous command groups stay refused, with or without the latch and confirm', () => {
		it.each([
			[['db', 'query', 'DELETE FROM wp_options'], /db/i],
			[['plugin', 'install', 'https://example.com/x.zip'], /plugin install/i],
			[['eval', 'echo 1;'], /eval/i],
			[['config', 'set', 'WP_DEBUG', 'true'], /config/i],
			[['user', 'create', 'x', 'x@example.com'], /user/i],
			[['option', 'update', 'default_role', 'administrator'], /default_role/i],
		] as Array<[string[], RegExp]>)('%j', async (argv, message) => {
			markTaskStartSeen(LOCAL);
			const runner = runnerFor(LOCAL.url);
			await expect(gate([argv], { runner, confirm: true })).rejects.toThrow(message);
			expect(runner).not.toHaveBeenCalled();
			expect(auditRows().at(-1)).toMatchObject({ tool: 'stonewright-wp-cli-run', status: 'blocked', code: 'wp_cli_command_refused' });
		});

		it('a refused command inside a batch refuses the whole batch', async () => {
			markTaskStartSeen(LOCAL);
			const runner = runnerFor(LOCAL.url);
			await expect(
				gate([['option', 'update', 'blogname', 'x'], ['db', 'query', 'DROP TABLE wp_posts']], { runner, confirm: true, tool: 'stonewright-wp-cli-job-start' }),
			).rejects.toThrow(/db/i);
			expect(runner).not.toHaveBeenCalled();
		});
	});

	describe('confirm mode', () => {
		it('a remote site needs confirm:true; without it nothing runs', async () => {
			markTaskStartSeen(REMOTE);
			const runner = runnerFor(REMOTE.url);
			const err = await gate([['option', 'update', 'blogname', 'x']], { target: REMOTE, runner }).then(() => null, (e: unknown) => e);
			expect(err).toMatchObject({ code: 'confirmation_required' });
			expect(runner).not.toHaveBeenCalled();
			expect(auditRows().at(-1)).toMatchObject({ status: 'blocked', code: 'confirmation_required' });
		});

		it('confirm:true lets it through, after the local install is checked', async () => {
			markTaskStartSeen(REMOTE);
			const runner = runnerFor(REMOTE.url);
			const handle = await gate([['option', 'update', 'blogname', 'x']], { target: REMOTE, runner, confirm: true });
			expect(handle.write).toBe(true);
			expect(runner.mock.calls.map((c) => c[0].command.join(' '))).toEqual(['option get home']);
		});

		it('refuses a write when the Stonewright plugin is active on the site, before WP-CLI runs', async () => {
			markTaskStartSeen(LOCAL);
			forgetPluginPresence();
			setPluginPresenceResolverForTests(() => Promise.resolve('present'));
			const runner = runnerFor(LOCAL.url);
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner }).catch((e: unknown) => e);
			expect(err).toBeInstanceOf(DirectSafetyBlockedError);
			expect((err as DirectSafetyBlockedError).code).toBe('stonewright_direct_write_blocked_plugin_present');
			expect(runner).not.toHaveBeenCalled();
		});

		it('a read still runs on a site where the plugin is active', async () => {
			forgetPluginPresence();
			setPluginPresenceResolverForTests(() => Promise.resolve('present'));
			const handle = await gate([['option', 'get', 'siteurl']]);
			expect(handle.write).toBe(false);
		});

		it('a local .test site does not need confirm', async () => {
			markTaskStartSeen(LOCAL);
			const handle = await gate([['option', 'update', 'blogname', 'x']]);
			expect(handle.write).toBe(true);
		});

		it('STONEWRIGHT_DIRECT_WRITES=off refuses every write', async () => {
			markTaskStartSeen(LOCAL);
			const runner = runnerFor(LOCAL.url);
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner, confirm: true, env: { STONEWRIGHT_DIRECT_WRITES: 'off' } }).then(() => null, (e: unknown) => e);
			expect(err).toMatchObject({ code: 'direct_writes_disabled' });
			expect(runner).not.toHaveBeenCalled();
		});
	});

	describe('the local install must be the task-started site', () => {
		it('refuses a write when the local WordPress is another site', async () => {
			markTaskStartSeen(LOCAL);
			const runner = runnerFor('http://other.example.test');
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner }).then(() => null, (e: unknown) => e);
			expect(err).toBeInstanceOf(DirectSafetyBlockedError);
			expect(err).toMatchObject({ code: 'wp_cli_target_mismatch' });
			// Only the two reads of the home and siteurl options ran; nothing was written.
			expect(runner.mock.calls.map((c) => c[0].command.join(' '))).toEqual(['option get home', 'option get siteurl']);
			expect(auditRows().at(-1)).toMatchObject({ status: 'blocked', code: 'wp_cli_target_mismatch' });
		});

		it('refuses a write when the local WordPress cannot be identified', async () => {
			markTaskStartSeen(LOCAL);
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner: runnerFor(null) }).then(() => null, (e: unknown) => e);
			expect(err).toMatchObject({ code: 'wp_cli_target_mismatch' });
		});

		it('the latch of another site does not count', async () => {
			markTaskStartSeen(REMOTE);
			const runner = runnerFor(LOCAL.url);
			const err = await gate([['option', 'update', 'blogname', 'x']], { runner }).then(() => null, (e: unknown) => e);
			expect(err).toMatchObject({ code: 'task_start_required' });
			expect(runner).not.toHaveBeenCalled();
		});
	});

	describe('reads', () => {
		it('pass without the latch, the confirm or the target check, and leave no audit row', async () => {
			const runner = runnerFor(LOCAL.url);
			const handle = await gate([['post', 'get', '5'], ['option', 'get', 'home'], ['plugin', 'list']], { runner });
			expect(handle.write).toBe(false);
			handle.finish({ ok: true });
			expect(runner).not.toHaveBeenCalled();
			expect(auditRows()).toEqual([]);
		});
	});

	describe('the audit row of a write', () => {
		it('records the outcome with the command words and no values', async () => {
			markTaskStartSeen(LOCAL);
			const handle = await gate([['option', 'update', 'blogname', 'a value that must not be stored']]);
			expect(auditRows()).toEqual([]);
			handle.finish({ ok: true });
			const row = auditRows().at(-1);
			expect(row).toMatchObject({ tool: 'stonewright-wp-cli-run', status: 'ok' });
			expect(String(row?.['resource'])).toContain('option update blogname');
			expect(JSON.stringify(row)).not.toContain('must not be stored');
		});

		it('records a failed run as an error row', async () => {
			markTaskStartSeen(LOCAL);
			const handle = await gate([['post', 'delete', '5']]);
			handle.finish({ ok: false, code: 'wp_cli_failed' });
			expect(auditRows().at(-1)).toMatchObject({ status: 'error', code: 'wp_cli_failed' });
		});

		it('a job with one write among reads is gated and audited once', async () => {
			markTaskStartSeen(LOCAL);
			const handle = await gate([['post', 'list'], ['post', 'meta', 'update', '5', 'k', 'v']], { tool: 'stonewright-wp-cli-job-start' });
			expect(handle.write).toBe(true);
			handle.finish({ ok: true });
			expect(auditRows().filter((row) => row['tool'] === 'stonewright-wp-cli-job-start')).toHaveLength(1);
		});
	});

	it('refuses a write when no Direct site is configured', async () => {
		const err = await gateDirectWpCli({
			tool: 'stonewright-wp-cli-run',
			commands: [['option', 'update', 'blogname', 'x']],
			env: { STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_SITES_FILE: join(stateDir, 'none.json') },
			target: {},
			runner: runnerFor(LOCAL.url) as never,
		}).then(() => null, (e: unknown) => e);
		expect(err).toMatchObject({ code: 'direct_site_unresolved' });
	});
});

describe('the WP-CLI tools of a Direct session', () => {
	let stateDir: string;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-cli-tools-'));
		resetTaskStartSeenForTests();
		resetLocalTargetCacheForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
		rmSync(stateDir, { recursive: true, force: true });
	});

	type Handler = (input: unknown) => Promise<{ content: Array<{ text: string }>; structuredContent?: Record<string, unknown> }>;

	async function session(mode: 'direct' | 'plugin') {
		const env: NodeJS.ProcessEnv = {
			STONEWRIGHT_MODE: mode,
			STONEWRIGHT_STATE_DIR: stateDir,
			STONEWRIGHT_SITES_FILE: join(stateDir, 'none.json'),
			STONEWRIGHT_WP_URL: 'http://example.test',
			STONEWRIGHT_WP_USERNAME: 'agent',
			STONEWRIGHT_WP_APP_PASSWORD: 'fixture-app-pass-0001',
			// No WP-CLI exists here: a command that was run would come back as "unavailable".
			STONEWRIGHT_WP_CLI_BIN: join(stateDir, 'no-such-wp'),
		};
		const server = await createMcpServer({
			env,
			fetchImpl: vi.fn(() => Promise.resolve(new Response('{}', { status: 404, headers: { 'content-type': 'application/json' } }))) as never,
		});
		const tools = (server as unknown as { _registeredTools: Record<string, { handler: Handler }> })._registeredTools;
		const call = (name: string, input: unknown) => (tools[name] as { handler: Handler }).handler(input);
		return { call, tools };
	}

	it.each([
		['stonewright-wp-cli-run', { command: ['option', 'update', 'blogname', 'x'] }],
		['stonewright-wp-cli-run', { command: ['post', 'meta', 'update', '5', 'k', 'v'] }],
		['stonewright-wp-cli-job-start', { command: ['option', 'update', 'blogname', 'x'] }],
		['stonewright-wp-cli-job-start', { commands: [['post', 'list'], ['option', 'update', 'blogname', 'x']] }],
	])('%s refuses %j without task-start', async (name, input) => {
		const { call } = await session('direct');
		await expect(call(name, input)).rejects.toThrow(/stonewright-task-start/);
		expect(auditFile()).toContain('task_start_required');
	});

	it('wp db query and a URL plugin install are refused with the Plugin-mode wording', async () => {
		const { call } = await session('direct');
		markTaskStartSeen({ alias: 'default', url: 'http://example.test' });
		await expect(call('stonewright-wp-cli-run', { command: ['db', 'query', 'DELETE FROM wp_options'], confirm: true })).rejects.toThrow(/Blocked WP-CLI command: db/);
		await expect(call('stonewright-wp-cli-run', { command: ['plugin', 'install', 'https://example.com/x.zip'], confirm: true })).rejects.toThrow(/Blocked WP-CLI command: plugin install/);
	});

	it('the batch runner stays read-only in a Direct session, so a write in a batch never reaches WP-CLI', async () => {
		const { call } = await session('direct');
		markTaskStartSeen({ alias: 'default', url: 'http://example.test' });
		await expect(call('stonewright-wp-cli-batch-run', { commands: [['post', 'list'], ['option', 'update', 'blogname', 'x']] })).rejects.toThrow(/read-oriented/);
	});

	it('a read runs without any of this', async () => {
		const { call } = await session('direct');
		const response = await call('stonewright-wp-cli-run', { command: ['option', 'get', 'home'] });
		expect(response.structuredContent).toMatchObject({ available: false });
	});

	it('Plugin mode keeps its behavior: a write goes to the runner, no latch is asked for', async () => {
		const { call } = await session('plugin');
		const response = await call('stonewright-wp-cli-run', { command: ['option', 'update', 'blogname', 'x'] });
		expect(response.structuredContent).toMatchObject({ available: false });
	});

	function auditFile(): string {
		const path = join(stateDir, 'audit-direct.jsonl');
		return existsSync(path) ? readFileSync(path, 'utf8') : '';
	}
});
