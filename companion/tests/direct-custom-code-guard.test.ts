import { describe, expect, it } from 'vitest';
import {
	customCodeRefusal,
	isCustomCssKey,
	isHtmlWidgetType,
	scanElementorTree,
	scanGlobalStyles,
	scanMarkup,
	scanMeta,
} from '../src/direct/custom-code-guard.js';

describe('scanMarkup', () => {
	it.each([
		['<script>alert(1)</script>', 'script'],
		['<SCRIPT src="x.js"></SCRIPT>', 'script'],
		['<p>hi</p><ScRiPt\n>x</ScRiPt>', 'script'],
		['<style>body{display:none}</style>', 'style'],
		['<STYLE media="print">a{}</STYLE>', 'style'],
		['<iframe src="https://example.com"></iframe>', 'iframe'],
		['<IFrame\tsrc=x>', 'iframe'],
		['<img src="a.png" onerror="alert(1)">', 'event_handler'],
		['<a href="/x" ONCLICK="go()">x</a>', 'event_handler'],
		['<div onmouseover=go()>x</div>', 'event_handler'],
		['<svg/onload=alert(1)>', 'event_handler'],
		['<a title="a>b" onclick="x">x</a>', 'event_handler'],
		['<a href="javascript:alert(1)">x</a>', 'javascript_url'],
		['<a href="JaVaScRiPt:alert(1)">x</a>', 'javascript_url'],
		['<a href=" \tjava\nscript:alert(1)">x</a>', 'javascript_url'],
		['<a href="&#106;avascript:alert(1)">x</a>', 'javascript_url'],
		['<a href="&#x6A;&#x61;vascript:alert(1)">x</a>', 'javascript_url'],
		['<a href="&#106avascript:alert(1)">x</a>', 'javascript_url'],
		['<a href="javascript&colon;alert(1)">x</a>', 'javascript_url'],
		['<a href="java&Tab;script:alert(1)">x</a>', 'javascript_url'],
		["<a href='javascript:void(0)'>x</a>", 'javascript_url'],
		['<a href=javascript:alert(1)>x</a>', 'javascript_url'],
		['<form action="javascript:go()"></form>', 'javascript_url'],
		['<object data="a.swf"></object>', 'object'],
		['<OBJECT\tdata=x>', 'object'],
		['<embed src="a.swf">', 'embed'],
		['<EmBeD\nsrc=x/>', 'embed'],
		['<link rel="stylesheet" href="https://example.com/a.css">', 'link'],
		['<LINK\nrel=stylesheet href=x>', 'link'],
		['<link/rel=stylesheet href=x>', 'link'],
		['<meta http-equiv="refresh" content="0;url=https://example.com">', 'meta'],
		['<META HTTP-EQUIV=refresh content=0>', 'meta'],
		['<meta\n  http-equiv = "refresh"\n content="1">', 'meta'],
		['<meta content="a>b" http-equiv="refresh">', 'meta'],
		['<!-- wp:custom {"html":"\\u003cembed src=x\\u003e"} /-->', 'embed'],
	])('detects %j as %s', (markup, kind) => {
		expect(scanMarkup(markup)).toContain(kind);
	});

	it('does not flag look-alike names or a meta tag without http-equiv', () => {
		const ordinary = [
			'<meta name="viewport" content="width=device-width">',
			'<meta charset="utf-8">',
			'<p>Use a <linkable>x</linkable> or <objective>y</objective> or <embedded>z</embedded>.</p>',
			'<link-card href="/a">x</link-card>',
			'<p>Escaped &lt;embed src=x&gt; and &lt;link rel=x&gt; stay text.</p>',
		];
		for (const markup of ordinary) {
			expect(scanMarkup(markup), markup).toEqual([]);
		}
	});

	it('finds an element hidden in a quoted attribute value', () => {
		expect(scanMarkup('<div data-x="<iframe src=x>">y</div>')).toContain('iframe');
		expect(scanMarkup('<div title="<img onerror=alert(1)>">y</div>')).toContain('event_handler');
	});

	it('finds code inside block comment attributes, including JSON unicode escapes', () => {
		const escaped = '<!-- wp:custom {"html":"\\u003cscript\\u003ealert(1)\\u003c/script\\u003e"} /-->';
		expect(scanMarkup(escaped)).toContain('script');
		const url = '<!-- wp:navigation-link {"label":"Go","url":"javascript:alert(1)"} /-->';
		expect(scanMarkup(url)).toContain('javascript_url');
		const nested = '<!-- wp:group {"a":{"b":["<style>x{}</style>"]}} --><div></div><!-- /wp:group -->';
		expect(scanMarkup(nested)).toContain('style');
		const entityUrl = '<!-- wp:navigation-link {"url":"&#106;avascript:alert(1)"} /-->';
		expect(scanMarkup(entityUrl)).toContain('javascript_url');
	});

	it('reports a block comment that is not valid JSON conservatively when it carries a script URL', () => {
		expect(scanMarkup('<!-- wp:x {"url":"javascript:alert(1)", } /-->')).toContain('javascript_url');
	});

	it('does not flag ordinary content', () => {
		const ordinary = [
			'<!-- wp:paragraph --><p>Hello <a href="https://example.com/about?x=1&amp;y=2">world</a></p><!-- /wp:paragraph -->',
			'<!-- wp:image {"id":5,"sizeSlug":"large","linkDestination":"none"} --><figure class="wp-block-image"><img src="/a.png" alt="A" class="wp-image-5"/></figure><!-- /wp:image -->',
			'<!-- wp:heading {"level":2,"textColor":"primary","style":{"typography":{"fontSize":"2rem"}}} --><h2 class="wp-block-heading has-primary-color">Title</h2><!-- /wp:heading -->',
			'<!-- wp:button {"backgroundColor":"accent"} --><div class="wp-block-button"><a class="wp-block-button__link" href="#contact" title="One">Online</a></div><!-- /wp:button -->',
			'<p>We talk about javascript: the language, and online=1 and onion=2.</p>',
			'<p>Escaped &lt;script&gt;alert(1)&lt;/script&gt; and &lt;iframe&gt; stay text.</p>',
			'<a href="mailto:a@example.com">mail</a><a href="tel:+40123">tel</a><a href="/relative">r</a><a href="data-x">d</a>',
			'<p>5 < 6 and 7 > 3, a <b>bold</b> it\'s fine</p>',
			'<video src="/a.mp4" controls></video><svg viewBox="0 0 1 1"><path d="M0 0"/></svg>',
			'',
		];
		for (const markup of ordinary) {
			expect(scanMarkup(markup), markup).toEqual([]);
		}
	});

	it('stays linear on hostile input', () => {
		const hostile = '<a "'.repeat(50_000) + '<a \''.repeat(50_000);
		const started = Date.now();
		scanMarkup(hostile);
		expect(Date.now() - started).toBeLessThan(2000);
	});
});

