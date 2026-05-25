import { describe, expect, it } from 'vitest';
import { resolve } from 'node:path';
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
		const runner: ExecFileRunner = async (file, args, options) => {
			calls.push({ file, args, shell: options.shell });
			return { stdout: '42\n', stderr: '', exitCode: 0 };
		};

		const result = await runWpCli(
			{
				command: ['post', 'create', '--post_type=page', '--post_title=Home', '--porcelain'],
				path: process.cwd(),
			},
			runner,
		);

		expect(result.ok).toBe(true);
		expect(result.stdout).toBe('42\n');
		expect(calls).toHaveLength(1);
		expect(calls[0]?.file).toBe('wp');
		expect(calls[0]?.args).toContain('post');
		expect(calls[0]?.shell).toBe(false);
	});

	it('accepts comma-separated allowed roots and validates --path', async () => {
		const cwd = process.cwd();
		const calls: Array<{ args: string[] }> = [];
		const runner: ExecFileRunner = async (_file, args) => {
			calls.push({ args });
			return { stdout: '', stderr: '', exitCode: 0 };
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

	it('discovers installed command metadata with wp cli cmd-dump', async () => {
		const runner: ExecFileRunner = async (_file, args) => {
			expect(args).toEqual(['cli', 'cmd-dump']);
			return {
				stdout: JSON.stringify({ name: 'wp', subcommands: [{ name: 'post' }] }),
				stderr: '',
				exitCode: 0,
			};
		};

		const result = await wpCliDiscover({}, runner);

		expect(result.ok).toBe(true);
		expect(result.parsed_json).toEqual({ name: 'wp', subcommands: [{ name: 'post' }] });
	});
});
