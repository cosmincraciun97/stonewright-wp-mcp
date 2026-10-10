import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { runRescueCli } from '../src/cli/rescue.js';
import { buildSiteRecord, emptyRegistry, saveRegistry, upsertSite } from '../src/cli/connect/registry.js';
import type { ExecFileOptions, ExecFileResult, ExecFileRunner } from '../src/wp-cli.js';

const stateDir = mkdtempSync(join(tmpdir(), 'stonewright-rescue-state-'));
const sitesFile = join(stateDir, 'sites.json');
const wpRoot = mkdtempSync(join(tmpdir(), 'stonewright-rescue-wp-'));
writeFileSync(join(wpRoot, 'wp-config.php'), '<?php // synthetic fixture');

const INCIDENT = 'cs-0123456789abcdef01234567';
const TOKEN = `swc_${'A'.repeat(40)}.${'B'.repeat(30)}`;

interface Call {
	file: string;
	args: string[];
	options: ExecFileOptions;
}

interface Script {
	active?: string | null;
	network?: string | null;
	rescue?: Partial<ExecFileResult>;
}

/** A WP-CLI that answers the discovery calls and the rescue command from a script. */
function fakeWp(script: Script = {}): { runner: ExecFileRunner; calls: Call[] } {
	const calls: Call[] = [];
	const runner: ExecFileRunner = (file, args, options) => {
		calls.push({ file, args, options });
		const words = args.filter((token) => !token.startsWith('--'));
		const ok = (stdout: string): Promise<ExecFileResult> => Promise.resolve({ stdout, stderr: '', exitCode: 0 });
		if (words.includes('option') && words.includes('active_plugins')) {
			const active = script.active === undefined
				? JSON.stringify(['akismet/akismet.php', 'stonewright/stonewright.php', 'shop/shop.php'])
				: script.active;
			return active === null
				? Promise.resolve({ stdout: '', stderr: 'Error: Error establishing a database connection.', exitCode: 1 })
				: ok(active);
		}
		if (words.includes('network') && words.includes('meta')) {
			return script.network === undefined || script.network === null
				? Promise.resolve({ stdout: '', stderr: 'Error: This is not a multisite installation.', exitCode: 1 })
				: ok(script.network);
		}
		if (words.includes('stonewright') && words.includes('rescue')) {
			return Promise.resolve({ stdout: 'rescue output\n', stderr: '', exitCode: 0, ...script.rescue });
		}
		return Promise.resolve({ stdout: '', stderr: `unexpected command: ${words.join(' ')}`, exitCode: 1 });
	};
	return { runner, calls };
}

function rescueCall(calls: Call[]): Call {
	const call = calls.find((entry) => entry.args.includes('stonewright'));
	if (!call) throw new Error('the rescue command was not run');
	return call;
}

let out = '';
let err = '';
const realOut = process.stdout.write.bind(process.stdout);
const realErr = process.stderr.write.bind(process.stderr);

function register(overrides: Partial<Parameters<typeof buildSiteRecord>[0]> = {}): void {
	const site = buildSiteRecord({
		alias: 'rescue-site',
		url: 'https://rescue.example.test',
		username: 'editor-admin',
		credential_ref: 'memory://rescue/app-password',
		local_wp_root: wpRoot,
		...overrides,
	});
	saveRegistry(upsertSite(emptyRegistry(), site, { makeDefault: true }), { sitesFile });
}

beforeEach(() => {
	process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	process.env['STONEWRIGHT_SITES_FILE'] = sitesFile;
	register();
	out = '';
	err = '';
	process.stdout.write = ((chunk: string | Uint8Array) => {
		out += String(chunk);
		return true;
	}) as typeof process.stdout.write;
	process.stderr.write = ((chunk: string | Uint8Array) => {
		err += String(chunk);
		return true;
	}) as typeof process.stderr.write;
});

afterEach(() => {
	process.stdout.write = realOut;
	process.stderr.write = realErr;
	delete process.env['STONEWRIGHT_STATE_DIR'];
	delete process.env['STONEWRIGHT_SITES_FILE'];
	rmSync(join(stateDir, 'audit-direct.jsonl'), { force: true });
});

