import { describe, expect, it } from 'vitest';
import {
	addressableBlocks,
	blockAt,
	countBlocks,
	parseBlockMarkup,
	serializeBlocks,
	serializeBlockAttributes,
	shortBlockName,
} from '../src/direct/block-markup.js';

const para = (text: string) => `<!-- wp:paragraph -->\n<p>${text}</p>\n<!-- /wp:paragraph -->`;
const group = (...inner: string[]) =>
	`<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">\n\n${inner.join('\n\n')}\n\n</div>\n<!-- /wp:group -->`;

const codes = (markup: string) => parseBlockMarkup(markup).diagnostics.map((d) => d.code);

describe('parseBlockMarkup', () => {
	it('parses blocks, nesting and attributes', () => {
		const markup = `${para('one')}\n\n${group(para('two'), '<!-- wp:separator /-->')}`;
		const { blocks, diagnostics } = parseBlockMarkup(markup);
		expect(diagnostics).toEqual([]);
		const root = addressableBlocks(blocks);
		expect(root.map((b) => b.name)).toEqual(['core/paragraph', 'core/group']);
		expect(root[1]?.attrs).toEqual({ layout: { type: 'constrained' } });
		expect(root[1]?.innerBlocks.map((b) => b.name)).toEqual(['core/paragraph', 'core/separator']);
		expect(root[1]?.innerBlocks[1]?.selfClosing).toBe(true);
		expect(countBlocks(blocks)).toBe(4);
		expect(blockAt(blocks, [1, 0])?.innerHTML).toContain('two');
		expect(blockAt(blocks, [1, 2])).toBeNull();
	});

	it('keeps a namespaced block name and shortens only core', () => {
		const { blocks } = parseBlockMarkup('<!-- wp:acme/hero {"a":1} -->\n<div>x</div>\n<!-- /wp:acme/hero -->');
		expect(blocks[0]?.name).toBe('acme/hero');
		expect(shortBlockName('core/paragraph')).toBe('paragraph');
		expect(shortBlockName('acme/hero')).toBe('acme/hero');
	});

	it('does not address blank text between root blocks, and still writes it back', () => {
		const markup = `${para('one')}\n\n${para('two')}\n`;
		const { blocks } = parseBlockMarkup(markup);
		expect(addressableBlocks(blocks)).toHaveLength(2);
		expect(serializeBlocks(blocks)).toBe(markup);
	});

	it('treats text outside any block as a classic (freeform) block', () => {
		const { blocks } = parseBlockMarkup(`<p>Loose text</p>\n\n${para('one')}`);
		const root = addressableBlocks(blocks);
		expect(root[0]?.name).toBeNull();
		expect(root[0]?.innerHTML).toContain('Loose text');
	});

	it('reads a plain HTML comment as markup, not as a block', () => {
		const { blocks, diagnostics } = parseBlockMarkup('<!-- just a note -->\n<p>x</p>');
		expect(diagnostics).toEqual([]);
		expect(addressableBlocks(blocks)[0]?.name).toBeNull();
	});

	it('round-trips what it parsed, byte for byte', () => {
		const markup = `${para('one')}\n\n${group(para('two'))}\n\n<!-- wp:html -->\n<div class="x">a --&gt; b</div>\n<!-- /wp:html -->\n`;
		expect(serializeBlocks(parseBlockMarkup(markup).blocks)).toBe(markup);
	});

	describe('diagnostics', () => {
		it('reports a block that is never closed, with its line', () => {
			const markup = `${para('one')}\n\n<!-- wp:heading -->\n<h2>x</h2>\n`;
			const found = parseBlockMarkup(markup).diagnostics;
			expect(found).toHaveLength(1);
			expect(found[0]).toMatchObject({ severity: 'error', code: 'unclosed_block', block: 'core/heading', line: 5 });
			expect(found[0]?.message).toMatch(/unbalanced/i);
		});

		it('reports a close with no open block', () => {
			expect(codes('<p>x</p>\n<!-- /wp:paragraph -->')).toEqual(['unexpected_close']);
		});

		it('reports a close that names another block', () => {
			const markup = '<!-- wp:group -->\n<div>\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:group -->\n</div>\n<!-- /wp:paragraph -->';
			const found = codes(markup);
			expect(found).toContain('mismatched_close');
		});

		it('reports attributes that are not valid JSON', () => {
			const found = parseBlockMarkup('<!-- wp:paragraph {"align":"center",} -->\n<p>x</p>\n<!-- /wp:paragraph -->').diagnostics;
			expect(found.map((d) => d.code)).toEqual(['invalid_attributes']);
			expect(found[0]?.block).toBe('core/paragraph');
		});

		it('reports attributes that are JSON but not an object', () => {
			expect(codes('<!-- wp:paragraph [1,2] -->\n<p>x</p>\n<!-- /wp:paragraph -->')).toEqual(['invalid_attributes']);
		});

		it('reports a block comment that is not shaped like one, and a comment that never ends', () => {
			expect(codes('<!-- wp:Paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->')).toContain('malformed_block_comment');
			expect(codes('<p>x</p><!-- wp:paragraph')).toEqual(['unterminated_comment']);
		});

		it('accepts attributes that hold a closing-comment lookalike, escaped as WordPress writes them', () => {
			const markup = '<!-- wp:paragraph {"note":"a \\u002d\\u002d\\u003e b"} -->\n<p>x</p>\n<!-- /wp:paragraph -->';
			const { blocks, diagnostics } = parseBlockMarkup(markup);
			expect(diagnostics).toEqual([]);
			expect(blocks[0]?.attrs).toEqual({ note: 'a --> b' });
		});

		it('a self-closing block needs no close', () => {
			expect(parseBlockMarkup('<!-- wp:separator /-->\n<!-- wp:spacer {"height":"20px"} /-->').diagnostics).toEqual([]);
		});
	});
});

