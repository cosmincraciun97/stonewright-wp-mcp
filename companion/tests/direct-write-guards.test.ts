import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import {
	existsSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	statSync,
	writeFileSync,
} from 'node:fs';
import { createHash } from 'node:crypto';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { markTaskStartSeen, type DirectWriteMode } from '../src/direct/writes.js';
import {
	contentCreate,
	contentUpdate,
	contentAutosaveCreate,
} from '../src/direct/tools/content.js';
import {
	templateCreate,
	templatePartCreate,
	templatePartUpdate,
	templateUpdate,
} from '../src/direct/tools/templates.js';
import { globalStylesUpdate } from '../src/direct/tools/global-styles.js';
import { widgetManage } from '../src/direct/tools/widgets.js';
import { applyBlueprint, listBlueprints } from '../src/direct/tools/blueprints.js';
import { elementorDataUpdate } from '../src/direct/tools/elementor-direct.js';
import { scanMarkup } from '../src/direct/custom-code-guard.js';
import { serializeDesignSpec, type DesignSpec } from '../src/direct/gutenberg-serializer.js';
import { getBlueprint } from '../src/direct/tools/blueprints.js';
import type { WpCliCommandResult } from '../src/wp-cli.js';
import { createMcpServer } from '../src/mcp-server.js';

const APP_PASSWORD = 'fixture-app-pass-0001';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'editor-user',
	appPassword: APP_PASSWORD,
	disabledTools: [],
};

const remoteSite: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'editor-user',
	appPassword: APP_PASSWORD,
	disabledTools: [],
};

type Call = { method: string; path: string; query: URLSearchParams; body: unknown; headers: Record<string, string> };

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

function makeFetch(handler: (call: Call) => unknown) {
	const calls: Call[] = [];
	const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const parsed = new URL(String(url));
		const call: Call = {
			method: (init?.method ?? 'GET').toUpperCase(),
			path: parsed.pathname.replace(/^\/wp-json/, ''),
			query: parsed.searchParams,
			body: init?.body ? (JSON.parse(String(init.body)) as unknown) : undefined,
			headers: Object.fromEntries(Object.entries((init?.headers ?? {}) as Record<string, string>)),
		};
		calls.push(call);
		const out = handler(call);
		return Promise.resolve(out instanceof Response ? out : json(out));
	});
	return { fetchImpl, calls };
}

const POST_ROW = { id: 4, title: { raw: 'Page' }, slug: 'page', status: 'publish', modified: '2026-10-01T00:00:00', link: 'http://example.test/page', type: 'page' };

