import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

interface CompanionPackageJson {
	files?: string[];
	scripts?: Record<string, string>;
}

const companionRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const packageJson = JSON.parse(
	readFileSync(join(companionRoot, 'package.json'), 'utf8'),
) as CompanionPackageJson;

describe('package startup safety', () => {
	it('keeps the published MCP entrypoint executable through npm bin shims', () => {
		const sourceEntry = readFileSync(join(companionRoot, 'src', 'index.ts'), 'utf8');
		expect(sourceEntry.startsWith('#!/usr/bin/env node\n')).toBe(true);
	});

	it('builds dist before packing release tarballs', () => {
		expect(packageJson.files).toContain('dist');
		expect(packageJson.scripts?.prepack).toContain('npm run build');
	});

	it('offers a source MCP command that rebuilds before launching dist/index.js', () => {
		expect(packageJson.scripts?.['mcp:source']).toContain('npm run build');
		expect(packageJson.scripts?.['mcp:source']).toContain('node dist/index.js');
	});
});
