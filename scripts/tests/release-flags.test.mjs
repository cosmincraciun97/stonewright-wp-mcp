import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import {
	copyFileSync,
	existsSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test, { after, before, describe } from 'node:test';
import { fileURLToPath, pathToFileURL } from 'node:url';
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
	assert.deepEqual(releaseFlags('1.0.0-beta.13', 'supported'), ['--latest']);
	assert.deepEqual(releaseFlags('1.0.0-beta.13.1', 'supported'), ['--latest']);
	assert.deepEqual(releaseFlags('1.0.0-beta.13.3', 'supported'), ['--latest']);
});

test('preview beta and rc versions are prereleases', () => {
	assert.deepEqual(releaseFlags('1.0.0-beta.11', 'preview'), ['--prerelease']);
	assert.deepEqual(releaseFlags('1.0.0-beta.11.1', 'preview'), ['--prerelease']);
	assert.deepEqual(releaseFlags('1.0.0-rc.1', 'preview'), ['--prerelease']);
});

test('stable versions use the stable latest channel', () => {
	assert.deepEqual(releaseFlags('1.0.0', 'stable'), ['--latest']);
});

test('release notes declare one recognized channel', () => {
	assert.equal(releaseChannelFromNotes('Release channel: `supported`\n'), 'supported');
	assert.throws(() => releaseChannelFromNotes('# Missing'), /release channel/i);
	assert.throws(() => releaseChannelFromNotes('Release channel: `other`'), /release channel/i);
	assert.throws(() => releaseChannelFromNotes('Release channel: `supported`\nRelease channel: `supported`\n'), /release channel/i);
	assert.throws(() => releaseChannelFromNotes('Release channel: `supported`\nRelease channel: `preview`\n'), /release channel/i);
	assert.throws(() => releaseChannelFromNotes('Release channel: `supported`\nRelease channel: supported\n'), /release channel/i);
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
	assert.throws(() => releaseFlags('1.3.0-beta..30', 'preview'), /semantic version/i);
	assert.throws(() => releaseFlags('01.0.0', 'stable'), /semantic version/i);
	assert.throws(() => releaseFlags('1.0.0-beta.01', 'preview'), /semantic version/i);
	assert.throws(() => releaseFlags('vv1.3.0-beta.30', 'preview'), /semantic version/i);
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

test('release workflow publishes exactly zip, companion tgz, and SHA256SUMS', () => {
	assert.match(releaseWorkflow, /rm -f dist\/stonewright-visual-\*\.tgz/);
	assert.match(releaseWorkflow, /check-release-artifacts\.mjs/);
	assert.doesNotMatch(
		releaseWorkflow,
		/gh release create[\s\S]*stonewright-visual/,
	);
});

test('README exposes one validated supported public beta path', () => {
	assert.match(readme, /<!-- supported-release:start -->/);
	assert.match(readme, /<!-- supported-release:end -->/);
	assert.match(readme, /Current release: 1\.0\.0-beta\.14 — Public Beta/);
	assert.match(readme, /releases\/tag\/v1\.0\.0-beta\.14/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.14\/stonewright-1\.0\.0-beta\.14\.zip/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.14\/stonewright-companion-1\.0\.0-beta\.14\.tgz/);
	assert.match(readme, /releases\/download\/v1\.0\.0-beta\.14\/SHA256SUMS\.txt/);
	assert.doesNotMatch(readme, /1\.0\.0-beta\.14.*not released/i);
	assert.match(readme, /docs\/installation\.md/);
	assert.match(docsFreshness, /supported-release:start/);
});

const repoRoot = fileURLToPath(new URL('../../', import.meta.url));
const hygieneScript = fileURLToPath(new URL('../check-public-hygiene.mjs', import.meta.url));
const workflowFiles = ['.github/workflows', 'plugin/.github/workflows'].flatMap((directory) => {
	const absolute = path.join(repoRoot, directory);
	if (!existsSync(absolute)) return [];
	return readdirSync(absolute)
		.filter((name) => /\.ya?ml$/.test(name))
		.map((name) => `${directory}/${name}`);
});

function readWorkflow(file) {
	return readFileSync(path.join(repoRoot, file), 'utf8').replace(/\r\n/g, '\n');
}

function stepBlock(workflow, stepStart) {
	const lines = workflow.split('\n');
	const start = lines.findIndex((line) => stepStart.test(line));
	assert.notEqual(start, -1, `no workflow step matches ${stepStart}`);
	const indent = /^\s*/.exec(lines[start])[0].length;
	const block = [lines[start]];
	for (const line of lines.slice(start + 1)) {
		if (line.trim() !== '' && /^\s*/.exec(line)[0].length <= indent) break;
		block.push(line);
	}
	return block.join('\n');
}

function git(cwd, args) {
	const result = spawnSync(
		'git',
		[
			'-c',
			'user.name=Example Author',
			'-c',
			'user.email=author@example.test',
			'-c',
			'commit.gpgsign=false',
			...args,
		],
		{ cwd, encoding: 'utf8' },
	);
	assert.equal(result.status, 0, `git ${args.join(' ')} failed: ${result.stderr}`);
	return result.stdout;
}

describe('history hygiene scan', () => {
	const privateTerm = 'example-private-term';
	let directory;
	let fullClone;
	let shallowClone;

	function runScan(clone, flags) {
		return spawnSync(process.execPath, [path.join(clone, 'scripts', 'check-public-hygiene.mjs'), ...flags], {
			cwd: clone,
			encoding: 'utf8',
			env: { ...process.env, STONEWRIGHT_PRIVATE_TERMS: privateTerm },
		});
	}

	before(() => {
		directory = mkdtempSync(path.join(os.tmpdir(), 'stonewright-history-'));
		const origin = path.join(directory, 'origin');
		mkdirSync(path.join(origin, 'scripts'), { recursive: true });
		git(origin, ['init', '-q']);
		copyFileSync(hygieneScript, path.join(origin, 'scripts', 'check-public-hygiene.mjs'));
		writeFileSync(path.join(origin, 'notes.txt'), `${privateTerm}\n`);
		git(origin, ['add', '-A']);
		git(origin, ['commit', '-q', '-m', 'Add notes']);
		rmSync(path.join(origin, 'notes.txt'));
		git(origin, ['add', '-A']);
		git(origin, ['commit', '-q', '-m', 'Remove notes']);
		fullClone = path.join(directory, 'full');
		shallowClone = path.join(directory, 'shallow');
		git(directory, ['clone', '-q', pathToFileURL(origin).href, fullClone]);
		git(directory, ['clone', '-q', '--depth', '1', pathToFileURL(origin).href, shallowClone]);
	});

	after(() => {
		if (directory) rmSync(directory, { recursive: true, force: true });
	});

	test('reports a private term that only an older commit contains', () => {
		const result = runScan(fullClone, ['--require-private-terms', '--history']);
		assert.equal(result.status, 1, `${result.stdout}${result.stderr}`);
		assert.match(result.stderr, /Git history contains private term #1 in [1-9]\d* commit\(s\)/);
	});

	test('refuses a shallow clone instead of passing', () => {
		const result = runScan(shallowClone, ['--require-private-terms', '--history']);
		assert.equal(result.status, 2, `${result.stdout}${result.stderr}`);
		assert.match(result.stderr, /shallow/i);
		assert.doesNotMatch(result.stdout, /Public hygiene OK/);
	});

	test('still scans the working tree of a shallow clone without --history', () => {
		const result = runScan(shallowClone, ['--require-private-terms']);
		assert.equal(result.status, 0, `${result.stdout}${result.stderr}`);
		assert.match(result.stdout, /Public hygiene OK/);
	});
});

test('release workflow gives the history hygiene scan a full-history checkout', () => {
	const release = readWorkflow('.github/workflows/release.yml');
	assert.match(release, /check-public-hygiene\.mjs --require-private-terms --history/);
	const checkout = stepBlock(release, /^\s*- uses: actions\/checkout@/);
	assert.match(checkout, /^\s+fetch-depth: 0$/m);
});

test('release workflow grants write scope only to the publish job', () => {
	const [buildJobs, publishJob] = readWorkflow('.github/workflows/release.yml').split(/^ {2}publish:[ \t]*$/m);
	assert.ok(publishJob, 'release workflow needs a publish job');
	assert.match(buildJobs, /^permissions:\n {2}contents: read$/m);
	assert.doesNotMatch(buildJobs, /contents:\s*write/);
	assert.doesNotMatch(buildJobs, /gh release create/);
	assert.match(publishJob, /^ {4}permissions:\n {6}contents: write$/m);
	assert.match(publishJob, /check-release-artifacts\.mjs[\s\S]*gh release create/);
});

test('release runs are never cancelled in progress', () => {
	assert.match(
		readWorkflow('.github/workflows/release.yml'),
		/^concurrency:\n {2}group: release-\$\{\{ github\.ref \}\}\n {2}cancel-in-progress: false$/m,
	);
});

test('every workflow action is pinned to a full commit SHA with a version comment', () => {
	assert.ok(workflowFiles.length >= 2, 'expected the CI and release workflows');
	for (const file of workflowFiles) {
		const lines = readWorkflow(file).split('\n');
		for (const [index, line] of lines.entries()) {
			const match = /^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/.exec(line);
			if (!match || match[1].startsWith('./')) continue;
			assert.match(match[1], /^[\w.-]+\/[\w./-]+@[0-9a-f]{40}$/, `${file}:${index + 1} must pin ${match[1]} to a commit SHA`);
			assert.match(match[2], /^\s+#\s*v?\d+(?:\.\d+)*\s*$/, `${file}:${index + 1} needs a version comment`);
		}
	}
});

test('every workflow job declares a timeout and the token defaults to read-only', () => {
	for (const file of workflowFiles) {
		const workflow = readWorkflow(file);
		const jobs = workflow.split(/^jobs:[ \t]*$/m)[1] ?? '';
		const jobCount = (jobs.match(/^ {2}[a-z][\w-]*:[ \t]*$/gm) ?? []).length;
		const timeoutCount = (jobs.match(/^ {4}timeout-minutes: \d+$/gm) ?? []).length;
		assert.ok(jobCount > 0, `${file} has no jobs`);
		assert.equal(timeoutCount, jobCount, `${file} needs a timeout-minutes on every job`);
		assert.match(workflow, /^permissions:\n {2}contents: read$/m, `${file} needs read-only default permissions`);
	}
});
