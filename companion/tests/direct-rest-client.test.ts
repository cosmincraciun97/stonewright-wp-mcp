import { describe, expect, it, vi } from 'vitest';
import { WpRestClient, WpRestError } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'abcd efgh ijkl mnop',
};

describe('WpRestClient', () => {
	it('sends Basic auth and normalizes path + query', async () => {
		const fetchImpl = vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = String(input);
			expect(url).toBe('http://example.test/wp-json/wp/v2/pages?per_page=10&search=home');
			expect(init?.method).toBe('GET');
			const headers = new Headers(init?.headers);
			const expected = `Basic ${Buffer.from('admin:abcd efgh ijkl mnop').toString('base64')}`;
			expect(headers.get('authorization')).toBe(expected);
			return Promise.resolve(
				new Response(JSON.stringify([{ id: 1, title: { rendered: 'Home' } }]), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});

		const client = new WpRestClient(site, { fetchImpl, timeoutMs: 5_000 });
		const result = await client.get<unknown[]>('/wp/v2/pages', {
			query: { per_page: 10, search: 'home', empty: undefined },
		});
		expect(Array.isArray(result)).toBe(true);
		expect(fetchImpl).toHaveBeenCalledOnce();
	});

	it('maps 401 to a credential hint', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ code: 'rest_forbidden', message: 'Sorry' }), {
					status: 401,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const err = await client.get('/wp/v2/pages').then(
			() => {
				throw new Error('expected rejection');
			},
			(e: unknown) => e,
		);
		expect(err).toBeInstanceOf(WpRestError);
		expect(err).toMatchObject({ status: 401 });
		expect((err as WpRestError).hint).toMatch(/Application Password/i);
	});

	it('maps 403 to a capability hint', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ code: 'rest_cannot_edit', message: 'Nope' }), {
					status: 403,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const err = await client.post('/wp/v2/pages', { body: { title: 'X' } }).then(
			() => {
				throw new Error('expected rejection');
			},
			(e: unknown) => e,
		);
		expect(err).toBeInstanceOf(WpRestError);
		expect(err).toMatchObject({ status: 403 });
		expect((err as WpRestError).hint).toMatch(/capability/i);
	});

	it('maps 404 on wp/v2 to a permalinks hint', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ code: 'rest_no_route', message: 'No route' }), {
					status: 404,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const err = await client.get('/wp/v2/pages').then(
			() => {
				throw new Error('expected rejection');
			},
			(e: unknown) => e,
		);
		expect(err).toBeInstanceOf(WpRestError);
		expect(err).toMatchObject({ status: 404 });
		expect((err as WpRestError).hint).toMatch(/permalink/i);
	});

	it('returns undefined for 204 responses', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(new Response(null, { status: 204 })));
		const client = new WpRestClient(site, { fetchImpl });
		const result = await client.del('/wp/v2/pages/9');
		expect(result).toBeUndefined();
	});

	it('retries once on ECONNRESET-like network failures', async () => {
		let calls = 0;
		const fetchImpl = vi.fn(() => {
			calls += 1;
			if (calls === 1) {
				const err = new Error('socket hang up') as Error & { code?: string };
				err.code = 'ECONNRESET';
				return Promise.reject(err);
			}
			return Promise.resolve(
				new Response(JSON.stringify({ id: 2 }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			);
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await client.get<{ id: number }>('/wp/v2/pages/2');
		expect(result.id).toBe(2);
		expect(fetchImpl).toHaveBeenCalledTimes(2);
	});
});
