import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = join(import.meta.dirname, '..');

describe('companion architecture guard', () => {
	it('does not expose a WP-CLI bridge', () => {
		const indexSource = readFileSync(join(root, 'src', 'index.ts'), 'utf8');

		expect(indexSource).not.toContain('/wpcli');
		expect(indexSource).not.toContain('runWpCli');
		expect(existsSync(join(root, 'src', 'wpcli.ts'))).toBe(false);
	});
});