describe('key and widget predicates', () => {
	it.each([
		'custom_css',
		'_custom_css',
		'customcss',
		'page_custom_css',
		'kit_custom_css',
		'additional_custom_css',
		'custom_css_pro',
		'CUSTOM_CSS',
		'custom-css',
		'custom_css_mobile',
		'_custom_css_tablet',
	])('treats %s as a custom CSS key', (key) => {
		expect(isCustomCssKey(key)).toBe(true);
	});

	it.each(['css', 'color', 'custom_cssx', 'my_custom_css', 'background_color', '_css_classes', 'css_classes'])(
		'does not treat %s as a custom CSS key',
		(key) => {
			expect(isCustomCssKey(key)).toBe(false);
		},
	);

	it.each(['html', 'HTML', ' html ', 'raw-html', 'raw_html', 'text-path', 'e-html', 'custom-html-widget'])(
		'treats %s as an HTML widget type',
		(type) => {
			expect(isHtmlWidgetType(type)).toBe(true);
		},
	);

	it.each(['heading', 'text-editor', 'image', 'button', 'e-heading', ''])('does not treat %s as an HTML widget type', (type) => {
		expect(isHtmlWidgetType(type)).toBe(false);
	});
});

describe('scanElementorTree', () => {
	const section = (children: unknown[]) => [
		{ id: 'a1', elType: 'container', settings: {}, elements: children },
	];

	it('flags an html widget at any depth', () => {
		const tree = section([
			{ id: 'b1', elType: 'container', elements: [{ id: 'c1', elType: 'widget', widgetType: 'html', settings: { html: '<p>x</p>' } }] },
		]);
		const found = scanElementorTree(tree);
		expect(found.map((f) => f.kind)).toContain('html_widget');
	});

	it('flags custom CSS keys on any element, nested settings included', () => {
		const tree = section([
			{ id: 'b1', elType: 'widget', widgetType: 'heading', settings: { title: 'x', custom_css: 'selector{color:red}' } },
			{ id: 'b2', elType: 'widget', widgetType: 'heading', settings: { styles: { variants: [{ _custom_css: 'a{}' }] } } },
		]);
		const found = scanElementorTree(tree);
		expect(found.filter((f) => f.kind === 'custom_css_key')).toHaveLength(2);
	});

	it('ignores placeholders that hold no CSS', () => {
		const tree = section([
			{ id: 'b1', elType: 'widget', widgetType: 'heading', settings: { custom_css: null, _custom_css: '', page_custom_css: '  ', kit_custom_css: [], customcss: {} } },
		]);
		expect(scanElementorTree(tree)).toEqual([]);
	});

	it('flags code carried in a normal text setting', () => {
		const tree = section([
			{ id: 'b1', elType: 'widget', widgetType: 'text-editor', settings: { editor: '<p>x</p><script>alert(1)</script>' } },
			{ id: 'b2', elType: 'widget', widgetType: 'button', settings: { link: { url: 'javascript:alert(1)' } } },
		]);
		const kinds = scanElementorTree(tree).map((f) => f.kind);
		expect(kinds).toContain('script');
		expect(kinds).toContain('javascript_url');
	});

	it('passes an ordinary tree', () => {
		const tree = section([
			{ id: 'b1', elType: 'widget', widgetType: 'heading', settings: { title: 'Hello', title_color: '#112233', _css_classes: 'hero' } },
			{ id: 'b2', elType: 'widget', widgetType: 'text-editor', settings: { editor: '<p>Hi <a href="https://example.com">there</a></p>' } },
			{ id: 'b3', elType: 'widget', widgetType: 'image', settings: { image: { url: 'https://example.com/a.png', id: 5 } } },
		]);
		expect(scanElementorTree(tree)).toEqual([]);
	});

	it('reports paths that name the offending node id', () => {
		const tree = section([{ id: 'zz9', elType: 'widget', widgetType: 'html', settings: {} }]);
		expect(scanElementorTree(tree)[0]?.path).toContain('zz9');
	});
});

