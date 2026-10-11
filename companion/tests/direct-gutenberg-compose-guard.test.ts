import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { gutenbergCompose, type GutenbergBlockSpec } from '../src/direct/tools/gutenberg-compose.js';
import { parseBlockMarkup } from '../src/direct/block-markup.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('gutenberg-compose checks what it passes through', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-compose-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});

	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		rmSync(stateDir, { recursive: true, force: true });
	});

	const compose = (...blocks: GutenbergBlockSpec[]) => gutenbergCompose({ site }, { blocks }) as Record<string, unknown>;

	it.each([
		['script in an html block', { type: 'html', content: '<script>track()</script>' }, 'script'],
		['style in an html block', { type: 'html', content: '<style>.a{color:red}</style>' }, 'style'],
		['iframe in an html block', { type: 'html', content: '<iframe src="https://example.com"></iframe>' }, 'iframe'],
		['inline handler in an html block', { type: 'html', content: '<img src="a.png" onerror="x()">' }, 'event_handler'],
		['javascript URL in an html block', { type: 'html', content: '<a href="javascript:x()">go</a>' }, 'javascript_url'],
		['script in raw markup', { type: 'raw', markup: '<!-- wp:html -->\n<script>x()</script>\n<!-- /wp:html -->' }, 'script'],
		['handler in raw markup', { type: 'raw', markup: '<!-- wp:paragraph -->\n<p onclick="x()">a</p>\n<!-- /wp:paragraph -->' }, 'event_handler'],
		['raw markup outside any block', { type: 'raw', markup: '<style>a{}</style>' }, 'style'],
	] as Array<[string, GutenbergBlockSpec, string]>)('refuses %s with the custom-code envelope and returns no markup', (_label, spec, kind) => {
		const out = compose({ type: 'paragraph', content: 'fine' }, spec);
		expect(out).toMatchObject({
			supported: false,
			approval_required: true,
			agent_must_stop: true,
			error: 'custom_code_approval_required',
			tool: 'stonewright-gutenberg-compose',
			written: false,
		});
		expect((out['findings'] as Array<{ kind: string }>).map((finding) => finding.kind)).toContain(kind);
		expect(out).not.toHaveProperty('markup');
		const audit = readFileSync(join(stateDir, 'audit-direct.jsonl'), 'utf8');
		expect(audit).toContain('custom_code_approval_required');
	});

	it('passes ordinary html and raw markup through unchanged', () => {
		const out = compose(
			{ type: 'html', content: '<div class="card"><a href="https://example.com">More</a></div>' },
			{ type: 'raw', markup: '<!-- wp:paragraph -->\n<p>Plain</p>\n<!-- /wp:paragraph -->' },
		);
		expect(out['block_count']).toBe(2);
		expect(String(out['markup'])).toContain('<div class="card">');
		expect(parseBlockMarkup(String(out['markup'])).diagnostics).toEqual([]);
	});

	it('refuses raw markup whose block comments do not balance, and names the problem', () => {
		expect(() => compose({ type: 'raw', markup: '<!-- wp:group -->\n<div>\n<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:group -->' })).toThrow(/unbalanced|mismatch/i);
	});

	it('refuses raw markup with attributes that are not valid JSON', () => {
		expect(() => compose({ type: 'raw', markup: '<!-- wp:heading {"level":2,} -->\n<h2>x</h2>\n<!-- /wp:heading -->' })).toThrow(/valid JSON/i);
	});

	it('does not let html content open or close block comments', () => {
		expect(() => compose({ type: 'html', content: '</div><!-- /wp:html --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:html -->' })).toThrow(/block comment/i);
	});

	it('keeps an attribute value from ending the block comment', () => {
		const out = compose({ type: 'heading', content: 'x', align: 'center --> <p>injected</p> <!--' });
		const parsed = parseBlockMarkup(String(out['markup']));
		expect(parsed.diagnostics).toEqual([]);
		expect(String(out['markup'])).not.toContain('--> <p>injected');
	});
});
