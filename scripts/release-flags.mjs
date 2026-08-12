#!/usr/bin/env node

import process from 'node:process';
import { fileURLToPath } from 'node:url';

const semver = /^\d+\.\d+\.\d+(?:-([0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*))?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/;

export function releaseFlags(version) {
	const match = semver.exec(version);
	if (!match) {
		throw new Error(`Expected a semantic version without a v prefix; received "${version}".`);
	}
	return [match[1] ? '--prerelease' : '--latest'];
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	try {
		const version = process.argv[2] ?? '';
		process.stdout.write(`${releaseFlags(version)[0]}\n`);
	} catch (error) {
		process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
		process.exitCode = 1;
	}
}
