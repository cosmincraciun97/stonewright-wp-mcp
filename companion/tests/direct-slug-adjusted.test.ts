import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { existsSync, mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { contentCreate } from '../src/direct/tools/content.js';
import { attachVerification, compareSent, verifyWrite } from '../src/direct/verify.js';

type Row = Record<string, unknown>;

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin-user',
	appPassword: 'fixture-app-pass-0001',
	disabledTools: [],
};

describe('compareSent with a WordPress-adjusted slug', () => {
	it('counts the numeric suffix WordPress adds to a taken slug as expected, and reports it', () => {
		const out = compareSent({ title: 'T', slug: 'v4-test' }, { title: { raw: 'T' }, slug: 'v4-test-2' }, { allowSlugSuffix: true });
		expect(out.mismatched).toEqual([]);
		expect(out.checked).toEqual(['title', 'slug']);
		expect(out.slugAdjusted).toEqual({ requested: 'v4-test', stored: 'v4-test-2' });
	});

	it('still reports a stored slug that is not the sent one with a numeric suffix', () => {
		for (const stored of ['other', 'v4-test-x', 'v4-test-1', 'v4-test-0', 'v4-test-2-extra', 'v4-testing-2', '']) {
			const out = compareSent({ slug: 'v4-test' }, { slug: stored }, { allowSlugSuffix: true });
			expect(out.mismatched, stored).toEqual(['slug']);
			expect(out.slugAdjusted, stored).toBeUndefined();
		}
	});

	it('keeps the strict comparison when the suffix is not allowed, as the backup comparison needs', () => {
		const out = compareSent({ slug: 'v4-test' }, { slug: 'v4-test-2' });
		expect(out.mismatched).toEqual(['slug']);
	});

	it('reports nothing extra when the slug is stored as sent', () => {
		const out = compareSent({ slug: 'V4 Test' }, { slug: 'v4-test' }, { allowSlugSuffix: true });
		expect(out.mismatched).toEqual([]);
		expect(out.slugAdjusted).toBeUndefined();
	});
});

describe('verifyWrite with a WordPress-adjusted slug', () => {
	let stateDir: string;
	const saved = process.env['STONEWRIGHT_STATE_DIR'];

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-slug-'));
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});
	afterEach(() => {
		if (saved === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = saved;
		rmSync(stateDir, { recursive: true, force: true });
	});

	function rows(): Row[] {
		const path = join(stateDir, 'audit-direct.jsonl');
		if (!existsSync(path)) return [];
		return readFileSync(path, 'utf8').split('\n').filter(Boolean).map((line) => JSON.parse(line) as Row);
	}

	it('is verified, carries slug_adjusted, and records no mismatch in the audit', async () => {
		const client = { get: () => Promise.resolve(({ id: 4, title: { raw: 'T' }, slug: 'v4-test-2' }) as never) };
		const out = await verifyWrite({
			client,
			site: { alias: 'local', url: 'http://example.test' },
			tool: 'stonewright-content-create-page',
			resource: 'pages/4',
			resourceType: 'pages',
			readPath: '/wp/v2/pages/4',
			sent: { title: 'T', slug: 'v4-test' },
		});
		expect(out.status).toBe('verified');
		expect(out.mismatched_fields).toEqual([]);
		expect(out.slug_adjusted).toEqual({ requested: 'v4-test', stored: 'v4-test-2' });

		const shown = attachVerification({ id: 4, slug: 'v4-test-2' }, out) as Record<string, unknown>;
		expect(shown['ok']).toBeUndefined();
		expect(shown['error']).toBeUndefined();
		expect(shown['verification']).toMatchObject({ status: 'verified', slug_adjusted: { requested: 'v4-test', stored: 'v4-test-2' } });

		const audit = rows();
		expect(audit.some((row) => row['code'] === 'verification_mismatch')).toBe(false);
		expect(JSON.stringify(audit)).toContain('slug adjusted');
	});
});

describe('Direct content writes when WordPress adjusts the slug', () => {
	let stateDir: string;
	const saved: Record<string, string | undefined> = {};

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-slug-tools-'));
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

	/** A site where the slug `v4-test` is taken: it keeps the page under `v4-test-2`. */
	function wpWithTakenSlug() {
		const store = new Map<string, Row>();
		const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
		const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const parsed = new URL(String(url));
			const path = decodeURIComponent(parsed.pathname.replace(/^\/wp-json/, ''));
			const method = (init?.method ?? 'GET').toUpperCase();
			const body = init?.body ? (JSON.parse(String(init.body)) as Row) : {};
			if (method === 'GET') {
				const row = store.get(path);
				return Promise.resolve(row ? json(structuredClone(row)) : json({ code: 'rest_not_found', message: 'nope' }, 404));
			}
			const creating = path === '/wp/v2/pages';
			const id = creating ? 100 : Number(path.split('/').pop());
			const key = creating ? `/wp/v2/pages/${id}` : path;
			const row: Row = store.get(key) ?? { id, status: 'draft', slug: 'v4-test-2', link: `http://example.test/?page_id=${id}` };
			for (const [field, value] of Object.entries(body)) {
				if (field === 'slug') row['slug'] = `${String(value)}-2`;
				else if ((field === 'title' || field === 'content') && typeof value === 'string') row[field] = { raw: value };
				else row[field] = value;
			}
			store.set(key, row);
			return Promise.resolve(json(structuredClone(row), creating ? 201 : 200));
		});
		return { fetchImpl, store };
	}

	const ctxFor = (wp: ReturnType<typeof wpWithTakenSlug>) => ({
		client: new WpRestClient(site, { fetchImpl: wp.fetchImpl as never }),
		site,
		writeMode: 'on' as const,
		env: process.env,
	});

	it('content-create-page answers ok with slug_adjusted, not verification_mismatch', async () => {
		const wp = wpWithTakenSlug();

		const result = (await contentCreate(ctxFor(wp), { kind: 'page', title: 'T', content: 'body', slug: 'v4-test', status: 'draft' })) as Row;

		expect(result['ok']).not.toBe(false);
		expect(result['error']).toBeUndefined();
		expect(result['slug']).toBe('v4-test-2');
		expect(result['verification']).toMatchObject({
			status: 'verified',
			mismatched_fields: [],
			slug_adjusted: { requested: 'v4-test', stored: 'v4-test-2' },
		});
	});
});