describe('scanGlobalStyles', () => {
	it('flags top-level styles.css and per-block css, at any depth', () => {
		const found = scanGlobalStyles({
			styles: {
				css: 'body{margin:0}',
				blocks: {
					'core/button': { css: '&:hover{opacity:.5}', variations: { outline: { css: 'a{}' } } },
				},
			},
		});
		expect(found.map((f) => f.path)).toEqual(
			expect.arrayContaining(['styles.css', 'styles.blocks.core/button.css', 'styles.blocks.core/button.variations.outline.css']),
		);
		expect(found.every((f) => f.kind === 'custom_css_key')).toBe(true);
	});

	it('flags the custom css key variants too', () => {
		expect(scanGlobalStyles({ styles: { custom_css: 'a{}' } })).toHaveLength(1);
	});

	it('passes presets, colors, typography and empty css', () => {
		expect(
			scanGlobalStyles({
				settings: { color: { palette: [{ slug: 'brand', color: '#112233', name: 'Brand' }] }, typography: { fontFamilies: [{ fontFamily: "'Inter', sans-serif", slug: 'inter' }] } },
				styles: {
					color: { background: 'var(--wp--preset--color--brand)' },
					typography: { fontSize: '1.125rem', lineHeight: '1.6' },
					elements: { link: { color: { text: '#0a58ca' } } },
					blocks: { 'core/heading': { typography: { fontWeight: '700' } } },
					css: '',
				},
			}),
		).toEqual([]);
	});

	it('flags a script URL value', () => {
		const found = scanGlobalStyles({ styles: { background: { backgroundImage: { url: 'javascript:alert(1)' } } } });
		expect(found.map((f) => f.kind)).toContain('javascript_url');
	});
});

describe('scanMeta', () => {
	it('flags markup and CSS keys carried in meta', () => {
		expect(scanMeta({ header: '<script>x</script>' }).map((f) => f.kind)).toContain('script');
		expect(scanMeta({ _elementor_page_settings: { custom_css: 'a{}' } }).map((f) => f.kind)).toContain('custom_css_key');
	});

	it('unpacks _elementor_data as an Elementor tree', () => {
		const tree = JSON.stringify([{ id: 'a', elType: 'widget', widgetType: 'html', settings: {} }]);
		expect(scanMeta({ _elementor_data: tree }).map((f) => f.kind)).toContain('html_widget');
	});

	it('passes ordinary meta', () => {
		expect(scanMeta({ subtitle: 'A <em>good</em> day', count: 3, flags: [true, false] })).toEqual([]);
	});
});

describe('customCodeRefusal', () => {
	it('uses the custom-css approval envelope and states that nothing was written', () => {
		const refusal = customCodeRefusal('stonewright-content-update', [{ kind: 'script', path: 'content' }]);
		expect(refusal).toMatchObject({
			supported: false,
			approval_required: true,
			agent_must_stop: true,
			error: 'custom_code_approval_required',
			tool: 'stonewright-content-update',
			written: false,
			findings: [{ kind: 'script', path: 'content' }],
		});
		expect(refusal.hint).toMatch(/Plugin mode/);
		expect(refusal.hint).toMatch(/custom-code approval/);
		expect(refusal.hint).toMatch(/Nothing was written/);
	});

	it('tells the agent that code already on the site cannot be saved back', () => {
		const hint = customCodeRefusal('stonewright-elementor-data-update', [{ kind: 'html_widget', path: 'x' }]).hint;
		expect(hint).toMatch(/already contains custom code/);
		expect(hint).toMatch(/HTML widget, custom CSS or inline script/);
		expect(hint).toMatch(/Direct cannot save/);
		expect(hint).toMatch(/ask a human/);
	});

	it('caps the findings it lists', () => {
		const many = Array.from({ length: 100 }, (_, i) => ({ kind: 'script' as const, path: `p${i}` }));
		expect(customCodeRefusal('t', many).findings.length).toBeLessThanOrEqual(20);
	});
});
