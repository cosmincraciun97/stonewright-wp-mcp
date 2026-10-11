import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { contentCreate, contentDelete, contentUpdate, contentRevisionDelete } from '../src/direct/tools/content.js';
import { templateCreate, templateDelete, templatePartCreate, templatePartDelete, templateUpdate } from '../src/direct/tools/templates.js';
import { globalStylesUpdate } from '../src/direct/tools/global-styles.js';
import { pluginActivate, pluginDeactivate, pluginDelete, pluginInstall } from '../src/direct/tools/plugins.js';
import { themeActivate } from '../src/direct/tools/themes.js';
import { settingsUpdate } from '../src/direct/tools/settings.js';
import { mediaDelete, mediaUpdate } from '../src/direct/tools/media.js';
import { taxonomyCreate, taxonomyDelete, taxonomyUpdate } from '../src/direct/tools/taxonomy.js';
import { menuCreate, menuDelete, menuItems, menuUpdate } from '../src/direct/tools/menus.js';
import { commentCreate, commentDelete, commentUpdate } from '../src/direct/tools/comments.js';
import { appPasswordCreate, appPasswordRevoke, userCreate, userDelete, userUpdate } from '../src/direct/tools/users.js';
import { widgetManage } from '../src/direct/tools/widgets.js';
import { acfFieldsUpdate } from '../src/direct/tools/acf.js';

type Row = Record<string, unknown>;

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

const RAW = new Set(['title', 'content', 'excerpt', 'caption']);

/** A WordPress that keeps what is written, so every readback sees the stored result. */
function makeWp() {
	const store = new Map<string, Row>();
	let nextId = 100;
	const calls: Array<{ method: string; path: string }> = [];
	// Fields the site silently ignores on write, to simulate a site that does not keep them.
	const dropped = new Set<string>();
	const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
	const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const parsed = new URL(String(url));
		const path = decodeURIComponent(parsed.pathname.replace(/^\/wp-json/, ''));
		const method = (init?.method ?? 'GET').toUpperCase();
		calls.push({ method, path });
		const body = init?.body ? (JSON.parse(String(init.body)) as Row) : {};
		for (const key of dropped) delete body[key];
		const existing = store.get(path);
		if (method === 'GET') return Promise.resolve(existing ? json(structuredClone(existing)) : json({ code: 'rest_not_found', message: 'nope' }, 404));
		if (method === 'DELETE') {
			if (!existing) return Promise.resolve(json({ code: 'rest_not_found', message: 'nope' }, 404));
			// Plugins and application passwords have no trash; everything else is trashed unless force is set.
			if (parsed.searchParams.get('force') === 'true' || /^\/wp\/v2\/plugins\/|application-passwords\//.test(path)) store.delete(path);
			else existing['status'] = 'trash';
			return Promise.resolve(json({ deleted: true, previous: existing }));
		}
		let row = existing;
		let key = path;
		if (!row) {
			const id = nextId++;
			const plugin = /^\/wp\/v2\/plugins$/.test(path) ? `${String(body['slug'])}/${String(body['slug'])}` : undefined;
			row = plugin ? { plugin } : /application-passwords$/.test(path) ? { uuid: `uuid-${id}`, password: 'fixture-generated-value' } : { id };
			key = plugin ? `/wp/v2/plugins/${plugin}` : /application-passwords$/.test(path) ? `${path}/uuid-${id}` : `${path}/${id}`;
			store.set(key, row);
		}
		for (const [field, value] of Object.entries(body)) {
			if (RAW.has(field) && typeof value === 'string') row[field] = { raw: value };
			else if ((field === 'meta' || field === 'acf') && value && typeof value === 'object') row[field] = { ...((row[field] as Row) ?? {}), ...(value as Row) };
			else row[field] = value;
		}
		return Promise.resolve(json(structuredClone(row), existing ? 200 : 201));
	});
	return { fetchImpl, store, calls, dropped };
}

