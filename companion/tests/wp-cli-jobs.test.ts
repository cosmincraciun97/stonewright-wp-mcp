import { describe, expect, it } from 'vitest';
import {
	getWpCliJob,
	startWpCliJob,
	type ExecFileResult,
	type ExecFileRunner,
} from '../src/wp-cli.js';

describe('WP-CLI background jobs', () => {
	it('runs a guarded WP-CLI command in the background and returns compact status', async () => {
		let finish!: (result: ExecFileResult) => void;
		const runner: ExecFileRunner = () => new Promise((resolve) => {
			finish = resolve;
		});

		const started = startWpCliJob(
			{
				command: ['plugin', 'list', '--format=json'],
				parseJson: true,
				responseMode: 'summary',
				path: process.cwd(),
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		expect(started).toMatchObject({
			ok: true,
			status: 'running',
			kind: 'command',
			command_count: 1,
		});
		expect(started.job_id).toMatch(/^wpcli_/);
		expect(getWpCliJob({ jobId: started.job_id })).toMatchObject({
			ok: true,
			status: 'running',
			result: null,
		});

		finish({ stdout: '[{"name":"elementor"}]', stderr: '', exitCode: 0 });

		const done = await waitForDone(started.job_id);
		expect(done).toMatchObject({
			ok: true,
			status: 'succeeded',
			kind: 'command',
			command_count: 1,
		});
		expect(done.result).toMatchObject({
			ok: true,
			available: true,
			exit_code: 0,
			stderr_bytes: 0,
			parsed_json: [{ name: 'elementor' }],
		});
		expect(typeof done.result.stdout_bytes).toBe('number');
		expect(done.result).not.toHaveProperty('stdout');
	});

	it('runs a batch as one background job', async () => {
		const seen: string[][] = [];
		const runner: ExecFileRunner = (_file, args) => {
			seen.push(args.slice(-2));
			return Promise.resolve({ stdout: 'ok', stderr: '', exitCode: 0 });
		};

		const started = startWpCliJob(
			{
				commands: [
					['post', 'list'],
					['cache', 'flush'],
				],
			},
			runner,
			{ STONEWRIGHT_WP_CLI_BIN: 'wp' } as NodeJS.ProcessEnv,
		);

		const done = await waitForDone(started.job_id);

		expect(seen).toEqual([
			['post', 'list'],
			['cache', 'flush'],
		]);
		expect(done).toMatchObject({
			ok: true,
			status: 'succeeded',
			kind: 'batch',
			command_count: 2,
		});
		expect(done.result).toMatchObject({
			ok: true,
			count: 2,
			succeeded: 2,
			failed: 0,
		});
		expect(done.result.results[0]).not.toHaveProperty('stdout');
	});
});

async function waitForDone(jobId: string): Promise<ReturnType<typeof getWpCliJob>> {
	for (let i = 0; i < 20; i++) {
		const status = getWpCliJob({ jobId });
		if (status.status !== 'running' && status.status !== 'queued') {
			return status;
		}
		await new Promise((resolve) => setTimeout(resolve, 5));
	}
	return getWpCliJob({ jobId });
}
