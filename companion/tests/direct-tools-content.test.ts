import { afterEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import {
	contentCreate,
	contentDelete,
	contentGet,
	contentList,
	contentUpdate,
} from '../src/direct/tools/content.js';
import { taxonomyList } from '../src/direct/tools/taxonomy.js';
import { clearRestDiscoveryCache } from '../src/direct/rest-discovery.js';
import { DIRECT_WAVE1_TOOL_NAMES } from '../src/direct/registry.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('direct content tools', () => {
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
		clearRestDiscoveryCache();
		vi.restoreAllMocks();
	});

	function homeDir(): string {
		const dir = mkdtempSync(join(tmpdir(), 'sw-home-'));
		dirs.push(dir);
		process.env.HOME = dir;
		return dir;
	}

	it('exports at least 12 wave-1 tool names', () => {
		expect(DIRECT_WAVE1_TOOL_NAMES.length).toBeGreaterThanOrEqual(12);
		expect(DIRECT_WAVE1_TOOL_NAMES).toContain('stonewright-content-list');
		expect(DIRECT_WAVE1_TOOL_NAMES).toContain('stonewright-media-upload');
		expect(DIRECT_WAVE1_TOOL_NAMES).toContain('stonewright-taxonomy-terms');
		expect(DIRECT_WAVE1_TOOL_NAMES).toContain('stonewright-content-create');
	});

	it('lists pages compactly on happy path', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(new Response(
				JSON.stringify([
					{
						id: 3,
						title: { rendered: 'About' },
						slug: 'about',
						status: 'publish',
						modified: '2026-07-01T00:00:00',
						link: 'http://example.test/about',
						type: 'page',
					},
				]),
				{ status: 200, headers: { 'content-type': 'application/json' } },
			)),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentList({ client, site, writeMode: 'on' }, { type: 'pages' });
		expect(result.items).toHaveLength(1);
		expect(result.items[0]).toMatchObject({ id: 3, title: 'About', slug: 'about' });
		expect(result.items[0]).not.toHaveProperty('content');
	});

	it('surfaces 401 errors from the REST client', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(new Response(JSON.stringify({ code: 'rest_forbidden', message: 'Unauthorized' }), {
				status: 401,
				headers: { 'content-type': 'application/json' },
			})),
		);
		const client = new WpRestClient(site, { fetchImpl });
		await expect(contentGet({ client, site, writeMode: 'on' }, { id: 1 })).rejects.toMatchObject({
			status: 401,
		});
	});

	it('blocks force delete without confirm in confirm mode', async () => {
		homeDir();
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			contentDelete({ client, site, writeMode: 'confirm' }, { id: 9, force: true }),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('allows force delete when confirm:true is provided', async () => {
		homeDir();
		const fetchImpl = vi.fn(() =>
			Promise.resolve(new Response(JSON.stringify({ deleted: true, previous: { id: 9 } }), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			})),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentDelete(
			{ client, site, writeMode: 'confirm' },
			{ id: 9, force: true, confirm: true },
		);
		expect(result.deleted).toBe(true);
		expect(fetchImpl).toHaveBeenCalledOnce();
	});

	it('creates a page and returns compact fields', async () => {
		homeDir();
		const fetchImpl = vi.fn(() =>
			Promise.resolve(new Response(
				JSON.stringify({
					id: 12,
					title: { raw: 'Hello' },
					slug: 'hello',
					status: 'draft',
					modified: '2026-07-15T00:00:00',
					link: 'http://example.test/?page_id=12',
					type: 'page',
				}),
				{ status: 201, headers: { 'content-type': 'application/json' } },
			)),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentCreate(
			{ client, site, writeMode: 'on' },
			{ kind: 'page', title: 'Hello', content: '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' },
		);
		expect(result).toMatchObject({ id: 12, title: 'Hello', status: 'draft' });
	});

	it('updates only provided fields', async () => {
		homeDir();
		const fetchImpl = vi.fn((_url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			expect(init?.method).toBe('PUT');
			expect(JSON.parse(String(init?.body))).toEqual({ title: 'Renamed' });
			return Promise.resolve(new Response(
				JSON.stringify({
					id: 4,
					title: { raw: 'Renamed' },
					slug: 'renamed',
					status: 'publish',
					modified: '2026-07-15T01:00:00',
					link: 'http://example.test/renamed',
					type: 'page',
				}),
				{ status: 200, headers: { 'content-type': 'application/json' } },
			));
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentUpdate(
			{ client, site, writeMode: 'on' },
			{ id: 4, title: 'Renamed' },
		);
		expect(result.title).toBe('Renamed');
	});

	it('blocks tools disabled for the site', async () => {
		const locked: ResolvedSite = {
			...site,
			disabledTools: ['stonewright-content-delete'],
		};
		const fetchImpl = vi.fn(() => Promise.resolve(new Response('{}', { status: 200 })));
		const client = new WpRestClient(locked, { fetchImpl });
		await expect(
			contentDelete({ client, site: locked, writeMode: 'on' }, { id: 1 }),
		).rejects.toThrow(/disabled/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('creates a generic CPT using discovered rest_base', async () => {
		homeDir();
		const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const href = String(url);
			if (init?.method === 'GET' || !init?.method) {
				if (href.includes('/wp/v2/types')) {
					return Promise.resolve(
						new Response(
							JSON.stringify({
								sector: { slug: 'sector', rest_base: 'sectors' },
							}),
							{ status: 200, headers: { 'content-type': 'application/json' } },
						),
					);
				}
			}
			if (init?.method === 'POST' && href.includes('/wp/v2/sectors')) {
				return Promise.resolve(
					new Response(
						JSON.stringify({
							id: 42,
							title: { raw: 'X' },
							slug: 'x',
							status: 'draft',
							modified: '2026-07-16T00:00:00',
							link: 'http://example.test/sectors/x',
							type: 'sector',
						}),
						{ status: 201, headers: { 'content-type': 'application/json' } },
					),
				);
			}
			return Promise.resolve(
				new Response(JSON.stringify({ code: 'unexpected', message: href }), {
					status: 500,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentCreate(
			{ client, site, writeMode: 'on' },
			{ type: 'sector', title: 'X' },
		);
		expect(result).toMatchObject({ id: 42, title: 'X', type: 'sector' });
		const postCall = fetchImpl.mock.calls.find(
			([url, init]) => String(init?.method ?? 'GET').toUpperCase() === 'POST',
		);
		expect(postCall).toBeDefined();
		expect(String(postCall?.[0])).toContain('/wp/v2/sectors');
	});

	it('falls back to raw slug when type discovery fails', async () => {
		const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0]) => {
			const href = String(url);
			if (href.includes('/wp/v2/types')) {
				return Promise.resolve(
					new Response(JSON.stringify({ code: 'error', message: 'boom' }), {
						status: 500,
						headers: { 'content-type': 'application/json' },
					}),
				);
			}
			if (href.includes('/wp/v2/portfolio')) {
				return Promise.resolve(
					new Response(
						JSON.stringify([
							{
								id: 7,
								title: { rendered: 'Work' },
								slug: 'work',
								status: 'publish',
								modified: '2026-07-16T00:00:00',
								link: 'http://example.test/portfolio/work',
								type: 'portfolio',
							},
						]),
						{ status: 200, headers: { 'content-type': 'application/json' } },
					),
				);
			}
			return Promise.resolve(
				new Response(JSON.stringify({ code: 'unexpected', message: href }), {
					status: 500,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await contentList(
			{ client, site, writeMode: 'on' },
			{ type: 'portfolio' },
		);
		expect(result.items).toHaveLength(1);
		expect(result.items[0]).toMatchObject({ id: 7, title: 'Work', type: 'portfolio' });
		const listCall = fetchImpl.mock.calls.find(([url]) => String(url).includes('/wp/v2/portfolio'));
		expect(listCall).toBeDefined();
	});

	it('lists taxonomy terms using discovered rest_base', async () => {
		const fetchImpl = vi.fn((url: Parameters<typeof fetch>[0]) => {
			const href = String(url);
			if (href.includes('/wp/v2/taxonomies')) {
				return Promise.resolve(
					new Response(
						JSON.stringify({
							sector_area: { slug: 'sector_area', rest_base: 'sector-areas' },
						}),
						{ status: 200, headers: { 'content-type': 'application/json' } },
					),
				);
			}
			if (href.includes('/wp/v2/sector-areas')) {
				return Promise.resolve(
					new Response(
						JSON.stringify([
							{
								id: 3,
								name: 'North',
								slug: 'north',
								description: '',
								count: 1,
								parent: 0,
							},
						]),
						{ status: 200, headers: { 'content-type': 'application/json' } },
					),
				);
			}
			return Promise.resolve(
				new Response(JSON.stringify({ code: 'unexpected', message: href }), {
					status: 500,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await taxonomyList(
			{ client, site, writeMode: 'on' },
			{ taxonomy: 'sector_area' },
		);
		expect(result.taxonomy).toBe('sector-areas');
		expect(result.items).toHaveLength(1);
		expect(result.items[0]).toMatchObject({ id: 3, name: 'North', slug: 'north' });
		const termsCall = fetchImpl.mock.calls.find(([url]) => String(url).includes('/wp/v2/sector-areas'));
		expect(termsCall).toBeDefined();
	});
});
