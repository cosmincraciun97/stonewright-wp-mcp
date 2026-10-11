import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import type { DirectWriteMode } from '../src/direct/writes.js';
import { acfFieldsUpdate } from '../src/direct/tools/acf.js';
import { mediaUpdate, mediaUpload } from '../src/direct/tools/media.js';
import { menuCreate, menuItems, menuUpdate } from '../src/direct/tools/menus.js';
import { commentCreate, commentUpdate } from '../src/direct/tools/comments.js';
import { taxonomyCreate, taxonomyUpdate } from '../src/direct/tools/taxonomy.js';
import { userCreate, userUpdate, appPasswordCreate } from '../src/direct/tools/users.js';
import { settingsUpdate } from '../src/direct/tools/settings.js';
import { scanTextFields } from '../src/direct/custom-code-guard.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'editor-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

type Call = { method: string; path: string; body: unknown };

function makeFetch() {
	const calls: Call[] = [];
	const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
		const parsed = new URL(String(url));
		calls.push({
			method: (init?.method ?? 'GET').toUpperCase(),
			path: parsed.pathname.replace(/^\/wp-json/, ''),
			body: init?.body && typeof init.body === 'string' ? (JSON.parse(init.body) as unknown) : undefined,
		});
		return Promise.resolve(
			new Response(JSON.stringify({ id: 5, name: 'ok', title: 'ok', alt_text: '', settings: {} }), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			}),
		);
	});
	return { fetchImpl, calls };
}

const SCRIPT = '<script>alert(1)</script>';
const HANDLER = '<img src="a.png" onerror="alert(1)">';
const JS_LINK = '<a href="javascript:alert(1)">go</a>';

