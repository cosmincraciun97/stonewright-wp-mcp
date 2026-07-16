import { describe, expect, it, vi } from 'vitest';
import { gutenbergValidate } from '../src/direct/tools/gutenberg-validate.js';
import type { DirectToolContext } from '../src/direct/tools/types.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 't',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'a',
	appPassword: 'b',
	disabledTools: [],
};

describe('gutenberg validate', () => {
	it('flags unbalanced block comments', async () => {
		const raw =
			'<!-- wp:paragraph -->\n<p>Hi</p>\n<!-- /wp:paragraph -->\n<!-- wp:heading -->\n<h2>X</h2>\n';
		const ctx: DirectToolContext = {
			site,
			writeMode: 'on',
			client: {
				get: vi.fn(() =>
					Promise.resolve({
						id: 3,
						content: { raw, rendered: '<p>Hi</p><h2>X</h2>' },
					}),
				),
			} as never,
		};
		const result = await gutenbergValidate(ctx, { post_id: 3, type: 'page' });
		expect(result.has_blocks).toBe(true);
		expect(result.block_names).toEqual(expect.arrayContaining(['paragraph', 'heading']));
		expect(result.suspicious.some((s) => s.includes('unbalanced'))).toBe(true);
	});

	it('flags empty rendered with non-empty raw', async () => {
		const ctx: DirectToolContext = {
			site,
			writeMode: 'on',
			client: {
				get: vi.fn(() =>
					Promise.resolve({
						id: 1,
						content: { raw: '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', rendered: '' },
					}),
				),
			} as never,
		};
		const result = await gutenbergValidate(ctx, { post_id: 1 });
		expect(result.suspicious.some((s) => s.includes('rendered output empty'))).toBe(true);
	});
});
