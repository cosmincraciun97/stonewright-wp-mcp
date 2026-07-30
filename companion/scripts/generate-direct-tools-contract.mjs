#!/usr/bin/env node
/**
 * Generate docs/contracts/direct-tools-v1.json from DIRECT_TOOL_NAMES.
 *
 * Usage:
 *   node companion/scripts/generate-direct-tools-contract.mjs
 *   npm run contracts:generate   (from companion/)
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const companionRoot = join(__dirname, '..');
const repoRoot = join(companionRoot, '..');
const registryPath = join(companionRoot, 'src', 'direct', 'registry.ts');
const outputPath = join(repoRoot, 'docs', 'contracts', 'direct-tools-v1.json');

/**
 * Expand a const string array that may spread other consts.
 * @param {string} source
 * @param {string} constName
 * @param {Map<string, string[]>} resolved
 * @returns {string[]}
 */
function resolveArrayConst(source, constName, resolved = new Map()) {
	if (resolved.has(constName)) {
		return resolved.get(constName) ?? [];
	}
	resolved.set(constName, []);

	const re = new RegExp(
		`(?:export\\s+)?const\\s+${constName}\\s*=\\s*\\[([\\s\\S]*?)\\]\\s*as\\s+const`,
		'm',
	);
	const match = source.match(re);
	if (!match) {
		return [];
	}

	const names = [];
	const body = match[1];
	for (const spread of body.matchAll(/\.\.\.([A-Z][A-Z0-9_]*)/g)) {
		names.push(...resolveArrayConst(source, spread[1], resolved));
	}
	for (const item of body.matchAll(/['"]([^'"]+)['"]/g)) {
		names.push(item[1]);
	}
	resolved.set(constName, names);
	return names;
}

/**
 * @param {string} path
 * @returns {{ version: number, allowlist: object, tools: Array<{name: string}> }}
 */
function loadExisting(path) {
	if (!existsSync(path)) {
		return {
			version: 1,
			allowlist: { removed: [], renamed: {}, schema_changes: [] },
			tools: [],
		};
	}
	try {
		const parsed = JSON.parse(readFileSync(path, 'utf8'));
		return {
			version: 1,
			allowlist: {
				removed: Array.isArray(parsed?.allowlist?.removed) ? parsed.allowlist.removed : [],
				renamed:
					parsed?.allowlist?.renamed && typeof parsed.allowlist.renamed === 'object'
						? parsed.allowlist.renamed
						: {},
				schema_changes: Array.isArray(parsed?.allowlist?.schema_changes)
					? parsed.allowlist.schema_changes
					: [],
			},
			tools: Array.isArray(parsed?.tools) ? parsed.tools : [],
		};
	} catch {
		return {
			version: 1,
			allowlist: { removed: [], renamed: {}, schema_changes: [] },
			tools: [],
		};
	}
}

function main() {
	if (!existsSync(registryPath)) {
		console.error(`ERROR: registry not found: ${registryPath}`);
		process.exit(1);
	}

	const source = readFileSync(registryPath, 'utf8');
	const names = [...new Set(resolveArrayConst(source, 'DIRECT_TOOL_NAMES'))].sort((a, b) =>
		a.localeCompare(b),
	);

	if (names.length === 0) {
		console.error('ERROR: DIRECT_TOOL_NAMES resolved to zero tools');
		process.exit(1);
	}

	const existing = loadExisting(outputPath);
	const document = {
		version: 1,
		generated_at: new Date().toISOString(),
		allowlist: existing.allowlist,
		tools: names.map((name) => ({ name })),
	};

	const outDir = dirname(outputPath);
	mkdirSync(outDir, { recursive: true });
	writeFileSync(outputPath, `${JSON.stringify(document, null, 2)}\n`, 'utf8');
	console.log(`Wrote ${names.length} direct tools to ${outputPath}`);
}

main();