describe('custom-code boundary on the remaining Direct writes', () => {
	let stateDir: string;
	let saved: Record<string, string | undefined>;

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-boundary-'));
		saved = { STONEWRIGHT_STATE_DIR: process.env['STONEWRIGHT_STATE_DIR'] };
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});

	afterEach(() => {
		for (const [key, value] of Object.entries(saved)) {
			if (value === undefined) delete process.env[key];
			else process.env[key] = value;
		}
		rmSync(stateDir, { recursive: true, force: true });
		vi.restoreAllMocks();
	});

	function ctx(fetchImpl: typeof fetch, writeMode: DirectWriteMode = 'on') {
		return { client: new WpRestClient(site, { fetchImpl }), site, writeMode, env: process.env, fetchImpl };
	}

	function auditRows(): Array<Record<string, unknown>> {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Record<string, unknown>);
	}

	function expectRefused(result: unknown, tool: string, kind?: string) {
		expect(result).toMatchObject({
			supported: false,
			approval_required: true,
			agent_must_stop: true,
			error: 'custom_code_approval_required',
			tool,
			written: false,
		});
		if (kind) {
			const findings = (result as { findings: Array<{ kind: string }> }).findings.map((f) => f.kind);
			expect(findings).toContain(kind);
		}
		expect(auditRows().at(-1)).toMatchObject({ tool, status: 'blocked', code: 'custom_code_approval_required' });
	}

	type Case = {
		label: string;
		tool: string;
		run: (c: ReturnType<typeof ctx>) => Promise<unknown>;
		kind?: string;
	};

	const refused: Case[] = [
		{ label: 'acf value', tool: 'stonewright-acf-fields-update', kind: 'script', run: (c) => acfFieldsUpdate(c, { id: 3, acf: { intro: SCRIPT } }) },
		{ label: 'acf nested repeater value', tool: 'stonewright-acf-fields-update', kind: 'event_handler', run: (c) => acfFieldsUpdate(c, { id: 3, acf: { rows: [{ cells: [{ html: HANDLER }] }] } }) },
		{ label: 'acf custom css key', tool: 'stonewright-acf-fields-update', kind: 'custom_css_key', run: (c) => acfFieldsUpdate(c, { id: 3, acf: { custom_css: 'body{display:none}' } }) },
		{ label: 'media title', tool: 'stonewright-media-update', kind: 'script', run: (c) => mediaUpdate(c, { id: 8, title: SCRIPT }) },
		{ label: 'media alt text', tool: 'stonewright-media-update', kind: 'event_handler', run: (c) => mediaUpdate(c, { id: 8, alt_text: HANDLER }) },
		{ label: 'media caption', tool: 'stonewright-media-update', kind: 'javascript_url', run: (c) => mediaUpdate(c, { id: 8, caption: JS_LINK }) },
		{ label: 'media upload title (before any download)', tool: 'stonewright-media-upload', kind: 'script', run: (c) => mediaUpload(c, { url: 'https://cdn.example.com/a.png', title: SCRIPT }) },
		{ label: 'media upload caption (before any file is read)', tool: 'stonewright-media-upload', kind: 'event_handler', run: (c) => mediaUpload(c, { path: 'does-not-matter.png', caption: HANDLER }) },
		{ label: 'menu create name', tool: 'stonewright-menu-create', kind: 'script', run: (c) => menuCreate(c, { name: SCRIPT }) },
		{ label: 'menu create description', tool: 'stonewright-menu-create', kind: 'event_handler', run: (c) => menuCreate(c, { name: 'Main', description: HANDLER }) },
		{ label: 'menu update description', tool: 'stonewright-menu-update', kind: 'javascript_url', run: (c) => menuUpdate(c, { id: 2, description: JS_LINK }) },
		{ label: 'menu item title', tool: 'stonewright-menu-items', kind: 'script', run: (c) => menuItems(c, { action: 'create', menu_id: 2, title: SCRIPT }) },
		{ label: 'menu item javascript url', tool: 'stonewright-menu-items', kind: 'javascript_url', run: (c) => menuItems(c, { action: 'create', menu_id: 2, title: 'Go', url: 'javascript:alert(1)' }) },
		{ label: 'menu item spaced javascript url', tool: 'stonewright-menu-items', kind: 'javascript_url', run: (c) => menuItems(c, { action: 'update', id: 9, url: ' JaVa\tScRiPt:alert(1)' }) },
		{ label: 'menu item update title', tool: 'stonewright-menu-items', kind: 'event_handler', run: (c) => menuItems(c, { action: 'update', id: 9, title: HANDLER }) },
		{ label: 'comment create content', tool: 'stonewright-comment-create', kind: 'script', run: (c) => commentCreate(c, { post: 1, content: SCRIPT }) },
		{ label: 'comment create author name', tool: 'stonewright-comment-create', kind: 'event_handler', run: (c) => commentCreate(c, { post: 1, content: 'hi', author_name: HANDLER }) },
		{ label: 'comment update content', tool: 'stonewright-comment-update', kind: 'javascript_url', run: (c) => commentUpdate(c, { id: 4, content: JS_LINK }) },
		{ label: 'term create description', tool: 'stonewright-taxonomy-terms', kind: 'script', run: (c) => taxonomyCreate(c, { name: 'News', description: SCRIPT }) },
		{ label: 'term update description', tool: 'stonewright-taxonomy-terms', kind: 'event_handler', run: (c) => taxonomyUpdate(c, { id: 6, description: HANDLER }) },
		{ label: 'term create name on a custom taxonomy', tool: 'stonewright-taxonomy-terms', kind: 'script', run: (c) => taxonomyCreate(c, { taxonomy: 'genre', name: SCRIPT }) },
		{ label: 'user create display name', tool: 'stonewright-user-create', kind: 'script', run: (c) => userCreate(c, { username: 'newbie', email: 'a@example.com', password: 'a-long-password-1', name: SCRIPT }) },
		{ label: 'user update display name', tool: 'stonewright-user-update', kind: 'event_handler', run: (c) => userUpdate(c, { id: 9, name: HANDLER }) },
		{ label: 'app password name', tool: 'stonewright-app-password-create', kind: 'script', run: (c) => appPasswordCreate(c, { user_id: 1, name: SCRIPT, confirm: true }) },
		{ label: 'settings title', tool: 'stonewright-settings-update', kind: 'script', run: (c) => settingsUpdate(c, { settings: { title: SCRIPT }, confirm: true }) },
		{ label: 'settings description', tool: 'stonewright-settings-update', kind: 'event_handler', run: (c) => settingsUpdate(c, { settings: { description: HANDLER }, confirm: true }) },
		{ label: 'settings option-name title', tool: 'stonewright-settings-update', kind: 'javascript_url', run: (c) => settingsUpdate(c, { settings: { blogname: JS_LINK }, confirm: true }) },
	];

	it.each(refused)('refuses $label and sends nothing', async ({ tool, run, kind }) => {
		const { fetchImpl, calls } = makeFetch();
		const result = await run(ctx(fetchImpl));
		expectRefused(result, tool, kind);
		expect(calls).toHaveLength(0);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('refuses in confirm mode too, once the write is confirmed', async () => {
		const { fetchImpl, calls } = makeFetch();
		const result = await commentCreate(ctx(fetchImpl, 'confirm'), { post: 1, content: SCRIPT, confirm: true });
		expectRefused(result, 'stonewright-comment-create', 'script');
		expect(calls).toHaveLength(0);
	});

	const allowed: Array<{ label: string; method: string; path: RegExp; run: (c: ReturnType<typeof ctx>) => Promise<unknown> }> = [
		{ label: 'acf plain values', method: 'POST', path: /\/wp\/v2\/posts\/3$/, run: (c) => acfFieldsUpdate(c, { id: 3, acf: { intro: '<p>Hello <strong>there</strong></p>', rows: [{ a: 'b' }], n: 4 } }) },
		{ label: 'media plain text', method: 'PUT', path: /\/wp\/v2\/media\/8$/, run: (c) => mediaUpdate(c, { id: 8, title: 'Hero', alt_text: 'A small boat', caption: 'Photo: <em>Anna</em>' }) },
		{ label: 'menu item with a relative url', method: 'POST', path: /\/wp\/v2\/menu-items$/, run: (c) => menuItems(c, { action: 'create', menu_id: 2, title: 'About', url: '/about' }) },
		{ label: 'comment with a link', method: 'POST', path: /\/wp\/v2\/comments$/, run: (c) => commentCreate(c, { post: 1, content: 'See <a href="https://example.com/x">this</a>', confirm: true }) },
		{ label: 'term description', method: 'POST', path: /\/wp\/v2\/categories$/, run: (c) => taxonomyCreate(c, { name: 'News', description: 'Company news and updates' }) },
		{ label: 'settings title', method: 'POST', path: /\/wp\/v2\/settings$/, run: (c) => settingsUpdate(c, { settings: { title: 'Example Studio', description: 'Just another site' }, confirm: true }) },
	];

	it.each(allowed)('passes $label through', async ({ method, path, run }) => {
		const { fetchImpl, calls } = makeFetch();
		const result = await run(ctx(fetchImpl));
		expect(result).not.toMatchObject({ error: 'custom_code_approval_required' });
		expect(calls.map((call) => call.method)).toContain(method);
		expect(calls.some((call) => call.method === method && path.test(call.path))).toBe(true);
	});

	describe('scanTextFields', () => {
		it('reads a bare javascript: url and entity-hidden forms as code, and plain words as text', () => {
			expect(scanTextFields({ url: 'javascript:alert(1)' }).map((f) => f.kind)).toEqual(['javascript_url']);
			expect(scanTextFields({ url: '&#106;avascript&colon;alert(1)' }).map((f) => f.kind)).toEqual(['javascript_url']);
			expect(scanTextFields({ title: 'How to use javascript: a primer' })).toEqual([]);
			expect(scanTextFields({ title: 'Use &lt;script&gt; carefully', url: '/about' })).toEqual([]);
		});

		it('names the field in the finding path', () => {
			expect(scanTextFields({ caption: SCRIPT })).toEqual([{ kind: 'script', path: 'caption' }]);
		});
	});
});
