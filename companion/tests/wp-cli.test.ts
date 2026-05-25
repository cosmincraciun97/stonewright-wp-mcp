import { describe, expect, it } from 'vitest';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import {
	buildWpCliArgs,
	runWpCli,
	validateWpCliCommand,
	wpCliDiscover,
	type ExecFileRunner,
} from '../src/wp-cli.js';

describe('WP-CLI runner', () => {
	it('builds argv tokens for write commands without using a shell string', () => {
		const args = buildWpCliArgs({
			command: ['post', 'create', '--post_type=page', '--post_title=Home', '--porcelain'],
			path: 'D:/Sites/example',
			url: 'https://example.test',
			user: 'admin',
		});

		expect(args).toEqual([
			'--path=D:/Sites/example',
			'--url=https://example.test',
			'--user=admin',
			'post',
			'create',
			'--post_type=page',
			'--post_title=Home',
			'--porcelain',
		]);
	});

	it('blocks arbitrary PHP and interactive shell entry points', () => {
		expect(() => validateWpCliCommand(['eval', 'echo 1;'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['eval-file', 'script.php'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['shell'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['post', 'list', '--exec=echo 1;'])).toThrow(/blocked/i);
		expect(() => validateWpCliCommand(['post', 'list', '--require=bootstrap.php'])).toThrow(/blocked/i);
	});

	it('runs through execFile options with shell disabled', async () => {
		const calls: Array<{ file: string; args: string[]; shell: unknown }> = [];
		const runner: ExecFileRunner = (file, args, options) => {
			calls.push({ file, args, shell: options.shell });
			return Promise.resolve({ stdout: '42\n', stderr: '', exitCode: 0 });
		};

		const result = await runWpCli(
			{
				command: ['post', 'create', '--post_type=page', '--post_title=Home', '--porcelain'],
				path: process.cwd(),
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(true);
		expect(result.stdout).toBe('42\n');
		expect(calls).toHaveLength(1);
		expect(calls[0]?.file).toBe('wp');
		expect(calls[0]?.args).toContain('post');
		expect(calls[0]?.shell).toBe(false);
	});

	it('auto-discovers LocalWP PHP and WP-CLI phar when wp is not on PATH', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wpcli-'));
		try {
			const wpRoot = join(temp, 'workspace', 'site', 'app', 'public');
			const pharPath = join(
				temp,
				'workspace',
				'LocalWP',
				'resources',
				'extraResources',
				'bin',
				'wp-cli',
				'wp-cli.phar',
			);
			const phpPath = join(
				temp,
				'roaming',
				'Local',
				'lightning-services',
				'php-8.2.29+0',
				'bin',
				'win64',
				'php.exe',
			);
			mkdirSync(wpRoot, { recursive: true });
			mkdirSync(resolve(pharPath, '..'), { recursive: true });
			mkdirSync(resolve(phpPath, '..'), { recursive: true });
			writeFileSync(pharPath, 'wp-cli-phar');
			writeFileSync(phpPath, 'php-bin');

			const calls: Array<{ file: string; args: string[] }> = [];
			const runner: ExecFileRunner = (file, args) => {
				calls.push({ file, args });
				return Promise.resolve({ stdout: '{}', stderr: '', exitCode: 0 });
			};

			const result = await runWpCli(
				{
					command: ['cli', 'info', '--format=json'],
					path: wpRoot,
				},
				runner,
				{
					APPDATA: join(temp, 'roaming'),
					STONEWRIGHT_WP_ROOT: wpRoot,
				} as NodeJS.ProcessEnv,
			);

			expect(result.ok).toBe(true);
			expect(calls[0]?.file).toBe(phpPath);
			expect(calls[0]?.args[0]).toBe(pharPath);
			expect(calls[0]?.args).toContain('cli');
			expect(result.command[0]).toBe(phpPath);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('accepts comma-separated allowed roots and validates --path', async () => {
		const cwd = process.cwd();
		const calls: Array<{ args: string[] }> = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push({ args });
			return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
		};

		await runWpCli(
			{
				command: ['option', 'get', 'home'],
				cwd,
				path: cwd,
			},
			runner,
			{ STONEWRIGHT_WP_ALLOWED_ROOTS: `${cwd},D:\\StonewrightOtherRoot` } as NodeJS.ProcessEnv,
		);

		expect(calls[0]?.args).toContain(`--path=${resolve(cwd)}`);
		await expect(
			runWpCli(
				{
					command: ['option', 'get', 'home'],
					cwd,
					path: resolve(cwd, '..'),
				},
				runner,
				{ STONEWRIGHT_WP_ALLOWED_ROOTS: cwd } as NodeJS.ProcessEnv,
			),
		).rejects.toThrow(/--path is outside/i);
	});

	it('uses --path as cwd and allowed root when cwd/env root are omitted', async () => {
		const temp = mkdtempSync(join(tmpdir(), 'stonewright-wproot-'));
		try {
			const wpRoot = join(temp, 'app', 'public');
			mkdirSync(wpRoot, { recursive: true });
			const calls: Array<{ cwd: string; args: string[] }> = [];
			const runner: ExecFileRunner = (_file, args, options) => {
				calls.push({ cwd: options.cwd, args });
				return Promise.resolve({ stdout: '', stderr: '', exitCode: 0 });
			};

			await runWpCli(
				{
					command: ['plugin', 'list'],
					path: wpRoot,
				},
				runner,
				{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
			);

			expect(calls[0]?.cwd).toBe(resolve(wpRoot));
			expect(calls[0]?.args).toContain(`--path=${resolve(wpRoot)}`);
		} finally {
			rmSync(temp, { recursive: true, force: true });
		}
	});

	it('discovers installed command metadata with wp cli cmd-dump', async () => {
		const runner: ExecFileRunner = (_file, args) => {
			expect(args.slice(-2)).toEqual(['cli', 'cmd-dump']);
			return Promise.resolve({
				stdout: JSON.stringify({ name: 'wp', subcommands: [{ name: 'post' }] }),
				stderr: '',
				exitCode: 0,
			});
		};

		const result = await wpCliDiscover({}, runner);

		expect(result.ok).toBe(true);
		expect(result.parsed_json).toEqual({ name: 'wp', subcommands: [{ name: 'post' }] });
	});
});
