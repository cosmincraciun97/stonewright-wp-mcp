import { beforeEach, describe, expect, it, vi } from 'vitest';
import { resetBlockRegistryCacheForTests } from '../src/direct/block-registry.js';
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

describe('gutenberg validate parses the blocks', () => {
	beforeEach(() => resetBlockRegistryCacheForTests());

	function ctxFor(raw: string, registry: unknown = new Error('no registry')) {
		const get = vi.fn((path: string) => {
			if (path.includes('/block-types')) {
				return registry instanceof Error ? Promise.reject(registry) : Promise.resolve(registry);
			}
			return Promise.resolve({ id: 3, content: { raw, rendered: '<p>rendered</p>' } });
		});
		const ctx: DirectToolContext = { site, writeMode: 'on', client: { get } as never };
		return { ctx, get };
	}
	const para = (text: string) => `<!-- wp:paragraph -->\n<p>${text}</p>\n<!-- /wp:paragraph -->`;

	it('passes a well-formed page and reports its blocks', async () => {
		const { ctx } = ctxFor(`${para('a')}\n\n<!-- wp:heading {"level":2} -->\n<h2 class="wp-block-heading">b</h2>\n<!-- /wp:heading -->`);
		const result = await gutenbergValidate(ctx, { post_id: 3, type: 'page' });
		expect(result).toMatchObject({ valid: true, has_blocks: true, block_count: 2, diagnostics: [], suspicious: [], custom_code: [] });
		expect(result.block_names).toEqual(['paragraph', 'heading']);
		expect(result.content_hash).toMatch(/^[a-f0-9]{64}$/);
	});

	it('reports attributes that are not valid JSON, with the line and the block', async () => {
		const { ctx } = ctxFor(`${para('a')}\n\n<!-- wp:heading {"level":2,} -->\n<h2>b</h2>\n<!-- /wp:heading -->`);
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		expect(result.valid).toBe(false);
		expect(result.diagnostics).toEqual([
			expect.objectContaining({ severity: 'error', code: 'invalid_attributes', block: 'core/heading', line: 5 }),
		]);
		expect(result.suspicious.some((line) => /valid JSON/.test(line))).toBe(true);
	});

	it('reports a close that names the wrong block and an unclosed one', async () => {
		const { ctx } = ctxFor('<!-- wp:group -->\n<div>\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:group -->\n</div>');
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		expect(result.valid).toBe(false);
		expect(result.diagnostics.map((d) => d.code)).toContain('mismatched_close');
	});

	it('flags a block the site does not have, when the registry can be read', async () => {
		const { ctx, get } = ctxFor(`${para('a')}\n\n<!-- wp:acme/hero -->\n<div>x</div>\n<!-- /wp:acme/hero -->`, [{ name: 'core/paragraph' }, { name: 'core/group' }]);
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		expect(result.registry).toBe('checked');
		expect(result.unknown_blocks).toEqual(['acme/hero']);
		expect(result.diagnostics).toEqual([expect.objectContaining({ severity: 'warning', code: 'unknown_block', block: 'acme/hero' })]);
		// A warning does not make the markup invalid.
		expect(result.valid).toBe(true);
		expect(get.mock.calls.some(([path]) => String(path).includes('/block-types'))).toBe(true);
	});

	it('says so, and skips the check, when the registry cannot be read', async () => {
		const { ctx } = ctxFor(`${para('a')}\n\n<!-- wp:acme/hero -->\n<div>x</div>\n<!-- /wp:acme/hero -->`, new Error('forbidden'));
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		expect(result.registry).toBe('unavailable');
		expect(result.unknown_blocks).toEqual([]);
		expect(result.valid).toBe(true);
	});

	it('runs the custom-code guard over html and classic blocks and over block attributes', async () => {
		const raw = [
			'<!-- wp:html -->\n<script>track()</script>\n<!-- /wp:html -->',
			'<!-- wp:paragraph -->\n<p onclick="x()">a</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:button {"url":"javascript:alert(1)"} -->\n<div class="wp-block-button"><a class="wp-block-button__link">b</a></div>\n<!-- /wp:button -->',
			'<style>.x{color:red}</style>',
		].join('\n\n');
		const { ctx } = ctxFor(raw);
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		const found = result.custom_code.map((finding) => `${finding.kind}@${finding.path}`);
		expect(found).toEqual(expect.arrayContaining(['script@block 0', 'event_handler@block 1', 'javascript_url@block 2', 'style@block 3']));
		expect(result.suspicious.some((line) => /custom code/i.test(line))).toBe(true);
	});

	it('does not call a page with only ordinary markup custom code', async () => {
		const { ctx } = ctxFor('<!-- wp:html -->\n<div class="card"><a href="https://example.com">x</a></div>\n<!-- /wp:html -->');
		const result = await gutenbergValidate(ctx, { post_id: 3 });
		expect(result.custom_code).toEqual([]);
	});
});
