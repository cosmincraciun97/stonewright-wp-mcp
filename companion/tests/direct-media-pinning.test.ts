import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { EventEmitter } from 'node:events';
import http from 'node:http';
import https from 'node:https';
import type { AddressInfo } from 'node:net';
import { downloadMediaUrl, type MediaTransport } from '../src/direct/media-source.js';
import { pinnedRequest } from '../src/direct/media-transport.js';

const PNG = Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), Buffer.from('tail')]);
const PUBLIC_IP = '93.184.216.34';
const OTHER_PUBLIC_IP = '203.0.113.9';

describe('media download connects to the address it validated', () => {
	let server: http.Server;
	let port: number;
	let seen: Array<{ host: string | undefined; url: string | undefined }>;

	beforeEach(async () => {
		seen = [];
		server = http.createServer((req, res) => {
			seen.push({ host: req.headers.host, url: req.url });
			if (req.url === '/moved') {
				res.writeHead(302, { location: 'http://elsewhere.example.test/next' });
				res.end();
				return;
			}
			res.writeHead(200, { 'content-type': 'image/png' });
			res.end(PNG);
		});
		await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
		port = (server.address() as AddressInfo).port;
	});

	afterEach(async () => {
		vi.restoreAllMocks();
		await new Promise<void>((resolve) => server.close(() => resolve()));
	});

	it('reaches the pinned address and never resolves the host name, keeping the original Host header', async () => {
		// rebind.example.test cannot resolve anywhere: a connection that worked went to the pinned address.
		const response = await pinnedRequest(
			new URL(`http://rebind.example.test:${port}/a.png`),
			[{ address: '127.0.0.1', family: 4 }],
			AbortSignal.timeout(5_000),
		);
		expect(response.status).toBe(200);
		expect(Buffer.from(await response.arrayBuffer()).equals(PNG)).toBe(true);
		expect(seen).toEqual([{ host: `rebind.example.test:${port}`, url: '/a.png' }]);
	});

	it('hands a redirect back unfollowed', async () => {
		const response = await pinnedRequest(
			new URL(`http://rebind.example.test:${port}/moved`),
			[{ address: '127.0.0.1', family: 4 }],
			AbortSignal.timeout(5_000),
		);
		expect(response.status).toBe(302);
		expect(response.headers.get('location')).toBe('http://elsewhere.example.test/next');
		expect(seen).toHaveLength(1);
	});

	it('an https request keeps the host name for the Host header and for SNI, and connects through the pinned lookup', async () => {
		let captured: https.RequestOptions | undefined;
		vi.spyOn(https, 'request').mockImplementation(((options: https.RequestOptions) => {
			captured = options;
			const fake = new EventEmitter() as EventEmitter & { end: () => void; destroy: () => void };
			fake.end = () => setImmediate(() => fake.emit('error', new Error('stop here')));
			fake.destroy = () => undefined;
			return fake;
		}) as never);

		await expect(
			pinnedRequest(new URL('https://cdn.example.com/a.png?x=1'), [{ address: OTHER_PUBLIC_IP, family: 4 }], AbortSignal.timeout(5_000)),
		).rejects.toThrow(/stop here/);

		expect(captured?.hostname).toBe('cdn.example.com');
		expect(captured?.path).toBe('/a.png?x=1');
		expect((captured?.headers as Record<string, string>)['host']).toBe('cdn.example.com');
		expect(captured?.servername ?? 'cdn.example.com').toBe('cdn.example.com');
		const lookup = captured?.lookup as (host: string, options: object, cb: (...args: unknown[]) => void) => void;
		const single = vi.fn();
		lookup('cdn.example.com', {}, single);
		expect(single).toHaveBeenCalledWith(null, OTHER_PUBLIC_IP, 4);
		const all = vi.fn();
		lookup('cdn.example.com', { all: true }, all);
		expect(all).toHaveBeenCalledWith(null, [{ address: OTHER_PUBLIC_IP, family: 4 }]);
	});

	it('default transport: an address that is not public is refused before any connection', async () => {
		const lookupImpl = vi.fn(() => Promise.resolve([{ address: '127.0.0.1', family: 4 }]));
		await expect(downloadMediaUrl(`http://rebind.example.test:${port}/a.png`, { lookupImpl })).rejects.toThrow(/media_url_address_not_public/);
		expect(seen).toHaveLength(0);
	});

	it('resolves once per hop and connects to that answer, even when a second lookup would answer differently', async () => {
		const answers = [
			[{ address: PUBLIC_IP, family: 4 }],
			[{ address: '127.0.0.1', family: 4 }],
			[{ address: '169.254.169.254', family: 4 }],
		];
		const lookupImpl = vi.fn(() => Promise.resolve(answers.shift() ?? []));
		const connected: Array<{ url: string; addresses: Array<{ address: string }> }> = [];
		const transportImpl: MediaTransport = (url, addresses) => {
			connected.push({ url: url.toString(), addresses: [...addresses] });
			return Promise.resolve(new Response(new Uint8Array(PNG), { status: 200, headers: { 'content-type': 'image/png' } }));
		};
		const out = await downloadMediaUrl('https://cdn.example.com/a.png', { lookupImpl, transportImpl });
		expect(out.bytes.equals(PNG)).toBe(true);
		expect(lookupImpl).toHaveBeenCalledTimes(1);
		expect(connected).toEqual([{ url: 'https://cdn.example.com/a.png', addresses: [{ address: PUBLIC_IP, family: 4 }] }]);
	});

	it('each redirect hop is resolved and validated on its own', async () => {
		const byHost: Record<string, Array<{ address: string; family: number }>> = {
			'start.example.com': [{ address: PUBLIC_IP, family: 4 }],
			'next.example.com': [{ address: '10.0.0.7', family: 4 }],
		};
		const lookupImpl = vi.fn((host: string) => Promise.resolve(byHost[host] ?? []));
		const transportImpl: MediaTransport = vi.fn((url: URL) =>
			Promise.resolve(
				url.hostname === 'start.example.com'
					? new Response(null, { status: 302, headers: { location: 'https://next.example.com/x.png' } })
					: new Response(new Uint8Array(PNG), { status: 200 }),
			),
		);
		await expect(downloadMediaUrl('https://start.example.com/a.png', { lookupImpl, transportImpl })).rejects.toThrow(/media_url_address_not_public/);
		expect(transportImpl).toHaveBeenCalledTimes(1);
		expect(lookupImpl).toHaveBeenCalledTimes(2);
	});

	it('an IP literal is pinned to itself', async () => {
		const lookupImpl = vi.fn();
		const connected: string[] = [];
		const transportImpl: MediaTransport = (_url, addresses) => {
			connected.push(...addresses.map((a) => a.address));
			return Promise.resolve(new Response(new Uint8Array(PNG), { status: 200 }));
		};
		await downloadMediaUrl(`http://${PUBLIC_IP}/a.png`, { lookupImpl, transportImpl });
		expect(lookupImpl).not.toHaveBeenCalled();
		expect(connected).toEqual([PUBLIC_IP]);
	});

	it('every validated address of a multi-address answer stays available to the connection', async () => {
		const lookupImpl = vi.fn(() => Promise.resolve([{ address: PUBLIC_IP, family: 4 }, { address: '2606:2800:220:1:248:1893:25c8:1946', family: 6 }]));
		const seenAddresses: string[] = [];
		const transportImpl: MediaTransport = (_url, addresses) => {
			seenAddresses.push(...addresses.map((a) => a.address));
			return Promise.resolve(new Response(new Uint8Array(PNG), { status: 200 }));
		};
		await downloadMediaUrl('https://cdn.example.com/a.png', { lookupImpl, transportImpl });
		expect(seenAddresses).toEqual([PUBLIC_IP, '2606:2800:220:1:248:1893:25c8:1946']);
	});
});
