#!/usr/bin/env node
/**
 * Regenerate companion/skills-builtin/manifest.json.
 *
 * The manifest lists, for every packaged built-in skill, the hash of the text this
 * release ships and the hashes of the texts earlier releases shipped. The companion
 * replaces an installed copy only when it matches one of the earlier hashes, so a
 * copy the user edited is never overwritten.
 *
 * Run it after changing any file in `skills-builtin/`:
 *   npm run skills:manifest   (from companion/)
 *
 * When a file's text changed, its previous hash moves into `previous`; nothing
 * is ever removed from `previous`.
 */

import { createHash } from 'node:crypto';
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const dir = join(here, '..', 'skills-builtin');
const manifestPath = join(dir, 'manifest.json');

const hash = (text) => createHash('sha256').update(text.replace(/\r\n/g, '\n')).digest('hex');

const existing = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : { version: 1, files: {} };
const files = {};
for (const name of readdirSync(dir).filter((n) => n.endsWith('.md')).sort()) {
	const current = hash(readFileSync(join(dir, name), 'utf8'));
	const before = existing.files?.[name];
	const previous = [...(before?.previous ?? [])];
	if (before && before.sha256 !== current && !previous.includes(before.sha256)) {
		previous.push(before.sha256);
	}
	files[name] = { sha256: current, previous: previous.filter((h) => h !== current) };
}

writeFileSync(manifestPath, `${JSON.stringify({ version: 1, files }, null, '\t')}\n`, 'utf8');
console.log(`Wrote ${Object.keys(files).length} entries to ${manifestPath}`);
