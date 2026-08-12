#!/usr/bin/env node

import process from 'node:process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const semver = /^\d+\.\d+\.\d+(?:-([0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*))?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/;
const channels = new Set(['supported', 'preview', 'stable']);

export function releaseChannelFromNotes(notes) {
	const match = /^Release channel: `([^`]+)`$/m.exec(notes);
	if (!match || !channels.has(match[1])) {
		throw new Error('Release notes require one valid release channel: supported, preview, or stable.');
	}
	return match[1];
}

export function releaseFlags(version, channel) {
	const match = semver.exec(version);
	if (!match) {
		throw new Error(`Expected a semantic version without a v prefix; received "${version}".`);
	}
	if (!channels.has(channel)) {
		throw new Error('Expected release channel: supported, preview, or stable.');
	}
	const prerelease = Boolean(match[1]);
	if ((channel === 'stable' && prerelease) || (channel !== 'stable' && !prerelease)) {
		throw new Error(`Release channel "${channel}" is incompatible with semantic version "${version}".`);
	}
	return [channel === 'preview' ? '--prerelease' : '--latest'];
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	try {
		const version = process.argv[2] ?? '';
		const notesPath = process.argv[3] ?? '';
		if (!notesPath) {
			throw new Error('Expected a versioned release notes path.');
		}
		const channel = releaseChannelFromNotes(readFileSync(notesPath, 'utf8'));
		process.stdout.write(`${releaseFlags(version, channel)[0]}\n`);
	} catch (error) {
		process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
		process.exitCode = 1;
	}
}
