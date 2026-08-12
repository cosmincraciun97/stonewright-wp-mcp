#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const errors = [];
const canonicalAgplSha256 = '0d96a4ff68ad6d4b6f1f30f713b18d5184912ba8dd389f86aa7710db079abcb0';

function read(relative) {
	const path = resolve(root, relative);
	if (!existsSync(path)) {
		errors.push(`${relative} is missing`);
		return '';
	}
	return readFileSync(path, 'utf8');
}

function json(relative) {
	try {
		return JSON.parse(read(relative));
	} catch {
		errors.push(`${relative} is not valid JSON`);
		return {};
	}
}

const rootLicense = read('LICENSE');
const rootHash = createHash('sha256').update(rootLicense).digest('hex');
if (rootHash !== canonicalAgplSha256) {
	errors.push(`LICENSE must be the unmodified GNU AGPL v3 text (sha256 ${canonicalAgplSha256})`);
}

const companionLicense = read('companion/LICENSE');
for (const marker of ['MIT License', 'Permission is hereby granted, free of charge', 'THE SOFTWARE IS PROVIDED "AS IS"']) {
	if (!companionLicense.includes(marker)) errors.push(`companion/LICENSE is missing MIT marker: ${marker}`);
}

const licensing = read('LICENSING.md');
for (const marker of ['Plugin', 'Visual', 'AGPL-3.0-or-later', 'Companion', 'MIT', 'third-party']) {
	if (!licensing.includes(marker)) errors.push(`LICENSING.md is missing component marker: ${marker}`);
}

const plugin = json('plugin/composer.json');
const companion = json('companion/package.json');
const visual = json('visual/package.json');
if (plugin.license !== 'AGPL-3.0-or-later') errors.push('plugin/composer.json license must be AGPL-3.0-or-later');
if (visual.license !== 'AGPL-3.0-or-later') errors.push('visual/package.json license must be AGPL-3.0-or-later');
if (companion.license !== 'MIT') errors.push('companion/package.json license must be MIT');

if (errors.length > 0) {
	for (const error of errors) process.stderr.write(`- ${error}\n`);
	process.exit(1);
}

process.stdout.write('License metadata verified: Plugin/Visual AGPL-3.0-or-later; companion MIT.\n');