describe('serializeBlockAttributes', () => {
	it('writes attributes the way WordPress does', () => {
		expect(serializeBlockAttributes({ url: 'https://example.com/a?b=1&c=2', t: '<b>x</b>', q: 'say "hi"', d: 'a--b' })).toBe(
			'{"url":"https://example.com/a?b=1\\u0026c=2","t":"\\u003cb\\u003ex\\u003c/b\\u003e","q":"say \\u0022hi\\u0022","d":"a\\u002d\\u002db"}',
		);
	});

	it('leaves unicode and slashes as they are', () => {
		expect(serializeBlockAttributes({ a: 'café/é' })).toBe('{"a":"café/é"}');
	});
});

describe('serializeBlocks after a change', () => {
	it('re-writes only the block that changed', () => {
		const markup = `${para('one')}\n\n${para('two')}`;
		const { blocks } = parseBlockMarkup(markup);
		const second = addressableBlocks(blocks)[1];
		if (!second) throw new Error('missing block');
		second.innerHTML = '\n<p>changed</p>\n';
		second.innerContent = ['\n<p>changed</p>\n'];
		second.dirty = true;
		expect(serializeBlocks(blocks)).toBe(`${para('one')}\n\n<!-- wp:paragraph -->\n<p>changed</p>\n<!-- /wp:paragraph -->`);
	});

	it('re-writes a parent around untouched children', () => {
		const markup = group(para('a'), para('b'));
		const { blocks } = parseBlockMarkup(markup);
		const root = addressableBlocks(blocks)[0];
		if (!root) throw new Error('missing block');
		root.attrs = { layout: { type: 'default' } };
		root.dirty = true;
		const out = serializeBlocks(blocks);
		expect(out).toContain('<!-- wp:group {"layout":{"type":"default"}} -->');
		expect(out).toContain(para('a'));
		expect(out).toContain(para('b'));
		expect(parseBlockMarkup(out).diagnostics).toEqual([]);
	});
});