describe('direct write guards', () => {
	let stateDir: string;
	const saved: Record<string, string | undefined> = {};

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-guards-'));
		for (const key of ['STONEWRIGHT_STATE_DIR', 'STONEWRIGHT_DIRECT_WRITES', 'STONEWRIGHT_DIRECT_REQUIRE_TASK_START']) saved[key] = process.env[key];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		delete process.env['STONEWRIGHT_DIRECT_WRITES'];
	});

	afterEach(() => {
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	function ctx(fetchImpl: typeof fetch, writeMode: DirectWriteMode = 'on', target: ResolvedSite = site) {
		return { client: new WpRestClient(target, { fetchImpl }), site: target, writeMode, env: process.env };
	}

	function backupFiles(tool: string, resource: string, alias = 'local'): string[] {
		const dir = join(stateDir, 'backups', alias, tool, resource);
		return existsSync(dir) ? readdirSync(dir).filter((name) => name.endsWith('.json')).sort() : [];
	}

	function auditRows(): Array<Record<string, unknown>> {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
	}

	function expectRefused(result: unknown, tool: string, kinds: string[]) {
		expect(result).toMatchObject({
			supported: false,
			approval_required: true,
			agent_must_stop: true,
			error: 'custom_code_approval_required',
			tool,
			written: false,
		});
		const findings = (result as { findings: Array<{ kind: string }> }).findings.map((f) => f.kind);
		for (const kind of kinds) expect(findings).toContain(kind);
		expect(String((result as { hint: string }).hint)).toMatch(/Plugin mode/);
	}

	describe('a1: custom-code boundary', () => {
		describe('global-styles-update', () => {
			it.each([
				['styles.css', { styles: { css: 'body{display:none}' } }],
				['per-block css', { styles: { blocks: { 'core/button': { css: '&:hover{opacity:.5}' } } } }],
				['nested block variation css', { styles: { blocks: { 'core/button': { variations: { outline: { css: 'a{}' } } } } } }],
			])('refuses %s and writes nothing', async (_label, input) => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				const result = await globalStylesUpdate(ctx(fetchImpl), { id: '12', ...input });
				expectRefused(result, 'stonewright-global-styles-update', ['custom_css_key']);
				expect(calls).toHaveLength(0);
				expect(backupFiles('stonewright-global-styles-update', '12')).toEqual([]);
				expect(auditRows().at(-1)).toMatchObject({ tool: 'stonewright-global-styles-update', status: 'blocked', code: 'custom_code_approval_required' });
			});

			it('still passes colors, typography and presets', async () => {
				const styles = { color: { background: 'var(--wp--preset--color--base)' }, typography: { fontSize: '1.2rem' } };
				const { fetchImpl, calls } = makeFetch((call) => ({ id: 12, title: 'Styles', settings: {}, styles: call.method === 'GET' ? {} : styles }));
				const result = (await globalStylesUpdate(ctx(fetchImpl), { id: '12', styles })) as Record<string, unknown>;
				expect(result['id']).toBe(12);
				expect(calls.map((c) => c.method)).toEqual(['GET', 'POST']);
			});
		});

		describe('template and template-part writes', () => {
			it.each([
				['script', '<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->', 'script'],
				['style', '<style>.x{color:red}</style>', 'style'],
				['iframe', '<iframe src="https://example.com/embed"></iframe>', 'iframe'],
				['inline handler', '<img src="a.png" onerror="alert(1)">', 'event_handler'],
				['javascript URL', '<a href="javascript:alert(1)">x</a>', 'javascript_url'],
				['entity-hidden javascript URL', '<a href="&#106;avascript&colon;alert(1)">x</a>', 'javascript_url'],
				['mixed-case element', '<ScRiPt>alert(1)</ScRiPt>', 'script'],
			])('template-update refuses %s and writes nothing', async (_label, content, kind) => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				const result = await templateUpdate(ctx(fetchImpl), { id: 'theme//home', content });
				expectRefused(result, 'stonewright-template-update', [kind]);
				expect(calls).toHaveLength(0);
				expect(backupFiles('stonewright-template-update', 'theme__home-' + createHash('sha256').update('theme//home').digest('hex').slice(0, 8))).toEqual([]);
			});

			it('template-part-update refuses a script in the content', async () => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				const result = await templatePartUpdate(ctx(fetchImpl), { id: 'theme//header', content: '<script>x()</script>' });
				expectRefused(result, 'stonewright-template-part-update', ['script']);
				expect(calls).toHaveLength(0);
			});

			it('refuses code in the title or description too', async () => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				expectRefused(await templateUpdate(ctx(fetchImpl), { id: 'a//b', title: '<script>x</script>' }), 'stonewright-template-update', ['script']);
				expectRefused(await templateUpdate(ctx(fetchImpl), { id: 'a//b', description: '<img onerror=x>' }), 'stonewright-template-update', ['event_handler']);
				expect(calls).toHaveLength(0);
			});

			it('template-create and template-part-create refuse code', async () => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				expectRefused(await templateCreate(ctx(fetchImpl), { slug: 'x', content: '<style>a{}</style>' }), 'stonewright-template-create', ['style']);
				expectRefused(await templatePartCreate(ctx(fetchImpl), { slug: 'x', content: '<iframe src=x></iframe>' }), 'stonewright-template-part-create', ['iframe']);
				expect(calls).toHaveLength(0);
			});

			it('passes ordinary template markup with links, images and blocks', async () => {
				const content =
					'<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">' +
					'<!-- wp:site-logo {"width":64} /-->' +
					'<!-- wp:navigation-link {"label":"About","url":"/about","kind":"custom"} /-->' +
					'<!-- wp:paragraph --><p>See <a href="https://example.com/a?x=1&amp;y=2">this</a> and <a href="#top">top</a>.</p><!-- /wp:paragraph -->' +
					'<!-- wp:image {"id":7} --><figure class="wp-block-image"><img src="/wp-content/uploads/a.jpg" alt="A"/></figure><!-- /wp:image -->' +
					'</div><!-- /wp:group -->';
				const { fetchImpl } = makeFetch((call) => ({ id: 'theme//home', content: { raw: call.method === 'GET' ? 'old' : content }, title: { raw: 'Home' } }));
				const result = (await templateUpdate(ctx(fetchImpl), { id: 'theme//home', content })) as Record<string, unknown>;
				expect(result['content']).toBe(content);
				expect(result['backup_path']).toBeTruthy();
			});
		});

		describe('content writes', () => {
			it.each([
				['script', '<script>alert(1)</script>', 'script'],
				['style', '<STYLE>a{}</STYLE>', 'style'],
				['iframe', '<p>x</p><iframe src="https://example.com"></iframe>', 'iframe'],
				['inline handler', '<p onclick="go()">x</p>', 'event_handler'],
				['javascript URL', '<a href=" JaVaScRiPt:go()">x</a>', 'javascript_url'],
				['entity-hidden javascript URL', '<a href="&#x6A;avascript&colon;go()">x</a>', 'javascript_url'],
				['block attribute script URL', '<!-- wp:navigation-link {"url":"javascript:go()"} /-->', 'javascript_url'],
				['escaped block attribute markup', '<!-- wp:custom {"html":"\\u003cscript\\u003ex()\\u003c/script\\u003e"} /-->', 'script'],
			])('content-update refuses %s and writes nothing', async (_label, content, kind) => {
				const { fetchImpl, calls } = makeFetch(() => POST_ROW);
				const result = await contentUpdate(ctx(fetchImpl), { id: 4, content });
				expectRefused(result, 'stonewright-content-update', [kind]);
				expect(calls).toHaveLength(0);
				expect(backupFiles('stonewright-content-update', 'pages-4')).toEqual([]);
			});

			it('content-create refuses code for page, post and generic creates', async () => {
				const { fetchImpl, calls } = makeFetch(() => POST_ROW);
				const content = '<script>x()</script>';
				expectRefused(await contentCreate(ctx(fetchImpl), { kind: 'page', title: 'T', content }), 'stonewright-content-create-page', ['script']);
				expectRefused(await contentCreate(ctx(fetchImpl), { kind: 'post', title: 'T', content }), 'stonewright-content-create-post', ['script']);
				expectRefused(await contentCreate(ctx(fetchImpl), { type: 'page', title: 'T', content }), 'stonewright-content-create', ['script']);
				expect(calls).toHaveLength(0);
			});

			it('content-autosave-create refuses code', async () => {
				const { fetchImpl, calls } = makeFetch(() => POST_ROW);
				expectRefused(await contentAutosaveCreate(ctx(fetchImpl), { id: 4, content: '<iframe src=x></iframe>' }), 'stonewright-content-autosave-create', ['iframe']);
				expect(calls).toHaveLength(0);
			});

			it('refuses code in the title and in meta, including _elementor_data and page settings', async () => {
				const { fetchImpl, calls } = makeFetch(() => POST_ROW);
				expectRefused(await contentUpdate(ctx(fetchImpl), { id: 4, title: '<script>x</script>' }), 'stonewright-content-update', ['script']);
				expectRefused(await contentUpdate(ctx(fetchImpl), { id: 4, meta: { header_scripts: '<script>x()</script>' } }), 'stonewright-content-update', ['script']);
				expectRefused(
					await contentUpdate(ctx(fetchImpl), { id: 4, meta: { _elementor_data: JSON.stringify([{ id: 'a', elType: 'widget', widgetType: 'html', settings: {} }]) } }),
					'stonewright-content-update',
					['html_widget'],
				);
				expectRefused(await contentUpdate(ctx(fetchImpl), { id: 4, meta: { _elementor_page_settings: { custom_css: 'a{}' } } }), 'stonewright-content-update', ['custom_css_key']);
				expect(calls).toHaveLength(0);
			});

			it('passes links, images, ordinary blocks and prose that mentions code', async () => {
				const content =
					'<!-- wp:heading {"level":2} --><h2 class="wp-block-heading">Hello</h2><!-- /wp:heading -->' +
					'<!-- wp:paragraph --><p>Read <a href="https://example.com/docs">the docs</a>, mail <a href="mailto:a@example.com">us</a>. javascript: is a language; online=1.</p><!-- /wp:paragraph -->' +
					'<!-- wp:image {"id":9,"sizeSlug":"large"} --><figure class="wp-block-image size-large"><img src="https://example.com/a.png" alt="A" class="wp-image-9"/></figure><!-- /wp:image -->' +
					'<!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact">Contact</a></div><!-- /wp:button -->' +
					'<!-- wp:code --><pre class="wp-block-code"><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre><!-- /wp:code -->';
				const { fetchImpl, calls } = makeFetch(() => POST_ROW);
				const created = await contentCreate(ctx(fetchImpl), { kind: 'page', title: 'T', content });
				expect(created).toMatchObject({ id: 4 });
				const updated = (await contentUpdate(ctx(fetchImpl), { id: 4, content, meta: { subtitle: 'Plain', count: 3 } })) as Record<string, unknown>;
				expect(updated['id']).toBe(4);
				expect(calls.filter((c) => c.method === 'PUT' || c.method === 'POST')).toHaveLength(2);
			});
		});

		describe('widget-manage', () => {
			it.each([
				['custom HTML widget', { action: 'create', id_base: 'custom_html', sidebar: 'sidebar-1', instance: { content: '<p>x</p>' } }, 'html_widget'],
				['script in a text widget', { action: 'create', id_base: 'text', sidebar: 'sidebar-1', instance: { text: '<script>x()</script>' } }, 'script'],
				['handler in an update', { action: 'update', id: 'text-2', instance: { raw: { content: '<img src=a onerror=x()>' } } }, 'event_handler'],
				['javascript URL in an update', { action: 'update', id: 'text-2', instance: { text: '<a href="javascript:x()">y</a>' } }, 'javascript_url'],
			])('refuses a %s and writes nothing', async (_label, input, kind) => {
				const { fetchImpl, calls } = makeFetch(() => ({}));
				const result = await widgetManage(ctx(fetchImpl), input as never);
				expectRefused(result, 'stonewright-widget-manage', [kind]);
				expect(calls).toHaveLength(0);
				expect(auditRows().at(-1)).toMatchObject({ tool: 'stonewright-widget-manage', status: 'blocked' });
			});

			it('passes an ordinary widget and a delete', async () => {
				const { fetchImpl, calls } = makeFetch(() => ({ id: 'text-2', id_base: 'text', sidebar: 'sidebar-1', instance: {} }));
				await widgetManage(ctx(fetchImpl), { action: 'create', id_base: 'text', sidebar: 'sidebar-1', instance: { text: '<p>Hello <a href="/about">about</a></p>' } });
				await widgetManage(ctx(fetchImpl), { action: 'delete', id: 'text-2' });
				expect(calls.map((c) => c.method)).toEqual(['POST', 'DELETE']);
			});
		});

		describe('blueprint-apply', () => {
			it('every bundled blueprint serializes to markup that passes the boundary', () => {
				const ids = listBlueprints().map((bp) => bp.id);
				expect(ids.length).toBeGreaterThan(0);
				for (const id of ids) {
					const spec = (getBlueprint(id)?.['spec'] ?? {}) as DesignSpec;
					expect(scanMarkup(serializeDesignSpec(spec)), id).toEqual([]);
				}
			});
		});

		describe('elementor-data-update', () => {
			function cliFor(tree: unknown) {
				return vi.fn((input: { command: string[] }): Promise<WpCliCommandResult> => {
					const key = input.command.join(' ');
					const parsed = key.includes('meta get') && key.includes('_elementor_data') ? tree : undefined;
					return Promise.resolve({
						ok: true,
						available: true,
						command: input.command,
						cwd: '/tmp',
						stdout: '',
						stderr: '',
						exit_code: 0,
						duration_ms: 1,
						wp_cli_source: 'test',
						...(parsed !== undefined ? { parsed_json: parsed } : {}),
					} as WpCliCommandResult);
				});
			}

			const env = () => ({ STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_WRITES: 'on' });
			const baseChildren = [{ id: 'w1', elType: 'widget', widgetType: 'heading', settings: { title: 'Hi' }, elements: [] as unknown[] }];
			const base = [{ id: 'c1', elType: 'container', settings: {}, elements: baseChildren }];

			it.each([
				['html widget', { id: 'w2', elType: 'widget', widgetType: 'html', settings: { html: '<p>x</p>' }, elements: [] }, 'html_widget'],
				['raw-html widget', { id: 'w2', elType: 'widget', widgetType: 'raw-html', settings: {}, elements: [] }, 'html_widget'],
				['custom_css setting', { id: 'w2', elType: 'widget', widgetType: 'heading', settings: { custom_css: 'selector{color:red}' }, elements: [] }, 'custom_css_key'],
				['page_custom_css setting', { id: 'w2', elType: 'widget', widgetType: 'heading', settings: { page_custom_css: 'a{}' }, elements: [] }, 'custom_css_key'],
				['script in a text setting', { id: 'w2', elType: 'widget', widgetType: 'text-editor', settings: { editor: '<script>x()</script>' }, elements: [] }, 'script'],
			])('refuses a tree with a %s and writes nothing', async (_label, widget, kind) => {
				const tree = [{ ...base[0], elements: [...baseChildren, widget] }];
				const cli = cliFor(base);
				const rest = { get: vi.fn(() => Promise.resolve({})), post: vi.fn(() => Promise.resolve({})) };
				const result = await elementorDataUpdate(env(), { post_id: 9, data: tree, confirm: true }, cli as never, rest);
				expectRefused(result, 'stonewright-elementor-data-update', [kind]);
				expect(cli).not.toHaveBeenCalled();
				expect(rest.post).not.toHaveBeenCalled();
				expect(rest.get).not.toHaveBeenCalled();
				expect(readdirSync(stateDir).includes('backups')).toBe(false);
			});

			it('refuses the same payload when it arrives as a JSON string', async () => {
				const tree = [{ id: 'w2', elType: 'widget', widgetType: 'html', settings: {}, elements: [] }];
				const cli = cliFor(base);
				const result = await elementorDataUpdate(env(), { post_id: 9, data: JSON.stringify(tree), confirm: true }, cli as never);
				expectRefused(result, 'stonewright-elementor-data-update', ['html_widget']);
				expect(cli).not.toHaveBeenCalled();
			});

			it('still writes an ordinary tree', async () => {
				const next = [{ ...base[0], elements: [{ id: 'w1', elType: 'widget', widgetType: 'heading', settings: { title: 'Changed', title_color: '#112233', custom_css: '' }, elements: [] }] }];
				const cli = cliFor(base);
				const result = (await elementorDataUpdate(env(), { post_id: 9, data: next, confirm: true }, cli as never)) as Record<string, unknown>;
				expect(result['ok']).toBe(true);
				expect(cli.mock.calls.some(([input]) => input.command.includes('update'))).toBe(true);
			});
		});
	});

	describe('a2: pre-image before whole-document writes', () => {
		it('template-update reads context=edit first, stores it, then writes, and returns the path and hash', async () => {
			const stored = { id: 'theme//home', slug: 'home', content: { raw: '<!-- wp:paragraph --><p>old</p><!-- /wp:paragraph -->' }, title: { raw: 'Home' } };
			let backupExistedAtWrite = false;
			const { fetchImpl, calls } = makeFetch((call) => {
				if (call.method === 'POST') {
					backupExistedAtWrite = backupFiles('stonewright-template-update', 'theme__home-' + createHash('sha256').update('theme//home').digest('hex').slice(0, 8)).length === 1;
					return { ...stored, content: { raw: 'new' } };
				}
				return stored;
			});
			const result = (await templateUpdate(ctx(fetchImpl), { id: 'theme//home', content: 'new' })) as Record<string, unknown>;

			expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual([
				'GET /wp/v2/templates/theme%2F%2Fhome',
				'POST /wp/v2/templates/theme%2F%2Fhome',
			]);
			expect(calls[0]?.query.get('context')).toBe('edit');
			expect(backupExistedAtWrite).toBe(true);

			const path = String(result['backup_path']);
			expect(existsSync(path)).toBe(true);
			expect(path.startsWith(join(stateDir, 'backups', 'local', 'stonewright-template-update'))).toBe(true);
			expect(path.endsWith('.json')).toBe(true);
			const bytes = readFileSync(path);
			expect(result['backup_sha256']).toBe(createHash('sha256').update(bytes).digest('hex'));
			const record = JSON.parse(bytes.toString('utf8')) as Record<string, unknown>;
			expect(record['body']).toEqual(stored);
			expect(record['tool']).toBe('stonewright-template-update');
			expect(record['site']).toBe('local');
			if (process.platform !== 'win32') {
				expect(statSync(path).mode & 0o777).toBe(0o600);
			}
		});

		it('template-part-update stores a pre-image under its own tool folder', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({ id: 'theme//header', content: { raw: 'x' }, title: { raw: 'Header' } }));
			const result = (await templatePartUpdate(ctx(fetchImpl), { id: 'theme//header', title: 'Header' })) as Record<string, unknown>;
			expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual([
				'GET /wp/v2/template-parts/theme%2F%2Fheader',
				'POST /wp/v2/template-parts/theme%2F%2Fheader',
			]);
			expect(String(result['backup_path'])).toContain('stonewright-template-part-update');
		});

		it('global-styles-update stores a pre-image of context=edit and returns it', async () => {
			const stored = { id: 12, title: { raw: 'Styles' }, settings: { color: {} }, styles: { color: { text: '#000' } } };
			const { fetchImpl, calls } = makeFetch((call) => (call.method === 'GET' ? stored : { ...stored, styles: { color: { text: '#111' } } }));
			const result = (await globalStylesUpdate(ctx(fetchImpl), { id: '12', styles: { color: { text: '#111' } } })) as Record<string, unknown>;
			expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /wp/v2/global-styles/12', 'POST /wp/v2/global-styles/12']);
			expect(calls[0]?.query.get('context')).toBe('edit');
			const record = JSON.parse(readFileSync(String(result['backup_path']), 'utf8')) as { body: unknown };
			expect(record.body).toEqual(stored);
			expect(String(result['backup_sha256'])).toMatch(/^[0-9a-f]{64}$/);
			expect(backupFiles('stonewright-global-styles-update', '12')).toHaveLength(1);
		});

		it('content-update stores a pre-image of the resolved collection', async () => {
			const stored = { id: 4, content: { raw: 'before' }, title: { raw: 'Page' }, meta: {} };
			const { fetchImpl, calls } = makeFetch((call) => (call.method === 'GET' ? stored : POST_ROW));
			const result = (await contentUpdate(ctx(fetchImpl), { id: 4, content: 'after' })) as Record<string, unknown>;
			expect(calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /wp/v2/pages/4', 'PUT /wp/v2/pages/4']);
			expect(calls[0]?.query.get('context')).toBe('edit');
			expect(result['id']).toBe(4);
			expect(backupFiles('stonewright-content-update', 'pages-4')).toHaveLength(1);
			expect((JSON.parse(readFileSync(String(result['backup_path']), 'utf8')) as { body: unknown }).body).toEqual(stored);
		});

		it('blueprint-apply with post_id stores a pre-image; a create does not read anything', async () => {
			const stored = { id: 9, content: { raw: 'old page' }, title: { raw: 'Old' } };
			const updateCalls = makeFetch((call) => (call.method === 'GET' ? stored : { id: 9 }));
			const updated = await applyBlueprint(new WpRestClient(site, { fetchImpl: updateCalls.fetchImpl }), { id: 'agency', post_id: 9 }, process.env);
			expect(updated['ok']).toBe(true);
			expect(updateCalls.calls.map((c) => `${c.method} ${c.path}`)).toEqual(['GET /wp/v2/pages/9', 'POST /wp/v2/pages/9']);
			expect(updated['backup_path']).toBeTruthy();
			expect(updated['backup_sha256']).toMatch(/^[0-9a-f]{64}$/);
			expect(backupFiles('stonewright-blueprint-apply', 'pages-9')).toHaveLength(1);

			const createCalls = makeFetch(() => ({ id: 77 }));
			const created = await applyBlueprint(new WpRestClient(site, { fetchImpl: createCalls.fetchImpl }), { id: 'agency' }, process.env);
			expect(created['ok']).toBe(true);
			expect(createCalls.calls.map((c) => c.method)).toEqual(['POST']);
			expect(created['backup_path']).toBeUndefined();
		});

		it('refuses the write and sends nothing when the current resource cannot be read', async () => {
			const { fetchImpl, calls } = makeFetch((call) => (call.method === 'GET' ? json({ code: 'rest_template_not_found', message: 'No template' }, 404) : { id: 'x' }));
			await expect(templateUpdate(ctx(fetchImpl), { id: 'theme//home', content: 'new' })).rejects.toMatchObject({ code: 'preimage_unavailable' });
			await expect(templatePartUpdate(ctx(fetchImpl), { id: 'theme//header', content: 'new' })).rejects.toThrow(/Nothing was written/);
			await expect(globalStylesUpdate(ctx(fetchImpl), { id: '12', styles: { color: {} } })).rejects.toMatchObject({ code: 'preimage_unavailable' });
			await expect(contentUpdate(ctx(fetchImpl), { id: 4, content: 'new' })).rejects.toMatchObject({ code: 'preimage_unavailable' });
			await expect(applyBlueprint(new WpRestClient(site, { fetchImpl }), { id: 'agency', post_id: 9 }, process.env)).rejects.toMatchObject({ code: 'preimage_unavailable' });
			expect(calls.filter((c) => c.method !== 'GET')).toEqual([]);
			expect(existsSync(join(stateDir, 'backups'))).toBe(false);
		});

		it('refuses the write when the read returns no resource body', async () => {
			const { fetchImpl, calls } = makeFetch((call) => (call.method === 'GET' ? new Response('', { status: 200 }) : {}));
			await expect(templateUpdate(ctx(fetchImpl), { id: 'a//b', content: 'x' })).rejects.toMatchObject({ code: 'preimage_unavailable' });
			expect(calls.filter((c) => c.method !== 'GET')).toEqual([]);
		});

		it('refuses the write and sends nothing when the pre-image cannot be stored', async () => {
			const blocker = join(stateDir, 'not-a-folder');
			writeFileSync(blocker, 'x');
			process.env['STONEWRIGHT_STATE_DIR'] = blocker;
			const { fetchImpl, calls } = makeFetch((call) => (call.method === 'GET' ? { id: 'a//b', content: { raw: 'x' } } : {}));
			await expect(templateUpdate(ctx(fetchImpl), { id: 'a//b', content: 'x' })).rejects.toMatchObject({ code: 'preimage_unavailable' });
			await expect(templateUpdate(ctx(fetchImpl), { id: 'a//b', content: 'x' })).rejects.toThrow(/Nothing was written/);
			expect(calls.filter((c) => c.method !== 'GET')).toEqual([]);
		});

		it('keeps at most 20 backups per resource and deletes the oldest', async () => {
			const { fetchImpl } = makeFetch((call) => ({ id: 'theme//home', content: { raw: call.method === 'GET' ? 'old' : 'new' } }));
			const paths: string[] = [];
			for (let index = 0; index < 23; index += 1) {
				const result = (await templateUpdate(ctx(fetchImpl), { id: 'theme//home', content: `v${index}` })) as Record<string, unknown>;
				paths.push(String(result['backup_path']));
			}
			const remaining = paths.filter((path) => existsSync(path));
			expect(remaining).toHaveLength(20);
			expect(remaining).toEqual(paths.slice(3));
			expect(new Set(paths).size).toBe(23);
		});

		it('rotates each resource separately', async () => {
			const { fetchImpl } = makeFetch(() => ({ id: 1, content: { raw: 'x' } }));
			for (let index = 0; index < 22; index += 1) await contentUpdate(ctx(fetchImpl), { id: 4, content: `a${index}` });
			await contentUpdate(ctx(fetchImpl), { id: 5, content: 'b' });
			expect(backupFiles('stonewright-content-update', 'pages-4')).toHaveLength(20);
			expect(backupFiles('stonewright-content-update', 'pages-5')).toHaveLength(1);
		});

		it('never stores credentials or request headers', async () => {
			const stored = { id: 'theme//home', content: { raw: '<p>hello</p>' }, title: { raw: 'Home' } };
			const { fetchImpl, calls } = makeFetch(() => stored);
			const result = (await templateUpdate(ctx(fetchImpl), { id: 'theme//home', content: 'x' })) as Record<string, unknown>;
			expect(calls[0]?.headers['authorization']).toMatch(/^Basic /);
			const text = readFileSync(String(result['backup_path']), 'utf8');
			expect(text).not.toContain(APP_PASSWORD);
			expect(text).not.toContain(Buffer.from(`editor-user:${APP_PASSWORD}`).toString('base64'));
			expect(text.toLowerCase()).not.toContain('authorization');
			expect(text).not.toContain('Basic ');
			expect(text).not.toContain('editor-user');
			expect(JSON.stringify(result)).not.toContain(APP_PASSWORD);
		});

		it('keeps path-like ids and aliases inside the backups folder', async () => {
			const evil: ResolvedSite = { ...site, alias: '../../escape' };
			markTaskStartSeen(evil);
			const { fetchImpl } = makeFetch(() => ({ id: '../../x', content: { raw: 'x' } }));
			const result = (await templateUpdate(ctx(fetchImpl, 'on', evil), { id: '../../x', content: 'y' })) as Record<string, unknown>;
			const path = String(result['backup_path']);
			expect(path.startsWith(join(stateDir, 'backups'))).toBe(true);
			expect(path.slice(join(stateDir, 'backups').length)).not.toContain('..');
		});
	});

	describe('a4: confirm on overwrites', () => {
		it.each([
			['template-update', templateUpdate, { id: 'theme//home', content: 'x' }],
			['template-part-update', templatePartUpdate, { id: 'theme//header', content: 'x' }],
		])('%s needs confirm:true in confirm mode and sends nothing without it', async (_name, tool, input) => {
			const { fetchImpl, calls } = makeFetch(() => ({ id: 'a', content: { raw: 'x' } }));
			await expect(tool(ctx(fetchImpl, 'confirm', remoteSite), input as never)).rejects.toMatchObject({ code: 'confirmation_required' });
			expect(calls).toHaveLength(0);
			const result = (await tool(ctx(fetchImpl, 'confirm', remoteSite), { ...input, confirm: true } as never)) as Record<string, unknown>;
			expect(result['backup_path']).toBeTruthy();
			expect(calls.map((c) => c.method)).toEqual(['GET', 'POST']);
		});

		it.each([
			['template-update', templateUpdate, { id: 'theme//home', content: 'x' }],
			['template-part-update', templatePartUpdate, { id: 'theme//header', content: 'x' }],
		])('%s needs no confirm when writes are on (local site)', async (_name, tool, input) => {
			const { fetchImpl } = makeFetch(() => ({ id: 'a', content: { raw: 'x' } }));
			await expect(tool(ctx(fetchImpl, 'on'), input as never)).resolves.toBeTruthy();
		});

		it('template-update is blocked when writes are off', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			await expect(templateUpdate(ctx(fetchImpl, 'off'), { id: 'a//b', content: 'x' })).rejects.toMatchObject({ code: 'direct_writes_disabled' });
			expect(calls).toHaveLength(0);
		});

		it('blueprint-apply over an existing page needs confirm:true in confirm mode, and a create does not', async () => {
			const env = { ...process.env, STONEWRIGHT_DIRECT_WRITES: 'confirm' };
			const first = makeFetch(() => ({ id: 9, content: { raw: 'x' } }));
			await expect(applyBlueprint(new WpRestClient(site, { fetchImpl: first.fetchImpl }), { id: 'agency', post_id: 9 }, env)).rejects.toMatchObject({ code: 'confirmation_required' });
			expect(first.calls).toHaveLength(0);
			const ok = await applyBlueprint(new WpRestClient(site, { fetchImpl: first.fetchImpl }), { id: 'agency', post_id: 9, confirm: true }, env);
			expect(ok['ok']).toBe(true);
			const created = makeFetch(() => ({ id: 3 }));
			const make = await applyBlueprint(new WpRestClient(site, { fetchImpl: created.fetchImpl }), { id: 'agency' }, env);
			expect(make['ok']).toBe(true);
		});

		it('checks confirm before it looks for custom code or reads the resource', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			await expect(templateUpdate(ctx(fetchImpl, 'confirm', remoteSite), { id: 'a//b', content: '<script>x</script>' })).rejects.toMatchObject({ code: 'confirmation_required' });
			expect(calls).toHaveLength(0);
		});
	});
	describe('registry surface', () => {
		type RegisteredTool = {
			inputSchema?: { shape?: Record<string, unknown> };
			description?: string;
			handler?: (input: unknown) => Promise<{ isError?: boolean; content: Array<{ text: string }> }>;
		};

		async function tools(fetchImpl: typeof fetch, writes: string) {
			// The template tools read the task-start rule from the process environment.
			process.env['STONEWRIGHT_DIRECT_REQUIRE_TASK_START'] = 'off';
			const server = await createMcpServer({
				env: {
					STONEWRIGHT_MODE: 'direct',
					STONEWRIGHT_MCP_TOOL_PROFILE: 'full',
					STONEWRIGHT_STATE_DIR: stateDir,
					STONEWRIGHT_WP_URL: 'https://example.com',
					STONEWRIGHT_WP_USERNAME: 'editor-user',
					STONEWRIGHT_WP_APP_PASSWORD: APP_PASSWORD,
					STONEWRIGHT_DIRECT_WRITES: writes,
					STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
				},
				fetchImpl,
			});
			return (server as { _registeredTools?: Record<string, RegisteredTool> })._registeredTools ?? {};
		}

		it.each(['stonewright-template-update', 'stonewright-template-part-update', 'stonewright-blueprint-apply'])(
			'%s accepts confirm and says so',
			async (name) => {
				const registered = await tools(() => Promise.reject(new Error('no network')), 'confirm');
				expect(Object.keys(registered[name]?.inputSchema?.shape ?? {})).toContain('confirm');
				expect(registered[name]?.description).toMatch(/confirm:true/);
			},
		);

		it('descriptions name the pre-image and the custom-code refusal', async () => {
			const registered = await tools(() => Promise.reject(new Error('no network')), 'on');
			for (const name of ['stonewright-template-update', 'stonewright-template-part-update', 'stonewright-global-styles-update', 'stonewright-content-update', 'stonewright-blueprint-apply']) {
				expect(registered[name]?.description, name).toMatch(/backup_path/);
				expect(registered[name]?.description, name).toMatch(/Plugin mode/);
			}
			expect(registered['stonewright-elementor-data-update']?.description).toMatch(/Plugin mode/);
		});

		it('returns the refusal as a normal result on a remote site, without an error flag, and writes nothing', async () => {
			const { fetchImpl, calls } = makeFetch(() => ({}));
			const registered = await tools(fetchImpl, 'confirm');
			// Direct-only startup reads the public REST index to look for the plugin; the tool calls below must send nothing.
			calls.length = 0;
			const result = await registered['stonewright-template-update']?.handler?.({ id: 'a//b', content: '<script>x()</script>', confirm: true });
			expect(result?.isError).toBeUndefined();
			expect(JSON.parse(result?.content[0]?.text ?? '{}')).toMatchObject({ approval_required: true, agent_must_stop: true, error: 'custom_code_approval_required' });
			expect(calls).toHaveLength(0);
			const blocked = await registered['stonewright-template-update']?.handler?.({ id: 'a//b', content: 'x' });
			expect(blocked?.isError).toBe(true);
			expect(JSON.parse(blocked?.content[0]?.text ?? '{}')).toMatchObject({ error: 'confirmation_required' });
			expect(calls).toHaveLength(0);
		});
	});
});
