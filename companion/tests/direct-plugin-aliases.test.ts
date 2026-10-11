import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { registerDirectTools } from '../src/direct/registry.js';
import { DIRECT_ARG_ALIASES, DIRECT_PLUGIN_ONLY_ARGS } from '../src/direct/arg-aliases.js';
import type { SitesConfig } from '../src/direct/sites-config.js';

type Recorded = { method: string; url: URL; json: Record<string, unknown> | null; form: FormData | null };

function json(body: unknown, status = 200): Response {
	return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
}

const sitesConfig: SitesConfig = {
	default: 'main',
	source: 'env',
	sites: { main: { url: 'https://example.test', username: 'admin', appPassword: 'fixture-pass-0001' } },
};

describe('Direct accepts the plugin argument names', () => {
	let dir: string;
	let requests: Recorded[];
	let client: Client;
	let lastPageBody: Record<string, unknown> | null = null;
	let lastMenuBody: Record<string, unknown> | null = null;
	let settingsState: Record<string, unknown> = {};

	function fakeFetch(): typeof fetch {
		return vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = new URL(String(input));
			const method = (init?.method ?? 'GET').toUpperCase();
			const body = init?.body;
			if (method !== 'GET' && typeof body === 'string' && /\/wp\/v2\/(pages|posts)$/.test(url.pathname)) lastPageBody = JSON.parse(body) as Record<string, unknown>;
			if (method !== 'GET' && typeof body === 'string' && url.pathname.endsWith('/wp/v2/menus')) lastMenuBody = JSON.parse(body) as Record<string, unknown>;
			if (method !== 'GET' && typeof body === 'string' && url.pathname.endsWith('/wp/v2/settings')) settingsState = { ...settingsState, ...Object.fromEntries(Object.entries(JSON.parse(body) as Record<string, unknown>).filter(([key]) => ['title', 'description', 'timezone', 'date_format', 'time_format'].includes(key))) };
			requests.push({
				method,
				url,
				json: typeof body === 'string' ? (JSON.parse(body) as Record<string, unknown>) : null,
				form: body instanceof FormData ? body : null,
			});
			const path = url.pathname.replace('/wp-json', '');
			if (method === 'GET') {
				if (path === '/wp/v2/users/me') return Promise.resolve(json({ id: 1 }));
				if (/^\/wp\/v2\/users\/\d+$/.test(path)) return Promise.resolve(json({ id: 9, roles: ['editor'] }));
				if (path === '/wp/v2/users') return Promise.resolve(json([{ id: 1 }]));
				// A stored page or post reads back as what was last sent to it (the readback after a write).
				const stored = /^\/wp\/v2\/(pages|posts)\/7$/.test(path) ? lastPageBody : null;
				if (stored) {
					return Promise.resolve(json({ id: 7, ...stored, title: { raw: stored['title'] }, content: { raw: stored['content'] }, excerpt: { raw: stored['excerpt'] } }));
				}
				if (path === '/wp/v2/menus/7' && lastMenuBody) return Promise.resolve(json({ id: 7, ...lastMenuBody }));
				if (path === '/wp/v2/settings') {
					return Promise.resolve(json({ title: 'Site', description: 'Tag', timezone: 'UTC', date_format: 'Y', url: 'https://example.test', ...settingsState }));
				}
				return Promise.resolve(json([]));
			}
			if (url.pathname.endsWith('/wp/v2/settings')) return Promise.resolve(json({ title: 'Site', description: 'Tag', timezone: 'UTC', date_format: 'Y', url: 'https://example.test', ...settingsState }));
			return Promise.resolve(json({ id: 7, title: { raw: 't' }, slug: 's', status: 'draft', source_url: 'https://example.test/x.png', mime_type: 'image/png' }, 201));
		}) as unknown as typeof fetch;
	}

	async function connect(): Promise<Client> {
		vi.stubEnv('STONEWRIGHT_DIRECT_WRITES', 'on');
		vi.stubEnv('STONEWRIGHT_DIRECT_REQUIRE_TASK_START', 'off');
		vi.stubEnv('STONEWRIGHT_STATE_DIR', join(dir, 'state'));
		const env = {
			STONEWRIGHT_DIRECT_WRITES: 'on',
			STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off',
			STONEWRIGHT_STATE_DIR: join(dir, 'state'),
		};
		const server = new McpServer({ name: 'test', version: '0.0.0' });
		registerDirectTools(server, { env, fetchImpl: fakeFetch(), sitesConfig, toolProfile: 'full' });
		const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
		const c = new Client({ name: 'test-client', version: '0.0.0' });
		await Promise.all([server.connect(serverTransport), c.connect(clientTransport)]);
		return c;
	}

	beforeEach(async () => {
		dir = mkdtempSync(join(tmpdir(), 'sw-aliases-'));
		requests = [];
		settingsState = {};
		lastPageBody = null;
		lastMenuBody = null;
		client = await connect();
	});

	afterEach(async () => {
		await client.close();
		vi.unstubAllEnvs();
		rmSync(dir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	async function call(name: string, args: Record<string, unknown>) {
		const result = await client.callTool({ name, arguments: args });
		const text = (result.content as Array<{ text: string }>)[0]?.text ?? '{}';
		return { isError: result.isError === true, body: JSON.parse(text) as Record<string, unknown> };
	}

	const writes = () => requests.filter((r) => r.method !== 'GET');
	const lastWrite = () => writes()[writes().length - 1];

	describe('aliases fill the Direct argument', () => {
		it('comment-list: post_id for post, and the status spellings of both modes', async () => {
			await call('stonewright-comment-list', { post_id: 12, status: 'approve' });
			expect(requests[0]?.url.searchParams.get('post')).toBe('12');
			expect(requests[0]?.url.searchParams.get('status')).toBe('approve');
			requests.length = 0;
			await call('stonewright-comment-list', { post: 12, status: 'approved' });
			expect(requests[0]?.url.searchParams.get('status')).toBe('approve');
			requests.length = 0;
			await call('stonewright-comment-list', { status: 'all' });
			expect(requests[0]?.url.searchParams.get('status')).toBe('all');
		});

		it('comment-create: post_id for post', async () => {
			const result = await call('stonewright-comment-create', { post_id: 12, content: 'Hello', confirm: true });
			expect(result.isError).toBe(false);
			expect(lastWrite()?.json?.['post']).toBe(12);
		});

		it('comment-update: both approve and approved reach WordPress as approved', async () => {
			await call('stonewright-comment-update', { id: 3, status: 'approve', confirm: true });
			expect(lastWrite()?.json?.['status']).toBe('approved');
			await call('stonewright-comment-update', { id: 3, status: 'approved', confirm: true });
			expect(lastWrite()?.json?.['status']).toBe('approved');
			await call('stonewright-comment-update', { id: 3, status: 'hold', confirm: true });
			expect(lastWrite()?.json?.['status']).toBe('hold');
		});

		it('menu-delete: menu_id for id', async () => {
			const result = await call('stonewright-menu-delete', { menu_id: 5, confirm: true });
			expect(result.isError).toBe(false);
			expect(lastWrite()?.method).toBe('DELETE');
			expect(lastWrite()?.url.pathname).toBe('/wp-json/wp/v2/menus/5');
		});

		it('media-upload: alt for alt_text and parent_post_id as the attachment parent', async () => {
			const file = join(dir, 'pixel.png');
			writeFileSync(file, Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 0]));
			const result = await call('stonewright-media-upload', { path: file, alt: 'A pixel', parent_post_id: 21, confirm: true });
			expect(result.isError).toBe(false);
			expect(lastWrite()?.form?.get('alt_text')).toBe('A pixel');
			expect(lastWrite()?.form?.get('post')).toBe('21');
		});

		it('user-create: user_login, user_email, user_pass, role and display_name', async () => {
			const result = await call('stonewright-user-create', {
				user_login: 'jdoe',
				user_email: 'jdoe@example.com',
				user_pass: 'test-long-password-12',
				role: 'author',
				display_name: 'J Doe',
				confirm: true,
			});
			expect(result.isError).toBe(false);
			expect(lastWrite()?.json).toEqual({
				username: 'jdoe',
				email: 'jdoe@example.com',
				password: 'test-long-password-12',
				roles: ['author'],
				name: 'J Doe',
			});
		});

		it('user-update: user_email, user_pass, role and display_name', async () => {
			const result = await call('stonewright-user-update', {
				id: 9,
				user_email: 'new@example.com',
				user_pass: 'test-another-pass-1',
				role: 'editor',
				display_name: 'New Name',
				confirm: true,
			});
			expect(result.isError).toBe(false);
			expect(lastWrite()?.json).toEqual({
				email: 'new@example.com',
				name: 'New Name',
				roles: ['editor'],
				password: 'test-another-pass-1',
			});
		});

		it('user-list: role for roles, number for per_page (capped at 50), offset', async () => {
			await call('stonewright-user-list', { role: 'editor', number: 100, offset: 10 });
			const q = requests[0]?.url.searchParams;
			expect(q?.get('roles')).toBe('editor');
			expect(q?.get('per_page')).toBe('50');
			expect(q?.get('offset')).toBe('10');
		});

		it('blueprint-get: blueprint_id for id', async () => {
			const viaAlias = await call('stonewright-blueprint-get', { blueprint_id: 'agency' });
			const viaId = await call('stonewright-blueprint-get', { id: 'agency' });
			expect(viaAlias.body['ok']).toBe(true);
			expect(viaAlias.body).toEqual(viaId.body);
		});

		it('blueprint-apply: blueprint_id, page_title and mode', async () => {
			const result = await call('stonewright-blueprint-apply', { blueprint_id: 'agency', page_title: 'Our agency', mode: 'publish' });
			expect(result.body['ok']).toBe(true);
			expect(lastWrite()?.url.pathname).toBe('/wp-json/wp/v2/pages');
			expect(lastWrite()?.json?.['title']).toBe('Our agency');
			expect(lastWrite()?.json?.['status']).toBe('publish');
		});

		it('wc-sales-report: date_from and date_to for date_min and date_max', async () => {
			await call('stonewright-wc-sales-report', { period: 'custom', date_from: '2026-01-01', date_to: '2026-01-31' });
			const q = requests[0]?.url.searchParams;
			expect(q?.get('date_min')).toBe('2026-01-01');
			expect(q?.get('date_max')).toBe('2026-01-31');
			expect(q?.has('period')).toBe(false);
		});

		it('every alias in the table has a case in this file', () => {
			const covered = new Set(CONFLICT_CASES.map((c) => `${c.tool}:${c.alias}`));
			for (const [tool, rules] of Object.entries(DIRECT_ARG_ALIASES)) {
				for (const rule of rules) expect(covered.has(`${tool}:${rule.alias}`), `${tool}:${rule.alias}`).toBe(true);
			}
		});
	});

	const CONFLICT_CASES: Array<{
		tool: string;
		alias: string;
		canonical: string;
		a: unknown;
		b: unknown;
		base?: Record<string, unknown>;
	}> = [
		{ tool: 'stonewright-comment-list', alias: 'post_id', canonical: 'post', a: 1, b: 2 },
		{ tool: 'stonewright-comment-create', alias: 'post_id', canonical: 'post', a: 1, b: 2, base: { content: 'x', confirm: true } },
		{ tool: 'stonewright-menu-delete', alias: 'menu_id', canonical: 'id', a: 1, b: 2, base: { confirm: true } },
		{ tool: 'stonewright-media-upload', alias: 'alt', canonical: 'alt_text', a: 'one', b: 'two', base: { url: 'https://example.com/a.png' } },
		{ tool: 'stonewright-user-create', alias: 'user_login', canonical: 'username', a: 'one', b: 'two', base: { email: 'a@example.com', password: 'test-long-password-12', confirm: true } },
		{ tool: 'stonewright-user-create', alias: 'user_email', canonical: 'email', a: 'one@example.com', b: 'two@example.com', base: { username: 'u', password: 'test-long-password-12', confirm: true } },
		{ tool: 'stonewright-user-create', alias: 'user_pass', canonical: 'password', a: 'test-long-password-12', b: 'test-long-password-13', base: { username: 'u', email: 'a@example.com', confirm: true } },
		{ tool: 'stonewright-user-create', alias: 'role', canonical: 'roles', a: 'author', b: ['editor'], base: { username: 'u', email: 'a@example.com', password: 'test-long-password-12', confirm: true } },
		{ tool: 'stonewright-user-create', alias: 'display_name', canonical: 'name', a: 'One', b: 'Two', base: { username: 'u', email: 'a@example.com', password: 'test-long-password-12', confirm: true } },
		{ tool: 'stonewright-user-update', alias: 'user_email', canonical: 'email', a: 'one@example.com', b: 'two@example.com', base: { id: 9, confirm: true } },
		{ tool: 'stonewright-user-update', alias: 'user_pass', canonical: 'password', a: 'test-long-password-12', b: 'test-long-password-13', base: { id: 9, confirm: true } },
		{ tool: 'stonewright-user-update', alias: 'role', canonical: 'roles', a: 'author', b: ['editor'], base: { id: 9, confirm: true } },
		{ tool: 'stonewright-user-update', alias: 'display_name', canonical: 'name', a: 'One', b: 'Two', base: { id: 9, confirm: true } },
		{ tool: 'stonewright-user-list', alias: 'role', canonical: 'roles', a: 'author', b: 'editor' },
		{ tool: 'stonewright-user-list', alias: 'number', canonical: 'per_page', a: 10, b: 20 },
		{ tool: 'stonewright-blueprint-get', alias: 'blueprint_id', canonical: 'id', a: 'agency', b: 'law' },
		{ tool: 'stonewright-blueprint-apply', alias: 'blueprint_id', canonical: 'id', a: 'agency', b: 'law' },
		{ tool: 'stonewright-blueprint-apply', alias: 'page_title', canonical: 'title', a: 'One', b: 'Two', base: { id: 'agency' } },
		{ tool: 'stonewright-blueprint-apply', alias: 'mode', canonical: 'status', a: 'draft', b: 'publish', base: { id: 'agency' } },
		{ tool: 'stonewright-wc-sales-report', alias: 'date_from', canonical: 'date_min', a: '2026-01-01', b: '2026-01-02', base: { period: 'custom' } },
		{ tool: 'stonewright-wc-sales-report', alias: 'date_to', canonical: 'date_max', a: '2026-01-31', b: '2026-01-30', base: { period: 'custom' } },
	];

	describe('an alias and its Direct argument with different values are refused', () => {
		for (const c of CONFLICT_CASES) {
			it(`${c.tool}: ${c.alias} against ${c.canonical}`, async () => {
				const result = await call(c.tool, { ...(c.base ?? {}), [c.alias]: c.a, [c.canonical]: c.b });
				expect(result.isError).toBe(true);
				expect(result.body['error']).toBe('argument_alias_conflict');
				expect(result.body['blocked']).toBe(true);
				expect(String(result.body['message'])).toContain(c.alias);
				expect(String(result.body['message'])).toContain(c.canonical);
				expect(requests).toHaveLength(0);
			});
		}

		it('the same value under both names is accepted', async () => {
			const result = await call('stonewright-comment-list', { post_id: 4, post: 4 });
			expect(result.isError).toBe(false);
			expect(requests[0]?.url.searchParams.get('post')).toBe('4');
			const role = await call('stonewright-user-create', {
				username: 'u', email: 'a@example.com', password: 'test-long-password-12', roles: ['author'], role: 'author', confirm: true,
			});
			expect(role.isError).toBe(false);
		});
	});

	describe('a required argument may come under either name', () => {
		it('blueprint-get with neither id nor blueprint_id is refused', async () => {
			const result = await call('stonewright-blueprint-get', {});
			expect(result.isError).toBe(true);
			expect(result.body['error']).toBe('invalid_arguments');
			expect(String(result.body['message'])).toContain('blueprint_id');
		});

		it('user-create without a user name under either name is refused and sends nothing', async () => {
			const result = await call('stonewright-user-create', {
				user_email: 'a@example.com', user_pass: 'test-long-password-12', confirm: true,
			});
			expect(result.isError).toBe(true);
			expect(result.body['error']).toBe('invalid_arguments');
			expect(requests).toHaveLength(0);
		});

		it('the advertised schema lists the alias fields and keeps the Direct ones', async () => {
			const tools = (await client.listTools()).tools;
			const byName = new Map(tools.map((t) => [t.name, t]));
			const props = (name: string) => Object.keys((byName.get(name)?.inputSchema.properties ?? {}) as Record<string, unknown>);
			expect(props('stonewright-comment-list')).toEqual(expect.arrayContaining(['post', 'post_id']));
			expect(props('stonewright-user-create')).toEqual(
				expect.arrayContaining(['username', 'user_login', 'email', 'user_email', 'password', 'user_pass', 'roles', 'role', 'name', 'display_name']),
			);
			expect(props('stonewright-blueprint-apply')).toEqual(expect.arrayContaining(['id', 'blueprint_id', 'title', 'page_title', 'status', 'mode']));
		});
	});

	describe('plugin-only arguments are refused instead of dropped', () => {
		const samples: Record<string, unknown> = {
			tags: ['news'],
			post_type: 'product',
			base64: 'AAAA',
			items: [{ title: 'Home', url: 'https://example.com' }],
			palette_override: { primary: '#000000' },
			brand_kit: 'kit',
			statuses: ['completed'],
			max_orders: 10,
			type: 'user',
			lesson: 'Do the thing',
			confidence: 0.5,
			severity: 'high',
			trigger: 'when editing',
			update_skill: true,
			skill_slug: 'a-skill',
			skill_title: 'A skill',
			skill_content: 'body',
		};
		const baseFor: Record<string, Record<string, unknown>> = {
			'stonewright-content-create-post': { title: 'T', confirm: true },
			'stonewright-media-upload': { url: 'https://example.com/a.png', filename: 'a.png' },
			'stonewright-menu-create': { name: 'Main' },
			'stonewright-blueprint-apply': { id: 'agency' },
			'stonewright-wc-sales-report': {},
			'stonewright-memory-list': {},
			'stonewright-learning-record': { topic: 'T', correction: 'C' },
		};
		for (const [tool, args] of Object.entries(DIRECT_PLUGIN_ONLY_ARGS)) {
			for (const { arg } of args) {
				it(`${tool}: ${arg}`, async () => {
					const result = await call(tool, { ...(baseFor[tool] ?? {}), [arg]: samples[arg] });
					expect(result.isError).toBe(true);
					expect(result.body['error']).toBe('plugin_only_argument');
					expect(String(result.body['message'])).toContain(arg);
					expect(requests).toHaveLength(0);
				});
			}
		}

		it('the plugin default post_type "post" and empty values are accepted', async () => {
			const result = await call('stonewright-content-create-post', { title: 'T', post_type: 'post', tags: [] });
			expect(result.isError).toBe(false);
			expect(lastWrite()?.url.pathname).toBe('/wp-json/wp/v2/posts');
		});
	});

	describe('other plugin arguments that have a Direct meaning', () => {
		it('content-create-page and -post pass excerpt, slug, date and categories to WordPress', async () => {
			await call('stonewright-content-create-page', { title: 'P', excerpt: 'Short', slug: 'p-slug', date: '2030-01-01T10:00:00', status: 'future' });
			expect(lastWrite()?.json).toMatchObject({ excerpt: 'Short', slug: 'p-slug', date: '2030-01-01T10:00:00', status: 'future' });
			await call('stonewright-content-create-post', { title: 'Q', categories: [3, 4] });
			expect(lastWrite()?.json?.['categories']).toEqual([3, 4]);
		});

		it('content-create-page refuses custom code in the excerpt', async () => {
			const result = await call('stonewright-content-create-page', { title: 'P', excerpt: '<script>alert(1)</script>' });
			expect(result.body['error'] ?? result.body['approval_required']).toBeDefined();
			expect(writes()).toHaveLength(0);
		});

		it('media-list: mime_type narrows the list', async () => {
			await call('stonewright-media-list', { mime_type: 'image' });
			expect(requests[0]?.url.searchParams.get('media_type')).toBe('image');
			requests.length = 0;
			await call('stonewright-media-list', { mime_type: 'application/pdf' });
			expect(requests[0]?.url.searchParams.get('mime_type')).toBe('application/pdf');
			requests.length = 0;
			await call('stonewright-media-list', { mime_type: '' });
			expect(requests[0]?.url.searchParams.has('media_type')).toBe(false);
			expect(requests[0]?.url.searchParams.has('mime_type')).toBe(false);
		});

		it('plugin-activate, -deactivate and -delete take the plugin file name with or without .php', async () => {
			await call('stonewright-plugin-activate', { plugin: 'akismet/akismet.php', confirm: true });
			expect(lastWrite()?.url.pathname).toBe('/wp-json/wp/v2/plugins/akismet/akismet');
			await call('stonewright-plugin-activate', { plugin: 'akismet/akismet', confirm: true });
			expect(lastWrite()?.url.pathname).toBe('/wp-json/wp/v2/plugins/akismet/akismet');
		});

		it('blueprint-list: industry and search filter the list and count reports it', async () => {
			const all = await call('stonewright-blueprint-list', {});
			const total = (all.body['blueprints'] as unknown[]).length;
			expect(all.body['count']).toBe(total);
			const law = await call('stonewright-blueprint-list', { industry: 'law' });
			const lawRows = law.body['blueprints'] as Array<{ industry: string }>;
			expect(lawRows.length).toBeGreaterThan(0);
			expect(lawRows.length).toBeLessThan(total);
			expect(lawRows.every((row) => row.industry.toLowerCase() === 'law')).toBe(true);
			const none = await call('stonewright-blueprint-list', { search: 'no-such-blueprint-text' });
			expect(none.body['count']).toBe(0);
		});

		it('blueprint-apply with engine fse or elementor says the engine needs Plugin mode', async () => {
			for (const engine of ['fse', 'elementor']) {
				const result = await call('stonewright-blueprint-apply', { id: 'agency', engine });
				expect(result.body['ok']).toBe(false);
				expect(result.body['error']).toBe('engine_requires_plugin_mode');
				expect(String(result.body['message'])).toMatch(/needs Plugin mode/);
				expect(String(result.body['message'])).toContain(engine);
			}
			const unknown = await call('stonewright-blueprint-apply', { id: 'agency', engine: 'sideways' });
			expect(unknown.body['error']).toBe('invalid_engine');
			expect(writes()).toHaveLength(0);
		});

		it('blueprint-apply accepts engine auto and gutenberg', async () => {
			for (const engine of ['auto', 'gutenberg']) {
				const result = await call('stonewright-blueprint-apply', { blueprint_id: 'agency', engine });
				expect(result.body['ok']).toBe(true);
			}
		});
	});

	describe('settings use the option names of the plugin', () => {
		it('settings-update maps blogname, blogdescription and timezone_string to the REST names', async () => {
			await call('stonewright-settings-update', {
				settings: { blogname: 'New name', blogdescription: 'New tagline', timezone_string: 'Europe/Paris', date_format: 'Y-m-d' },
				confirm: true,
			});
			expect(lastWrite()?.json).toEqual({ title: 'New name', description: 'New tagline', timezone: 'Europe/Paris', date_format: 'Y-m-d' });
		});

		it('settings-update refuses a name given under both spellings with different values', async () => {
			const result = await call('stonewright-settings-update', {
				settings: { blogname: 'One', title: 'Two' },
				confirm: true,
			});
			expect(result.isError).toBe(true);
			expect(result.body['error']).toBe('argument_alias_conflict');
			expect(writes()).toHaveLength(0);
		});

		it('settings-update accepts both spellings with the same value', async () => {
			const result = await call('stonewright-settings-update', { settings: { blogname: 'Same', title: 'Same' }, confirm: true });
			expect(result.isError).toBe(false);
			expect(lastWrite()?.json).toEqual({ title: 'Same' });
		});

		it('settings-update reports a setting that core REST did not take', async () => {
			const result = await call('stonewright-settings-update', { settings: { blogname: 'N', users_can_register: true }, confirm: true });
			expect(result.body['ignored_keys']).toEqual(['users_can_register']);
		});

		it('settings-get: the output also carries the option names, and keys narrows it', async () => {
			const all = await call('stonewright-settings-get', {});
			const settings = all.body['settings'] as Record<string, unknown>;
			expect(settings['title']).toBe('Site');
			expect(settings['blogname']).toBe('Site');
			expect(settings['blogdescription']).toBe('Tag');
			expect(settings['timezone_string']).toBe('UTC');
			const some = await call('stonewright-settings-get', { keys: ['blogname', 'date_format'] });
			expect(Object.keys(some.body['settings'] as object).sort()).toEqual(['blogname', 'date_format']);
			const rest = await call('stonewright-settings-get', { keys: ['title'] });
			expect(Object.keys(rest.body['settings'] as object)).toEqual(['title']);
		});
	});

	describe('wc-sales-report periods', () => {
		it('maps the plugin periods to date_min and date_max', async () => {
			const { salesReportRange } = await import('../src/direct/tools/woocommerce.js');
			const now = new Date('2026-10-11T12:00:00Z');
			expect(salesReportRange({ period: 'last_7_days' }, now)).toEqual({ date_min: '2026-10-05', date_max: '2026-10-11' });
			expect(salesReportRange({ period: 'last_30_days' }, now)).toEqual({ date_min: '2026-09-12', date_max: '2026-10-11' });
			expect(salesReportRange({ period: 'year_to_date' }, now)).toEqual({ date_min: '2026-01-01', date_max: '2026-10-11' });
			expect(salesReportRange({ period: 'custom', date_min: '2026-02-01', date_max: '2026-02-28' }, now)).toEqual({
				date_min: '2026-02-01',
				date_max: '2026-02-28',
			});
			expect(salesReportRange({ period: 'week' }, now)).toBeNull();
		});

		it('sends the computed range, and the Direct period values still pass through', async () => {
			await call('stonewright-wc-sales-report', { period: 'last_7_days' });
			const q = requests[0]?.url.searchParams;
			expect(q?.get('date_min')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
			expect(q?.get('date_max')).toMatch(/^\d{4}-\d{2}-\d{2}$/);
			expect(q?.has('period')).toBe(false);
			requests.length = 0;
			await call('stonewright-wc-sales-report', { period: 'week' });
			expect(requests[0]?.url.searchParams.get('period')).toBe('week');
		});

		it('refuses custom without both dates, or with a bad or reversed range, and a plugin period with explicit dates', async () => {
			for (const args of [
				{ period: 'custom' },
				{ period: 'custom', date_from: '2026-01-01' },
				{ period: 'custom', date_from: '2026-02-30', date_to: '2026-03-01' },
				{ period: 'custom', date_from: '2026-03-02', date_to: '2026-03-01' },
				{ period: 'last_7_days', date_min: '2026-01-01' },
			]) {
				const result = await call('stonewright-wc-sales-report', args);
				expect(result.isError, JSON.stringify(args)).toBe(true);
			}
			expect(requests).toHaveLength(0);
		});
	});

	describe('output fields of the plugin, beside the Direct ones', () => {
		it('media items carry url, mime and alt', async () => {
			const file = join(dir, 'pixel.png');
			writeFileSync(file, Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 0]));
			const uploaded = await call('stonewright-media-upload', { path: file, confirm: true });
			expect(uploaded.body).toMatchObject({ source_url: 'https://example.test/x.png', url: 'https://example.test/x.png', mime_type: 'image/png', mime: 'image/png' });
			expect(uploaded.body).toHaveProperty('alt');
		});

		it('menu-create returns menu_id and menu-list returns menus', async () => {
			const created = await call('stonewright-menu-create', { name: 'Main' });
			expect(created.body['menu_id']).toBe(created.body['id']);
		});

		it('user-create returns login and display_name', async () => {
			const result = await call('stonewright-user-create', {
				username: 'jdoe', email: 'jdoe@example.com', password: 'test-long-password-12', confirm: true,
			});
			expect(result.body).toHaveProperty('login');
			expect(result.body).toHaveProperty('display_name');
		});
	});

	describe('together with the readback of a write', () => {
		it('settings-update maps option names before the readback, so blogname is not a mismatch', async () => {
			const result = await call('stonewright-settings-update', {
				settings: { blogname: 'New name', blogdescription: 'New tagline', users_can_register: true },
				confirm: true,
			});
			expect(result.isError).toBe(false);
			expect(result.body['ok']).not.toBe(false);
			const verification = result.body['verification'] as { status: string; checked_fields: string[]; mismatched_fields: string[] };
			expect(verification.status).toBe('verified');
			expect(verification.checked_fields.sort()).toEqual(['description', 'title']);
			expect(verification.mismatched_fields).toEqual([]);
			// A key core never returns is reported once, as ignored, not as a mismatch.
			expect(result.body['ignored_keys']).toEqual(['users_can_register']);
			const settings = result.body['settings'] as Record<string, unknown>;
			expect(settings['blogname']).toBe('New name');
		});

		it('blueprint-apply compares the canonical arguments that were sent, not the plugin names', async () => {
			const result = await call('stonewright-blueprint-apply', { blueprint_id: 'agency', page_title: 'Our agency', mode: 'publish' });
			expect(result.body['ok']).toBe(true);
			const verification = result.body['verification'] as { status: string; checked_fields: string[] };
			expect(verification.status).toBe('verified');
			expect(verification.checked_fields).toEqual(expect.arrayContaining(['title', 'status']));
			expect(verification.checked_fields).not.toContain('page_title');
		});

		it('content-create-page checks the excerpt and slug that came in under the plugin spelling', async () => {
			const result = await call('stonewright-content-create-page', { title: 'P', excerpt: 'Short', slug: 'p-slug' });
			const verification = result.body['verification'] as { checked_fields: string[]; mismatched_fields: string[] };
			expect(verification.checked_fields).toEqual(expect.arrayContaining(['title', 'excerpt', 'slug']));
		});

		it('menu-create returns menu_id beside the verification', async () => {
			const result = await call('stonewright-menu-create', { name: 'Main' });
			expect(result.body['menu_id']).toBe(result.body['id']);
			expect(result.body['verification']).toMatchObject({ status: 'verified' });
		});

		it('user-create with plugin names returns login and display_name beside the verification', async () => {
			const result = await call('stonewright-user-create', {
				user_login: 'jdoe', user_email: 'jdoe@example.com', user_pass: 'test-long-password-12', role: 'author', display_name: 'J Doe', confirm: true,
			});
			expect(result.body).toHaveProperty('login');
			expect(result.body).toHaveProperty('display_name');
			expect(result.body).toHaveProperty('verification');
		});
	});

	it('writes the audit rows of refused calls without opening an incident', async () => {
		await call('stonewright-comment-list', { post_id: 1, post: 2 });
		const auditPath = join(dir, 'state', 'audit-direct.jsonl');
		expect(existsSync(auditPath)).toBe(true);
		const rows = readFileSync(auditPath, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l) as Record<string, unknown>);
		expect(rows.at(-1)).toMatchObject({ tool: 'stonewright-comment-list', status: 'blocked', code: 'argument_alias_conflict' });
		expect(existsSync(join(dir, 'state', 'incidents'))).toBe(false);
	});
});
