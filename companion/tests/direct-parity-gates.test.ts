import { afterEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import {
	applyBlueprint,
	getBlueprint,
	listBlueprints,
} from '../src/direct/tools/blueprints.js';
import {
	acfFieldsGet,
	acfFieldsUpdate,
	seoHeadGet,
} from '../src/direct/tools/acf.js';
import {
	markTaskStartSeen,
	resetTaskStartSeenForTests,
} from '../src/direct/writes.js';

const localSite: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

const remoteSite: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('direct blueprint engine gate', () => {
	const dirs: string[] = [];
	const originalHome = process.env.HOME;
	const originalWrites = process.env.STONEWRIGHT_DIRECT_WRITES;

	afterEach(() => {
		if (originalHome === undefined) {
			delete process.env.HOME;
		} else {
			process.env.HOME = originalHome;
		}
		if (originalWrites === undefined) {
			delete process.env.STONEWRIGHT_DIRECT_WRITES;
		} else {
			process.env.STONEWRIGHT_DIRECT_WRITES = originalWrites;
		}
		for (const dir of dirs.splice(0)) {
			rmSync(dir, { recursive: true, force: true });
		}
		vi.restoreAllMocks();
	});

	function homeDir(): string {
		const dir = mkdtempSync(join(tmpdir(), 'sw-home-'));
		dirs.push(dir);
		process.env.HOME = dir;
		return dir;
	}

	it('lists shipped blueprints and can load one by id', () => {
		const listed = listBlueprints();
		expect(listed.length).toBeGreaterThan(0);
		const agency = listed.find((bp) => bp.id === 'agency');
		expect(agency).toBeDefined();
		expect(getBlueprint('agency')).toMatchObject({ id: 'agency' });
		expect(getBlueprint('nope-missing')).toBeNull();
	});

	it('rejects engine: elementor with elementor_requires_plugin', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await applyBlueprint(
			client,
			{ id: 'agency', engine: 'elementor' },
			process.env,
		);
		expect(result.ok).toBe(false);
		expect(result.error).toBe('elementor_requires_plugin');
		expect(String(result.message)).toMatch(/Elementor engine requires the Stonewright plugin/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('rejects other non-gutenberg engines with elementor_requires_plugin', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await applyBlueprint(
			client,
			{ id: 'agency', engine: 'bricks' },
			process.env,
		);
		expect(result).toMatchObject({ ok: false, error: 'elementor_requires_plugin' });
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('returns not_found for unknown blueprint ids (gutenberg path)', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await applyBlueprint(client, { id: 'does-not-exist' }, process.env);
		expect(result).toMatchObject({ ok: false, error: 'not_found' });
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('tags successful applies as gutenberg engine', async () => {
		homeDir();
		process.env.STONEWRIGHT_DIRECT_WRITES = 'on';
		const fetchImpl = vi.fn((_url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			expect(init?.method).toBe('POST');
			const body = JSON.parse(String(init?.body)) as Record<string, unknown>;
			expect(body.title).toBeTruthy();
			expect(String(body.content)).toContain('<!-- wp:');
			expect(body.status).toBe('draft');
			return Promise.resolve(
				new Response(JSON.stringify({ id: 42, title: { raw: body.title } }), {
					status: 201,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await applyBlueprint(
			client,
			{ id: 'agency', title: 'Agency Landing', engine: 'gutenberg' },
			process.env,
		);
		expect(result.ok).toBe(true);
		expect(result.engine).toBe('gutenberg');
		expect(result.mode).toBe('direct');
		expect(result.post_id).toBe(42);
		expect(result.page_id).toBe(42);
		expect(result.blueprint_id).toBe('agency');
		expect(result.created).toBe(true);
		expect(String(result.note)).toMatch(/Elementor requires plugin/i);
		expect(fetchImpl).toHaveBeenCalledOnce();
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/pages');
	});

	it('allows engine auto as gutenberg create path', async () => {
		homeDir();
		process.env.STONEWRIGHT_DIRECT_WRITES = 'on';
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ id: 7 }), {
					status: 201,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await applyBlueprint(client, { id: 'agency', engine: 'auto' }, process.env);
		expect(result).toMatchObject({ ok: true, engine: 'gutenberg', post_id: 7 });
	});

	it('updates existing post_id as gutenberg and requires confirm when destructive on remote', async () => {
		homeDir();
		// Remote host defaults to confirm mode; post_id path is destructive.
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(remoteSite, { fetchImpl });
		await expect(
			applyBlueprint(client, { id: 'agency', post_id: 9 }, process.env),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();

		const fetchOk = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ id: 9 }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const clientOk = new WpRestClient(remoteSite, { fetchImpl: fetchOk });
		const result = await applyBlueprint(
			clientOk,
			{ id: 'agency', post_id: 9, confirm: true },
			process.env,
		);
		expect(result).toMatchObject({
			ok: true,
			engine: 'gutenberg',
			post_id: 9,
			page_id: 9,
			mode: 'direct',
		});
		expect(String(fetchOk.mock.calls[0]?.[0])).toContain('/wp/v2/pages/9');
	});
});

describe('direct acf fields tools', () => {
	const dirs: string[] = [];
	const originalHome = process.env.HOME;

	afterEach(() => {
		if (originalHome === undefined) {
			delete process.env.HOME;
		} else {
			process.env.HOME = originalHome;
		}
		for (const dir of dirs.splice(0)) {
			rmSync(dir, { recursive: true, force: true });
		}
		vi.restoreAllMocks();
	});

	function homeDir(): string {
		const dir = mkdtempSync(join(tmpdir(), 'sw-home-'));
		dirs.push(dir);
		process.env.HOME = dir;
		return dir;
	}

	it('returns acf object from REST edit context on happy path', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(
					JSON.stringify({
						id: 11,
						acf: { headline: 'Hello', score: 9 },
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await acfFieldsGet(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 11 },
		);
		expect(result).toEqual({
			id: 11,
			acf: { headline: 'Hello', score: 9 },
			source: 'rest',
		});
		const url = String(fetchImpl.mock.calls[0]?.[0]);
		expect(url).toContain('/wp/v2/posts/11');
		expect(url).toContain('context=edit');
	});

	it('uses pages rest base when type is page', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ id: 3, acf: { k: 'v' } }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		await acfFieldsGet({ client, site: localSite, writeMode: 'on' }, { id: 3, type: 'page' });
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/pages/3');
	});

	it('returns null acf with hint when REST body has no acf key', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ id: 11, title: { rendered: 'X' } }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await acfFieldsGet(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 11 },
		);
		expect(result.acf).toBeNull();
		expect(String((result as { hint?: string }).hint)).toMatch(/Show in REST/i);
	});

	it('updates acf fields via REST post body on happy path', async () => {
		homeDir();
		const fetchImpl = vi.fn((_url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			expect(init?.method).toBe('POST');
			expect(JSON.parse(String(init?.body))).toEqual({ acf: { headline: 'Updated' } });
			return Promise.resolve(
				new Response(
					JSON.stringify({ id: 11, acf: { headline: 'Updated' } }),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				),
			);
		});
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await acfFieldsUpdate(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 11, acf: { headline: 'Updated' } },
		);
		expect(result).toEqual({ id: 11, acf: { headline: 'Updated' }, ok: true });
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/posts/11');
	});

	it('blocks acf update when writeMode is off', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(localSite, { fetchImpl });
		await expect(
			acfFieldsUpdate(
				{ client, site: localSite, writeMode: 'off' },
				{ id: 11, acf: { headline: 'X' } },
			),
		).rejects.toThrow(/writes are disabled/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('blocks acf update when task-start gate is not latched', async () => {
		// Override the shared fixture latch for this case.
		resetTaskStartSeenForTests();
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(localSite, { fetchImpl });
		await expect(
			acfFieldsUpdate(
				{ client, site: localSite, writeMode: 'on' },
				{ id: 11, acf: { headline: 'X' } },
			),
		).rejects.toThrow(/task-start/i);
		expect(fetchImpl).not.toHaveBeenCalled();
		// Restore for subsequent tests in this file.
		markTaskStartSeen();
		markTaskStartSeen('local');
		markTaskStartSeen('remote');
	});

	it('blocks acf tools disabled via sites.json', async () => {
		const locked: ResolvedSite = {
			...localSite,
			disabledTools: ['stonewright-acf-fields-get', 'stonewright-acf-fields-update'],
		};
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(locked, { fetchImpl });
		await expect(
			acfFieldsGet({ client, site: locked, writeMode: 'on' }, { id: 1 }),
		).rejects.toThrow(/disabled/i);
		await expect(
			acfFieldsUpdate(
				{ client, site: locked, writeMode: 'on' },
				{ id: 1, acf: { a: 1 } },
			),
		).rejects.toThrow(/disabled/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('surfaces REST errors from acf get', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ code: 'rest_forbidden', message: 'Nope' }), {
					status: 403,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		await expect(
			acfFieldsGet({ client, site: localSite, writeMode: 'on' }, { id: 1 }),
		).rejects.toMatchObject({ status: 403 });
	});
});

describe('direct seo-head-get', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('returns yoast head fields on happy path', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(
					JSON.stringify({
						id: 5,
						link: 'https://example.com/hello',
						yoast_head_json: {
							title: 'Hello | Site',
							description: 'Desc',
							canonical: 'https://example.com/hello',
							robots: { index: 'index', follow: 'follow' },
						},
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await seoHeadGet(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 5 },
		);
		expect(result).toEqual({
			id: 5,
			plugin: 'yoast',
			title: 'Hello | Site',
			description: 'Desc',
			canonical: 'https://example.com/hello',
			robots: { index: 'index', follow: 'follow' },
		});
		const url = String(fetchImpl.mock.calls[0]?.[0]);
		expect(url).toContain('/wp/v2/posts/5');
		expect(url).toContain('_fields=');
		expect(url).toContain('yoast_head_json');
	});

	it('uses pages rest base for type page', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(
					JSON.stringify({
						id: 8,
						yoast_head_json: { title: 'P', description: '', canonical: '' },
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		await seoHeadGet({ client, site: localSite, writeMode: 'on' }, { id: 8, type: 'page' });
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/pages/8');
	});

	it('returns null plugin hint when yoast head is absent', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ id: 5, link: 'https://example.com/x' }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await seoHeadGet(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 5 },
		);
		expect(result).toMatchObject({ id: 5, plugin: null });
		expect(String((result as { hint?: string }).hint)).toMatch(/Yoast|SEO plugin/i);
	});

	it('returns null plugin hint when REST request fails', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ code: 'rest_post_invalid_id', message: 'gone' }), {
					status: 404,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(localSite, { fetchImpl });
		const result = await seoHeadGet(
			{ client, site: localSite, writeMode: 'on' },
			{ id: 999 },
		);
		expect(result).toMatchObject({ id: 999, plugin: null });
		expect(String((result as { hint?: string }).hint)).toMatch(/SEO plugin/i);
	});

	it('blocks seo-head-get when disabled for the site', async () => {
		const locked: ResolvedSite = {
			...localSite,
			disabledTools: ['stonewright-seo-head-get'],
		};
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(locked, { fetchImpl });
		await expect(
			seoHeadGet({ client, site: locked, writeMode: 'on' }, { id: 1 }),
		).rejects.toThrow(/disabled/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});
});
