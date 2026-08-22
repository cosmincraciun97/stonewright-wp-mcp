import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { releaseChannelFromNotes, releaseFlags } from '../release-flags.mjs';

const releaseWorkflow = readFileSync(
	new URL('../../.github/workflows/release.yml', import.meta.url),
	'utf8',
);
const readme = readFileSync(new URL('../../README.md', import.meta.url), 'utf8');
const docsFreshness = readFileSync(
	new URL('../check-docs-freshness.mjs', import.meta.url),
	'utf8',
);

test('supported public betas are latest releases', () => {
	assert.deepEqual(releaseFlags('1.0.0-beta.10', 'supported'), ['--latest']);
	assert.deepEqual(releaseFlags('1.0.0-beta.11', 'supported'), ['--latest']);
});

test('preview beta and rc versions are prereleases', () => {
	assert.deepEqual(releaseFlags('1.0.0-beta.11', 'preview'), ['--prerelease']);
	assert.deepEqual(releaseFlags('1.0.0-rc.1', 'preview'), ['--prerelease']);
});

test('stable versions use the stable latest channel', () => {
	assert.deepEqual(releaseFlags('1.0.0', 'stable'), ['--latest']);
});

test('release notes declare one recognized channel', () => {
	assert.equal(releaseChannelFromNotes('Release channel: `supported`\n'), 'supported');
	assert.throws(() => releaseChannelFromNotes('# Missing'), /release channel/i);
	assert.throws(() => releaseChannelFromNotes('Release channel: `other`'), /release channel/i);
});

test('missing and incompatible release channels fail closed', () => {
	assert.throws(() => releaseFlags('1.0.0-beta.10', ''), /release channel/i);
	assert.throws(() => releaseFlags('1.0.0-beta.10', 'stable'), /incompatible/i);
	assert.throws(() => releaseFlags('1.0.0', 'preview'), /incompatible/i);
	assert.throws(() => releaseFlags('1.0.0', 'supported'), /incompatible/i);
});

test('malformed versions fail closed', () => {
	assert.throws(() => releaseFlags('v1.0.0', 'stable'), /semantic version/i);
	assert.throws(() => releaseFlags('latest', 'stable'), /semantic version/i);
	assert.throws(() => releaseFlags('1.0', 'stable'), /semantic version/i);
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

test('README exposes one validated supported public beta path', () => {
	assert.match(readme, /<!-- supported-release:start -->/);
	assert.match(readme, /<!-- supported-release:end -->/);
	assert.match(readme, /Current release: 1\.0\.0-beta\.11 — Public Beta/);
	assert.match(readme, /releases\/tag\/v1\.0\.0-beta\.11/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.11\/stonewright-1\.0\.0-beta\.11\.zip/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.11\/stonewright-companion-1\.0\.0-beta\.11\.tgz/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.11\/SHA256SUMS\.txt/);
	assert.doesNotMatch(readme, /1\.0\.0-beta\.11.*not released/i);
	assert.match(readme, /docs\/installation\.md/);
	assert.match(docsFreshness, /supported-release:start/);
});
