import {
	FIXTURE_RUN_ID_ENV,
	createFixtureRunId,
	oauthFixtureBase,
	removeRunFixtureRoots,
	removeStaleFixtureRoots,
} from './oauth-fixture-cleanup.js';

/**
 * Vitest globalSetup. Windows only: gives every worker of this run the same
 * run id, sweeps stale roots of crashed runs, and removes this run's roots
 * once all workers have exited.
 */
export default async function setup(): Promise<() => Promise<void>> {
	if (process.platform !== 'win32') return () => Promise.resolve();
	const runId = createFixtureRunId();
	const base = oauthFixtureBase();
	process.env[FIXTURE_RUN_ID_ENV] = runId;
	report('stale', await removeStaleFixtureRoots(base, { now: Date.now, currentRunId: runId }));
	return async () => {
		report('run', await removeRunFixtureRoots(base, runId));
		delete process.env[FIXTURE_RUN_ID_ENV];
	};
}

function report(label: string, result: { failed: string[] }): void {
	if (result.failed.length > 0) process.stderr.write(`OAuth fixture cleanup (${label}) could not remove: ${result.failed.join(', ')}
`);
}
