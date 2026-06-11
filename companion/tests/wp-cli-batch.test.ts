import { describe, expect, it } from 'vitest';
import { runWpCliBatch, type ExecFileRunner } from '../src/wp-cli.js';

describe('WP-CLI batch runner', () => {
	it('runs multiple argv commands and preserves Unicode tokens without a shell', async () => {
		const calls: Array<{ args: string[]; shell: unknown }> = [];
		const runner: ExecFileRunner = (_file, args, options) => {
			calls.push({ args, shell: options.shell });
			return Promise.resolve({ stdout: `ok ${calls.length}`, stderr: '', exitCode: 0 });
		};

		const result = await runWpCliBatch(
			{
				path: process.cwd(),
				commands: [
					['post', 'create', '--post_title=Marius Șoflete', '--porcelain'],
					['term', 'create', 'echipa_rol', 'Producție Media'],
				],
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(true);
		expect(result.succeeded).toBe(2);
		expect(result.failed).toBe(0);
		expect(calls).toHaveLength(2);
		expect(calls[0]?.args).toContain('--post_title=Marius Șoflete');
		expect(calls[1]?.args).toContain('Producție Media');
		expect(calls.every((call) => call.shell === false)).toBe(true);
	});

	it('stops on the first failed command by default', async () => {
		const calls: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			calls.push(args);
			return Promise.resolve({
				stdout: '',
				stderr: calls.length === 1 ? 'failed' : '',
				exitCode: calls.length === 1 ? 1 : 0,
			});
		};

		const result = await runWpCliBatch(
			{
				commands: [
					['post', 'create', '--post_title=Broken'],
					['post', 'list'],
				],
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(false);
		expect(result.stopped).toBe(true);
		expect(result.results).toHaveLength(1);
		expect(calls).toHaveLength(1);
	});
});
