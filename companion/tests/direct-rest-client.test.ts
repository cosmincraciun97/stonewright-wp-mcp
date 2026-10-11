import { describe, expect, it, vi } from 'vitest';
import { WpRestClient, WpRestError } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 'local',
	url: 'http://example.test',
	restBase: 'http://example.test/wp-json',
	username: 'admin',
	appPassword: 'test test test test',
};

describe('WpRestClient', () => {
	it('sends Basic auth and normalizes path + query', async () => {
		const fetchImpl = vi.fn((input: Parameters<typeof fetch>[0], init?: RequestInit) => {
			const url = String(input);
			expect(url).toBe('http://example.test/wp-json/wp/v2/pages?per_page=10&search=home');
			expect(init?.method).toBe('GET');
			const headers = new Headers(init?.headers);
			const expected = `Basic ${Buffer.from('admin:test test test test').toString('base64')}`;
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

function resetError(): Error {
	const err = new Error('socket hang up') as Error & { code?: string };
	err.code = 'ECONNRESET';
	return err;
}

function okResponse(): Response {
	return new Response(JSON.stringify({ id: 2 }), { status: 200, headers: { 'content-type': 'application/json' } });
}

describe('WpRestClient retry rules', () => {
	it.each(['post', 'put', 'del'] as const)('never retries a write: %s makes exactly one request after a reset', async (verb) => {
		const fetchImpl = vi.fn(() => Promise.reject(resetError()));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client[verb]('/wp/v2/pages/2', { body: { title: 'x' } })).rejects.toThrow(/socket hang up/);
		expect(fetchImpl).toHaveBeenCalledTimes(1);
	});

	it('never retries a PATCH after a reset', async () => {
		const fetchImpl = vi.fn(() => Promise.reject(resetError()));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client.request('PATCH', '/wp/v2/pages/2', { body: { title: 'x' } })).rejects.toThrow(/socket hang up/);
		expect(fetchImpl).toHaveBeenCalledTimes(1);
	});

	it('never retries a write on a timeout-style network error', async () => {
		const fetchImpl = vi.fn(() => Promise.reject(new Error('network error while waiting for response')));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client.post('/wp/v2/pages', { body: { title: 'x' } })).rejects.toThrow(/network/);
		expect(fetchImpl).toHaveBeenCalledTimes(1);
	});

	it('retries a GET once, then gives up after the second failure', async () => {
		const fetchImpl = vi.fn(() => Promise.reject(resetError()));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client.get('/wp/v2/pages/2')).rejects.toThrow(/socket hang up/);
		expect(fetchImpl).toHaveBeenCalledTimes(2);
	});

	it('a GET that recovers on the retry returns the body', async () => {
		const fetchImpl = vi.fn()
			.mockImplementationOnce(() => Promise.reject(resetError()))
			.mockImplementationOnce(() => Promise.resolve(okResponse()));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client.get<{ id: number }>('/wp/v2/pages/2')).resolves.toEqual({ id: 2 });
		expect(fetchImpl).toHaveBeenCalledTimes(2);
	});

	it('reads the code off the cause as well, the way undici reports a reset', async () => {
		const fetchImpl = vi.fn()
			.mockImplementationOnce(() => Promise.reject(new TypeError('fetch failed', { cause: resetError() })))
			.mockImplementationOnce(() => Promise.resolve(okResponse()));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(client.get<{ id: number }>('/wp/v2/pages/2')).resolves.toEqual({ id: 2 });
		expect(fetchImpl).toHaveBeenCalledTimes(2);
	});
});

describe('WpRestClient transport rule for the Application Password', () => {
	function remote(url: string): ResolvedSite {
		return { ...site, alias: 'remote', url, restBase: `${url}/wp-json` };
	}

	it.each([
		'http://shop.example.com',
		'http://example.org:8080',
		'http://203.0.113.10',
		'http://192.168.1.20',
		'http://10.0.0.5',
		'http://notlocalhost.example.com',
		'http://example.test.example.com',
	])('refuses %s over plain http for every verb and sends nothing', async (url) => {
		const fetchImpl = vi.fn(() => Promise.resolve(okResponse()));
		const client = new WpRestClient(remote(url), { fetchImpl, env: {} });
		for (const call of [
			() => client.get('/wp/v2/pages'),
			() => client.post('/wp/v2/pages', { body: {} }),
			() => client.put('/wp/v2/pages/1', { body: {} }),
			() => client.del('/wp/v2/pages/1'),
		]) {
			await expect(call()).rejects.toMatchObject({ code: 'insecure_transport' });
		}
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('names the cause and the fix in the message and never prints the secret', async () => {
		const client = new WpRestClient(remote('http://shop.example.com'), { fetchImpl: vi.fn(), env: {} });
		const err = await client.get('/wp/v2/pages').then(() => null, (e: unknown) => e as Error);
		expect(err?.message).toMatch(/https/i);
		expect(err?.message).toMatch(/STONEWRIGHT_DIRECT_ALLOW_HTTP/);
		expect(err?.message).not.toContain(site.appPassword);
	});

	it.each([
		'http://localhost',
		'http://localhost:8080',
		'http://127.0.0.1',
		'http://127.0.0.5:8081',
		'http://[::1]:8080',
		'http://dev.test',
		'http://sw78.test',
		'http://my-site.local',
		'http://shop.localhost',
		'https://shop.example.com',
	])('accepts %s', async (url) => {
		const fetchImpl = vi.fn(() => Promise.resolve(okResponse()));
		const client = new WpRestClient(remote(url), { fetchImpl, env: {} });
		await expect(client.get('/wp/v2/pages/2')).resolves.toEqual({ id: 2 });
		expect(fetchImpl).toHaveBeenCalledTimes(1);
	});

	it('an explicit override lets a trusted private network site through', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(okResponse()));
		const client = new WpRestClient(remote('http://192.168.1.20'), {
			fetchImpl,
			env: { STONEWRIGHT_DIRECT_ALLOW_HTTP: 'on' },
		});
		await expect(client.get('/wp/v2/pages/2')).resolves.toEqual({ id: 2 });
	});

	it('the override needs the exact value on', async () => {
		const fetchImpl = vi.fn();
		const client = new WpRestClient(remote('http://192.168.1.20'), {
			fetchImpl,
			env: { STONEWRIGHT_DIRECT_ALLOW_HTTP: 'maybe' },
		});
		await expect(client.get('/wp/v2/pages/2')).rejects.toMatchObject({ code: 'insecure_transport' });
		expect(fetchImpl).not.toHaveBeenCalled();
	});
});
