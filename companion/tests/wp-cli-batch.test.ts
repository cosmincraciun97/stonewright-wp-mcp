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

	it('can return compact per-command summaries for token-efficient MCP output', async () => {
		const runner: ExecFileRunner = (_file, _args) => Promise.resolve({
			stdout: JSON.stringify({ id: 10, title: 'Speaker', payload: 'x'.repeat(5000) }),
			stderr: 'warning '.repeat(100),
			exitCode: 0,
		});

		const result = await runWpCliBatch(
			{
				commands: [
					['post', 'list', '--format=json'],
					['post', 'meta', 'list', '10', '--format=json'],
				],
				parseJson: true,
				responseMode: 'summary',
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(result.ok).toBe(true);
		expect(result.results).toHaveLength(2);
		expect(result.results[0]).toMatchObject({
			ok: true,
			available: true,
			exit_code: 0,
			stdout_bytes: expect.any(Number) as unknown as number,
			stderr_bytes: 800,
		});
		expect(Number(result.results[0]?.stdout_bytes)).toBeGreaterThan(5000);
		expect(result.results[0]).not.toHaveProperty('stdout');
		expect(result.results[0]).not.toHaveProperty('stderr');
		expect(result.results[0]).toHaveProperty('parsed_json');
	});
});
