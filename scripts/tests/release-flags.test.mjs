import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { releaseFlags } from '../release-flags.mjs';

const releaseWorkflow = readFileSync(
	new URL('../../.github/workflows/release.yml', import.meta.url),
	'utf8',
);

test('future beta and rc versions are prereleases', () => {
	assert.deepEqual(releaseFlags('1.0.0-beta.10'), ['--prerelease']);
	assert.deepEqual(releaseFlags('1.0.0-rc.1'), ['--prerelease']);
});

test('stable versions are latest releases', () => {
	assert.deepEqual(releaseFlags('1.0.0'), ['--latest']);
});

test('malformed versions fail closed', () => {
	assert.throws(() => releaseFlags('v1.0.0'), /semantic version/i);
	assert.throws(() => releaseFlags('latest'), /semantic version/i);
	assert.throws(() => releaseFlags('1.0'), /semantic version/i);
});

test('plugin release archive carries the canonical license', () => {
	assert.match(releaseWorkflow, /cp LICENSE dist\/stonewright\/LICENSE/);
	assert.match(releaseWorkflow, /stonewright\/LICENSE/);
});

test('archive inspections remain reliable with pipefail enabled', () => {
	assert.doesNotMatch(
		releaseWorkflow,
		/(?:tar -tzf|unzip -Z1)[^\n]*\|\s*grep\s+-[A-Za-z]*q/,
	);
});
