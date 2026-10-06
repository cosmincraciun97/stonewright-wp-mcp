#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const errors = [];
const canonicalGplSha256 = 'edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6';

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
// A Windows checkout may convert line endings; the canonical text uses LF.
const rootHash = createHash('sha256').update(rootLicense.replace(/\r\n/g, '\n')).digest('hex');
if (rootHash !== canonicalGplSha256) {
	errors.push(`LICENSE must be the unmodified GNU GPL v2 text (sha256 ${canonicalGplSha256})`);
}

const companionLicense = read('companion/LICENSE');
for (const marker of ['MIT License', 'Permission is hereby granted, free of charge', 'THE SOFTWARE IS PROVIDED "AS IS"']) {
	if (!companionLicense.includes(marker)) errors.push(`companion/LICENSE is missing MIT marker: ${marker}`);
}

const licensing = read('LICENSING.md');
for (const marker of ['Plugin', 'Visual', 'GPL-2.0-or-later', 'Companion', 'MIT', 'third-party']) {
	if (!licensing.includes(marker)) errors.push(`LICENSING.md is missing component marker: ${marker}`);
}

const plugin = json('plugin/composer.json');
const companion = json('companion/package.json');
const visual = json('visual/package.json');
if (plugin.license !== 'GPL-2.0-or-later') errors.push('plugin/composer.json license must be GPL-2.0-or-later');
if (visual.license !== 'GPL-2.0-or-later') errors.push('visual/package.json license must be GPL-2.0-or-later');
if (companion.license !== 'MIT') errors.push('companion/package.json license must be MIT');

if (errors.length > 0) {
	for (const error of errors) process.stderr.write(`- ${error}\n`);
	process.exit(1);
}

process.stdout.write('License metadata verified: Plugin/Visual GPL-2.0-or-later; companion MIT.\n');
