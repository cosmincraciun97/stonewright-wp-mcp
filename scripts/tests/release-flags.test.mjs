import assert from 'node:assert/strict';
import test from 'node:test';
import { releaseFlags } from '../release-flags.mjs';

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