describe('every typed Direct write is read back', () => {
	let stateDir: string;
	const saved: Record<string, string | undefined> = {};

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-verify-tools-'));
		for (const key of ['STONEWRIGHT_STATE_DIR', 'STONEWRIGHT_DIRECT_WRITES', 'STONEWRIGHT_DIRECT_PROBE']) saved[key] = process.env[key];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
		process.env['STONEWRIGHT_DIRECT_PROBE'] = 'off';
		delete process.env['STONEWRIGHT_DIRECT_WRITES'];
	});

	afterEach(() => {
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
	});

	function rows(): Row[] {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Row);
	}

	type Wp = ReturnType<typeof makeWp>;
	type Ctx = { client: WpRestClient; site: ResolvedSite; writeMode: 'on'; env: NodeJS.ProcessEnv };
	const ctxFor = (wp: Wp): Ctx => ({ client: new WpRestClient(site, { fetchImpl: wp.fetchImpl as never }), site, writeMode: 'on', env: process.env });

	const page = (id: number): Row => ({ id, title: { raw: 'T' }, content: { raw: 'c' }, status: 'publish', slug: `p-${id}`, meta: {}, link: `http://example.test/p-${id}/` });
	const seed = (wp: Wp) => {
		wp.store.set('/wp/v2/pages/4', page(4));
		wp.store.set('/wp/v2/posts/6', { ...page(6), acf: {} });
		wp.store.set('/wp/v2/pages/5/revisions/9', { id: 9 });
		wp.store.set('/wp/v2/templates/theme//home', { id: 'theme//home', title: { raw: 'Home' }, content: { raw: 'x' }, description: '' });
		wp.store.set('/wp/v2/templates/theme//old', { id: 'theme//old' });
		wp.store.set('/wp/v2/template-parts/theme//old', { id: 'theme//old' });
		wp.store.set('/wp/v2/global-styles/12', { id: 12, title: { raw: 'S' }, settings: {}, styles: { color: { text: '#000' } } });
		wp.store.set('/wp/v2/plugins/akismet/akismet', { plugin: 'akismet/akismet', status: 'inactive', name: 'Akismet', textdomain: 'akismet' });
		wp.store.set('/wp/v2/plugins/hello/hello', { plugin: 'hello/hello', status: 'inactive', name: 'Hello', textdomain: 'hello' });
		wp.store.set('/wp/v2/themes/twentytwentyfour', { stylesheet: 'twentytwentyfour', status: 'inactive' });
		wp.store.set('/wp/v2/settings', { title: 'Old', description: 'D' });
		wp.store.set('/wp/v2/media/7', { id: 7, title: { raw: 'm' }, alt_text: '', caption: { raw: '' } });
		wp.store.set('/wp/v2/categories/8', { id: 8, name: 'News', slug: 'news', description: '' });
		wp.store.set('/wp/v2/menus/3', { id: 3, name: 'Main', description: '' });
		wp.store.set('/wp/v2/menu-items/31', { id: 31, title: { raw: 'Home' }, url: 'http://example.test/', status: 'publish' });
		wp.store.set('/wp/v2/comments/41', { id: 41, content: { raw: 'hi' }, status: 'hold', post: 4 });
		wp.store.set('/wp/v2/users/5', { id: 5, name: 'Sam', email: 'sam@example.com', roles: ['editor'] });
		wp.store.set('/wp/v2/users/me', { id: 1, roles: ['administrator'] });
		wp.store.set('/wp/v2/users/5/application-passwords/uuid-1', { uuid: 'uuid-1', name: 'x' });
		wp.store.set('/wp/v2/widgets/text-2', { id: 'text-2', id_base: 'text', sidebar: 'sidebar-1', instance: { raw: { title: 'a' } } });
	};

	const writes: Array<[string, (c: Ctx) => Promise<unknown>, string]> = [
		['content-create', (c) => contentCreate(c, { kind: 'page', title: 'New', content: 'body', status: 'draft' }), 'pages'],
		['content-update', (c) => contentUpdate(c, { id: 4, content: 'changed', title: 'Changed' }), 'pages'],
		['content-delete (force)', (c) => contentDelete(c, { id: 4, force: true, confirm: true }), 'pages'],
		['content-delete (trash)', (c) => contentDelete(c, { id: 4 }), 'pages'],
		['content-revision-delete', (c) => contentRevisionDelete(c, { id: 5, revision_id: 9, confirm: true }), 'revisions'],
		['template-create', (c) => templateCreate(c, { slug: 'fresh', title: 'Fresh', content: '<p>x</p>', confirm: true }), 'templates'],
		['template-update', (c) => templateUpdate(c, { id: 'theme//home', content: 'y', confirm: true }), 'templates'],
		['template-delete', (c) => templateDelete(c, { id: 'theme//old', force: true, confirm: true }), 'templates'],
		['template-part-create', (c) => templatePartCreate(c, { slug: 'fresh', title: 'Fresh', content: '<p>x</p>', confirm: true }), 'template-parts'],
		['template-part-delete', (c) => templatePartDelete(c, { id: 'theme//old', force: true, confirm: true }), 'template-parts'],
		['global-styles-update', (c) => globalStylesUpdate(c, { id: '12', styles: { color: { text: '#111' } }, confirm: true }), 'global-styles'],
		['plugin-activate', (c) => pluginActivate(c, { plugin: 'akismet/akismet', confirm: true }), 'plugins'],
		['plugin-deactivate', (c) => pluginDeactivate(c, { plugin: 'hello/hello', confirm: true }), 'plugins'],
		['plugin-install', (c) => pluginInstall(c, { slug: 'wordpress-seo', confirm: true }), 'plugins'],
		['plugin-delete', (c) => pluginDelete(c, { plugin: 'hello/hello', confirm: true }), 'plugins'],
		['theme-activate', (c) => themeActivate(c, { stylesheet: 'twentytwentyfour', confirm: true }), 'themes'],
		['settings-update', (c) => settingsUpdate(c, { settings: { title: 'New' }, confirm: true }), 'settings'],
		['media-update', (c) => mediaUpdate(c, { id: 7, title: 'renamed', alt_text: 'alt' }), 'media'],
		['media-delete (force)', (c) => mediaDelete(c, { id: 7, force: true, confirm: true }), 'media'],
		['taxonomy-create', (c) => taxonomyCreate(c, { name: 'Events', slug: 'events' }), 'categories'],
		['taxonomy-update', (c) => taxonomyUpdate(c, { id: 8, name: 'Updates', description: 'd' }), 'categories'],
		['taxonomy-delete', (c) => taxonomyDelete(c, { id: 8, confirm: true }), 'categories'],
		['menu-create', (c) => menuCreate(c, { name: 'Footer' }), 'menus'],
		['menu-update', (c) => menuUpdate(c, { id: 3, name: 'Primary' }), 'menus'],
		['menu-delete', (c) => menuDelete(c, { id: 3, confirm: true }), 'menus'],
		['menu-items create', (c) => menuItems(c, { action: 'create', menu_id: 3, title: 'About', url: 'http://example.test/about' }), 'menu-items'],
		['menu-items update', (c) => menuItems(c, { action: 'update', id: 31, title: 'Start' }), 'menu-items'],
		['menu-items delete', (c) => menuItems(c, { action: 'delete', id: 31, confirm: true }), 'menu-items'],
		['comment-create', (c) => commentCreate(c, { post: 4, content: 'hello', confirm: true }), 'comments'],
		['comment-update', (c) => commentUpdate(c, { id: 41, status: 'approved', confirm: true }), 'comments'],
		['comment-delete (force)', (c) => commentDelete(c, { id: 41, force: true, confirm: true }), 'comments'],
		['user-create', (c) => userCreate(c, { username: 'new-user', email: 'new@example.com', password: 'fixture-new-user-pass', roles: ['subscriber'], confirm: true }), 'users'],
		['user-update', (c) => userUpdate(c, { id: 5, name: 'Samuel', confirm: true }), 'users'],
		['user-delete', (c) => userDelete(c, { id: 5, reassign: 1, confirm: true }), 'users'],
		['app-password-create', (c) => appPasswordCreate(c, { user_id: 5, name: 'ci', confirm: true }), 'application-passwords'],
		['app-password-revoke', (c) => appPasswordRevoke(c, { user_id: 5, uuid: 'uuid-1', confirm: true }), 'application-passwords'],
		['widget-create', (c) => widgetManage(c, { action: 'create', id_base: 'text', sidebar: 'sidebar-1', instance: { raw: { title: 'b' } }, confirm: true }), 'widgets'],
		['widget-update', (c) => widgetManage(c, { action: 'update', id: 'text-2', sidebar: 'sidebar-2', confirm: true }), 'widgets'],
		['widget-delete', (c) => widgetManage(c, { action: 'delete', id: 'text-2', confirm: true }), 'widgets'],
		['acf-fields-update', (c) => acfFieldsUpdate(c, { id: 6, acf: { subtitle: 'x' } }), 'posts'],
	];

	it.each(writes)('%s: reads the resource back, returns verification and audits both hashes', async (name, call, resourceType) => {
		const wp = makeWp();
		seed(wp);
		const out = (await call(ctxFor(wp))) as Row;
		const verification = out['verification'] as Row | undefined;
		expect(verification, JSON.stringify(out)).toBeDefined();
		expect(verification?.['status'], JSON.stringify(verification)).toBe('verified');
		expect(out['ok']).not.toBe(false);
		// The last request of a write is its readback: a GET for the resource, or for its absence.
		expect(wp.calls.at(-1)?.method).toBe('GET');
		const [write, verifier] = rows().filter((row) => row['event_type'] === 'direct_tool' || row['event_type'] === 'direct_verifier').slice(-2);
		expect(write, name).toMatchObject({ status: 'ok', event_type: 'direct_tool', resource_type: resourceType, verification_status: 'verified', effect_verified: true });
		expect(write?.['change_set_id']).toBe(verification?.['change_set_id']);
		expect(verifier, name).toMatchObject({ status: 'ok', event_type: 'direct_verifier', verification_status: 'verified', parent_request_id: write?.['event_id'] });
		expect(JSON.stringify(rows())).not.toContain('fixture-generated-value');
	});

	it.each([
		['content-update', (c: Ctx) => contentUpdate(c, { id: 4, content: 'changed' }), ['content'], 'content'],
		['template-update', (c: Ctx) => templateUpdate(c, { id: 'theme//home', content: 'y', confirm: true }), ['content'], 'content'],
		['global-styles-update', (c: Ctx) => globalStylesUpdate(c, { id: '12', styles: { color: { text: '#111' } }, confirm: true }), ['styles'], 'styles'],
		['settings-update', (c: Ctx) => settingsUpdate(c, { settings: { title: 'New' }, confirm: true }), ['title'], 'title'],
		['plugin-activate', (c: Ctx) => pluginActivate(c, { plugin: 'akismet/akismet', confirm: true }), ['status'], 'status'],
		['comment-update', (c: Ctx) => commentUpdate(c, { id: 41, status: 'approved', confirm: true }), ['status'], 'status'],
		['acf-fields-update', (c: Ctx) => acfFieldsUpdate(c, { id: 6, acf: { subtitle: 'x' } }), ['acf.subtitle'], 'acf'],
	])('%s: a field the site did not keep is reported as a mismatch, never as plain success', async (_name, call, fields, dropField) => {
		const wp = makeWp();
		seed(wp);
		wp.dropped.add(dropField);
		const out = (await call(ctxFor(wp))) as Row;
		expect(out).toMatchObject({ ok: false, error: 'verification_mismatch', written: true });
		expect((out['verification'] as Row)['status']).toBe('mismatch');
		expect((out['verification'] as Row)['mismatched_fields']).toEqual(fields);
		const verifier = rows().filter((row) => row['event_type'] === 'direct_verifier').at(-1);
		expect(verifier).toMatchObject({ status: 'error', code: 'verification_mismatch', verification_status: 'mismatch', effect_verified: false });
	});

	it('a delete that left the resource in place is a mismatch', async () => {
		const wp = makeWp();
		seed(wp);
		const original = wp.fetchImpl.getMockImplementation() as typeof fetch;
		wp.fetchImpl.mockImplementation(((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			if ((init?.method ?? 'GET') === 'DELETE') return Promise.resolve(new Response(JSON.stringify({ deleted: true }), { status: 200, headers: { 'content-type': 'application/json' } }));
			return original(url, init);
		}) as never);
		const out = (await contentDelete(ctxFor(wp), { id: 4, force: true, confirm: true })) as Row;
		expect(out).toMatchObject({ ok: false, error: 'verification_mismatch' });
		expect((out['verification'] as Row)['mismatched_fields']).toEqual(['resource_still_present']);
	});

	it('records the pre-write hash and a different post-write hash for an update', async () => {
		const wp = makeWp();
		seed(wp);
		const out = (await contentUpdate(ctxFor(wp), { id: 4, content: 'changed' })) as Row;
		const verification = out['verification'] as Row;
		expect(verification['before_sha256']).toMatch(/^[0-9a-f]{64}$/);
		expect(verification['after_sha256']).toMatch(/^[0-9a-f]{64}$/);
		expect(verification['before_sha256']).not.toBe(verification['after_sha256']);
		expect(out['before_sha256']).toBe(verification['before_sha256']);
	});

	it('reports audit_written:false, and still returns the result, when the audit cannot be written', async () => {
		const wp = makeWp();
		seed(wp);
		// A folder where the audit file should be makes every append fail.
		mkdirSync(join(stateDir, 'audit-direct.jsonl'));
		const out = (await contentUpdate(ctxFor(wp), { id: 4, content: 'changed' })) as Row;
		expect(out['ok']).not.toBe(false);
		expect(out['verification']).toMatchObject({ status: 'verified', audit_written: false });
	});

	it('keeps the write unverified, not failed, when the readback cannot be read', async () => {
		const wp = makeWp();
		seed(wp);
		const original = wp.fetchImpl.getMockImplementation() as typeof fetch;
		let writes = 0;
		wp.fetchImpl.mockImplementation(((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			if ((init?.method ?? 'GET') !== 'GET') writes += 1;
			if ((init?.method ?? 'GET') === 'GET' && writes > 0) return Promise.resolve(new Response('{"code":"rest_forbidden","message":"no"}', { status: 403 }));
			return original(url, init);
		}) as never);
		const out = (await contentUpdate(ctxFor(wp), { id: 4, content: 'changed' })) as Row;
		expect(out['ok']).not.toBe(false);
		expect((out['verification'] as Row)['status']).toBe('not_checked');
		expect(String((out['verification'] as Row)['reason'])).toMatch(/readback_failed/);
	});
});