describe('stonewright rescue (companion)', () => {
	it('first reads the active plugins with every plugin skipped, then runs the rescue command with all but Stonewright skipped', async () => {
		const { runner, calls } = fakeWp();

		expect(await runRescueCli(['status'], process.env, runner)).toBe(0);

		const discovery = calls[0];
		if (!discovery) throw new Error('no discovery call');
		expect(discovery.args).toEqual(expect.arrayContaining(['option', 'get', 'active_plugins', '--format=json', '--skip-plugins', '--skip-themes']));
		const final = rescueCall(calls);
		const tokens = final.args;
		expect(tokens.slice(tokens.indexOf('stonewright'))).toEqual(['stonewright', 'rescue', 'status', '--skip-plugins=akismet,shop', '--skip-themes']);
		expect(tokens.some((token) => token.startsWith('--path='))).toBe(true);
		expect(tokens).toContain('--user=editor-admin');
		expect(out).toContain('rescue output');
	});

	it('never skips Stonewright: the rescue command carries no bare --skip-plugins', async () => {
		const { runner, calls } = fakeWp({ active: JSON.stringify(['stonewright/stonewright.php']) });

		await runRescueCli(['status'], process.env, runner);

		const final = rescueCall(calls).args;
		expect(final).not.toContain('--skip-plugins');
		expect(final.filter((token) => token.startsWith('--skip-plugins'))).toEqual([]);
		expect(final).toContain('--skip-themes');
	});

	it('finds Stonewright under any folder name and skips the others by their slugs', async () => {
		const { runner, calls } = fakeWp({
			active: JSON.stringify(['hello.php', 'stonewright-wp-mcp/stonewright.php', 'woo/woo.php', 'woo/extra.php', 'a-b_c.d/main.php']),
		});

		await runRescueCli(['status'], process.env, runner);

		expect(rescueCall(calls).args).toContain('--skip-plugins=hello,woo,a-b_c.d');
	});

	it('skips network-active plugins too, and never Stonewright when it is network active', async () => {
		const { runner, calls } = fakeWp({
			active: JSON.stringify(['akismet/akismet.php']),
			network: JSON.stringify({ 'stonewright/stonewright.php': 1700000000, 'seo/seo.php': 1700000100 }),
		});

		await runRescueCli(['status'], process.env, runner);

		expect(rescueCall(calls).args).toContain('--skip-plugins=akismet,seo');
	});

	it('runs every command as argv tokens without a shell', async () => {
		const { runner, calls } = fakeWp();

		await runRescueCli(['rollback', INCIDENT], process.env, runner);

		for (const call of calls) {
			expect(call.options.shell).toBe(false);
			expect(Array.isArray(call.args)).toBe(true);
			expect(call.args.every((token) => typeof token === 'string' && token !== '' && !token.includes('\0'))).toBe(true);
			expect(call.file).not.toMatch(/(^|[\\/])(cmd|sh|bash|zsh|powershell|pwsh)(\.exe)?$/i);
		}
	});

	it('never builds a command that evaluates code, opens a shell or loads a file', async () => {
		const { runner, calls } = fakeWp();

		await runRescueCli(['rollback', INCIDENT, '--token', TOKEN, '--json'], process.env, runner);

		for (const call of calls) {
			for (const token of call.args) {
				expect(token).not.toMatch(/^--(exec|require|prompt|ssh|http)(=|$)/);
				expect(['eval', 'eval-file', 'shell', 'package']).not.toContain(token);
			}
		}
	});

	it('hands the incident to the rescue command, with the json format when asked', async () => {
		const { runner, calls } = fakeWp();

		await runRescueCli(['rollback', INCIDENT, '--json'], process.env, runner);

		const tokens = rescueCall(calls).args;
		expect(tokens.slice(tokens.indexOf('stonewright'), tokens.indexOf('stonewright') + 4)).toEqual(['stonewright', 'rescue', 'rollback', INCIDENT]);
		expect(tokens).toContain('--format=json');
	});

	it('refuses an incident id that is not an id before anything runs', async () => {
		for (const bad of ['', '--require=evil.php', '../../x', 'cs-1;rm -rf /', '$(touch x)', 'cs 1', '-cs-1', 'a'.repeat(97)]) {
			const { runner, calls } = fakeWp();
			const argv = bad === '' ? ['rollback'] : ['rollback', bad];

			expect(await runRescueCli(argv, process.env, runner)).toBe(1);
			expect(calls).toEqual([]);
		}
	});

	it('takes the user from --user, or from the site, and refuses one that could be read as a flag', async () => {
		let fake = fakeWp();
		expect(await runRescueCli(['status', '--user', 'jane.admin'], process.env, fake.runner)).toBe(0);
		expect(rescueCall(fake.calls).args).toContain('--user=jane.admin');

		fake = fakeWp();
		expect(await runRescueCli(['status', '--user=12'], process.env, fake.runner)).toBe(0);
		expect(rescueCall(fake.calls).args).toContain('--user=12');

		fake = fakeWp();
		expect(await runRescueCli(['status'], process.env, fake.runner)).toBe(0);
		expect(rescueCall(fake.calls).args).toContain('--user=editor-admin');

		for (const bad of ['--exec=evil', '-x', 'a b', 'x;y', '']) {
			fake = fakeWp();
			expect(await runRescueCli(['status', `--user=${bad}`], process.env, fake.runner)).toBe(1);
			expect(fake.calls).toEqual([]);
		}
	});

	it('passes the confirmation token in the environment of the child, never as an argument', async () => {
		const { runner, calls } = fakeWp();

		await runRescueCli(['rollback', INCIDENT, '--token', TOKEN], process.env, runner);

		const final = rescueCall(calls);
		expect(final.args.join(' ')).not.toContain(TOKEN);
		expect(final.args.some((token) => token.startsWith('--confirmation-token'))).toBe(false);
		expect(final.options.env['STONEWRIGHT_CONFIRMATION_TOKEN']).toBe(TOKEN);
		for (const call of calls.filter((entry) => entry !== final)) {
			expect(call.options.env['STONEWRIGHT_CONFIRMATION_TOKEN']).toBeUndefined();
		}
	});

	it('refuses a token that is not shaped like a confirmation token', async () => {
		for (const bad of ['', 'swc_x', 'not-a-token', `${TOKEN} extra`, `${TOKEN};rm -rf /`, '--exec=x']) {
			const { runner, calls } = fakeWp();

			expect(await runRescueCli(['rollback', INCIDENT, `--token=${bad}`], process.env, runner)).toBe(1);
			expect(calls).toEqual([]);
		}
	});

	it('asks the rescue command to issue a token when told to', async () => {
		const { runner, calls } = fakeWp({ rescue: { stdout: `${TOKEN}\n` } });

		expect(await runRescueCli(['rollback', INCIDENT, '--issue-token'], process.env, runner)).toBe(0);

		expect(rescueCall(calls).args).toContain('--issue-token');
		expect(out).toContain(TOKEN);
	});

	it('does not print the token it was given', async () => {
		const { runner } = fakeWp();

		await runRescueCli(['rollback', INCIDENT, '--token', TOKEN], process.env, runner);

		expect(out + err).not.toContain(TOKEN);
	});

	it('maps the exit code of the rescue command: 0 stays 0, 2 asks for approval, anything else is 1', async () => {
		let fake = fakeWp({ rescue: { exitCode: 0 } });
		expect(await runRescueCli(['rollback', INCIDENT], process.env, fake.runner)).toBe(0);

		fake = fakeWp({ rescue: { exitCode: 2, stderr: 'Error: Production-safe mode needs a confirmation token.' } });
		expect(await runRescueCli(['rollback', INCIDENT], process.env, fake.runner)).toBe(2);
		expect(err).toContain('confirmation token');

		fake = fakeWp({ rescue: { exitCode: 1, stderr: 'Error: nothing to roll back' } });
		expect(await runRescueCli(['rollback', INCIDENT], process.env, fake.runner)).toBe(1);
	});

	it('stops before the rescue command when the active plugins cannot be read', async () => {
		for (const active of [null, 'not json', '{"not":"a list"}', JSON.stringify(['akismet/akismet.php'])]) {
			const { runner, calls } = fakeWp({ active });

			expect(await runRescueCli(['status'], process.env, runner)).toBe(1);
			expect(calls.some((call) => call.args.includes('stonewright'))).toBe(false);
		}
		expect(err).toContain('Stonewright is not an active plugin');
	});

	it('refuses to build a skip list from a plugin name it cannot pass safely', async () => {
		for (const name of ['bad,name/x.php', 'bad name/x.php', "quote'/x.php", '-flag/x.php', 'semi;colon/x.php']) {
			const { runner, calls } = fakeWp({ active: JSON.stringify(['stonewright/stonewright.php', name]) });

			expect(await runRescueCli(['status'], process.env, runner)).toBe(1);
			expect(calls.some((call) => call.args.includes('stonewright'))).toBe(false);
		}
	});

	it('needs a site with a local WordPress root', async () => {
		register({ local_wp_root: undefined as unknown as string });
		const { runner, calls } = fakeWp();

		expect(await runRescueCli(['status'], process.env, runner)).toBe(1);
		expect(calls).toEqual([]);
		expect(err).toContain('local WordPress root');
	});

	it('reports an unknown site and an unknown subcommand', async () => {
		const { runner, calls } = fakeWp();

		expect(await runRescueCli(['status', '--site', 'no-such-site'], process.env, runner)).toBe(1);
		expect(await runRescueCli(['bogus'], process.env, runner)).toBe(1);
		expect(await runRescueCli([], process.env, runner)).toBe(1);
		expect(await runRescueCli(['status', '--nope'], process.env, runner)).toBe(1);
		expect(calls).toEqual([]);
	});

	it('writes one audit row without arguments, token or output', async () => {
		const { runner } = fakeWp({ rescue: { stdout: 'UNIQUE-OUTPUT-MARKER-771\n' } });

		await runRescueCli(['rollback', INCIDENT, '--token', TOKEN], process.env, runner);

		const auditPath = join(stateDir, 'audit-direct.jsonl');
		expect(existsSync(auditPath)).toBe(true);
		const rows = readFileSync(auditPath, 'utf8').trim().split('\n');
		expect(rows).toHaveLength(1);
		const raw = rows[0] ?? '';
		expect((JSON.parse(raw) as { tool: string }).tool).toBe('stonewright-rescue-rollback');
		expect(raw).not.toContain(TOKEN);
		expect(raw).not.toContain('UNIQUE-OUTPUT-MARKER-771');
		expect(raw).not.toContain('--skip-plugins');
	});

	it('uses the shared WP-CLI runner and nothing else to start a process', () => {
		const source = readFileSync(new URL('../src/cli/rescue.ts', import.meta.url), 'utf8');

		expect(source).not.toMatch(/child_process|execSync|spawn|new Function|\beval\s*\(/);
		expect(source).not.toMatch(/['"`]--(exec|require)\b/);
		expect(source).not.toMatch(/shell\s*:\s*true/);
	});
});
