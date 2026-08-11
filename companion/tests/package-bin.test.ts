import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

describe('companion CLI package aliases', () => {
	it('ships a short stonewright installer command without removing legacy bins', () => {
		const pkg = JSON.parse(readFileSync(join(process.cwd(), 'package.json'), 'utf8')) as {
			bin?: Record<string, string>;
		};
		expect(pkg.bin).toEqual(expect.objectContaining({
			stonewright: 'dist/index.js',
			'stonewright-companion': 'dist/index.js',
			'stonewright-mcp': 'dist/index.js',
		}));
	});
});
