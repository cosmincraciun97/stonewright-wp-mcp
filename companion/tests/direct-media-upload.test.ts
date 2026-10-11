import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { closeSync, ftruncateSync, mkdirSync, mkdtempSync, openSync, realpathSync, rmSync, symlinkSync, writeFileSync, writeSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, sep } from 'node:path';
import { mediaUpload, type MediaToolContext } from '../src/direct/tools/media.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';

const site: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

const PUBLIC_IP = '93.184.216.34';
const PNG = Buffer.concat([Buffer.from('89504e470d0a1a0a', 'hex'), Buffer.from('tail')]);
const JPEG = Buffer.concat([Buffer.from('ffd8ffe0', 'hex'), Buffer.from('tail')]);
const GIF = Buffer.from('GIF89a-tail');

type Lookup = NonNullable<MediaToolContext['lookupImpl']>;

function bytesResponse(status = 200, headers: Record<string, string> = {}): Response {
	return new Response(status >= 300 && status < 400 ? null : new Uint8Array(PNG), { status, headers });
}

describe('direct media-upload: local path roots', () => {
	let work: string;
	let root: string;
	let outside: string;
	let stateDir: string;
	let post: ReturnType<typeof vi.fn>;
	let previousState: string | undefined;

	function ctx(over: Partial<MediaToolContext> = {}): MediaToolContext {
		return {
			client: { post } as never,
			site,
			writeMode: 'on',
			env: { STONEWRIGHT_STATE_DIR: stateDir },
			cwd: root,
			...over,
		};
	}

	beforeEach(() => {
		work = realpathSync(mkdtempSync(join(tmpdir(), 'sw-media-')));
		root = join(work, 'project');
		outside = join(work, 'elsewhere');
		stateDir = join(work, 'state');
		mkdirSync(root, { recursive: true });
		mkdirSync(outside, { recursive: true });
		mkdirSync(join(stateDir, 'media'), { recursive: true });
		writeFileSync(join(root, 'in.png'), PNG);
		writeFileSync(join(outside, 'secret.txt'), 'secret');
		post = vi.fn(() => Promise.resolve({ id: 7, source_url: 'https://example.com/in.png' }));
		previousState = process.env['STONEWRIGHT_STATE_DIR'];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});
	afterEach(() => {
		if (previousState === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = previousState;
		rmSync(work, { recursive: true, force: true });
	});

	it('uploads a file inside the working directory', async () => {
		const out = await mediaUpload(ctx(), { path: join(root, 'in.png') });
		expect(out.id).toBe(7);
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('resolves a relative path against the working directory', async () => {
		await mediaUpload(ctx(), { path: 'in.png' });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it("uploads a file inside the companion's media folder", async () => {
		writeFileSync(join(stateDir, 'media', 'm.png'), PNG);
		await mediaUpload(ctx(), { path: join(stateDir, 'media', 'm.png') });
		expect(post).toHaveBeenCalledTimes(1);
	});

	// The list is comma- or semicolon-separated on every platform (like
	// STONEWRIGHT_WP_ALLOWED_ROOTS). path.delimiter is ':' on POSIX, which is not a
	// separator here and would also split 'C:' on Windows.
	function rootsEnv(list: string): NodeJS.ProcessEnv {
		return { STONEWRIGHT_STATE_DIR: stateDir, STONEWRIGHT_DIRECT_MEDIA_ROOTS: list };
	}

	it.each([';', ','])('uploads a file inside a root named by STONEWRIGHT_DIRECT_MEDIA_ROOTS (separator %s)', async (separator) => {
		writeFileSync(join(outside, 'ok.png'), PNG);
		const env = rootsEnv(`${join(work, 'unused')}${separator}${outside}`);
		await mediaUpload(ctx({ env }), { path: join(outside, 'ok.png') });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('ignores spaces and empty entries in STONEWRIGHT_DIRECT_MEDIA_ROOTS', async () => {
		writeFileSync(join(outside, 'ok.png'), PNG);
		const env = rootsEnv(` ; ${outside} ;, `);
		await mediaUpload(ctx({ env }), { path: join(outside, 'ok.png') });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('accepts a configured root written with a trailing separator', async () => {
		writeFileSync(join(outside, 'ok.png'), PNG);
		await mediaUpload(ctx({ env: rootsEnv(`${outside}${sep}`) }), { path: join(outside, 'ok.png') });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('accepts a configured root given through a symlink, and a file given through the real path', async () => {
		writeFileSync(join(outside, 'ok.png'), PNG);
		const alias = join(work, 'outside-alias');
		symlinkSync(outside, alias, 'junction');
		await mediaUpload(ctx({ env: rootsEnv(alias) }), { path: join(outside, 'ok.png') });
		await mediaUpload(ctx({ env: rootsEnv(alias) }), { path: join(alias, 'ok.png') });
		expect(post).toHaveBeenCalledTimes(2);
	});

	it('does not accept a sibling folder that only shares the root name as a prefix', async () => {
		const sibling = `${outside}-more`;
		mkdirSync(sibling, { recursive: true });
		writeFileSync(join(sibling, 'x.png'), PNG);
		await expect(mediaUpload(ctx({ env: rootsEnv(outside) }), { path: join(sibling, 'x.png') }))
			.rejects.toThrow(/media_path_outside_allowed_roots/);
		expect(post).not.toHaveBeenCalled();
	});

	it('does not treat a colon-separated list as a list of roots', async () => {
		writeFileSync(join(outside, 'ok.png'), PNG);
		const env = rootsEnv(`${join(work, 'unused')}:${outside}`);
		await expect(mediaUpload(ctx({ env }), { path: join(outside, 'ok.png') }))
			.rejects.toThrow(/media_path_outside_allowed_roots/);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a path outside every allowed root without confirm', async () => {
		await expect(mediaUpload(ctx(), { path: join(outside, 'secret.txt') }))
			.rejects.toThrow(/media_path_outside_allowed_roots/);
		expect(post).not.toHaveBeenCalled();
	});

	it('allows a path outside the roots with confirm:true', async () => {
		await mediaUpload(ctx(), { path: join(outside, 'secret.txt'), confirm: true });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('does not treat a .. escape out of a root as inside it', async () => {
		const escape = join(root, '..', 'elsewhere', 'secret.txt');
		await expect(mediaUpload(ctx(), { path: escape })).rejects.toThrow(/media_path_outside_allowed_roots/);
		await expect(mediaUpload(ctx(), { path: '../elsewhere/secret.txt' })).rejects.toThrow(/media_path_outside_allowed_roots/);
		expect(post).not.toHaveBeenCalled();
	});

	it('resolves a symlink before checking the roots', async () => {
		// A junction on Windows (no privilege needed), a directory symlink elsewhere.
		symlinkSync(outside, join(root, 'link'), 'junction');
		await expect(mediaUpload(ctx(), { path: join(root, 'link', 'secret.txt') }))
			.rejects.toThrow(/media_path_outside_allowed_roots/);
		expect(post).not.toHaveBeenCalled();
		await mediaUpload(ctx(), { path: join(root, 'link', 'secret.txt'), confirm: true });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('accepts an allowed root that is itself reached through a symlink', async () => {
		const alias = join(work, 'root-alias');
		symlinkSync(root, alias, 'junction');
		await mediaUpload(ctx({ cwd: alias }), { path: join(alias, 'in.png') });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it('reports a missing file and a directory without uploading', async () => {
		await expect(mediaUpload(ctx(), { path: join(root, 'missing.png') })).rejects.toThrow(/media_path_not_found/);
		await expect(mediaUpload(ctx(), { path: root })).rejects.toThrow(/media_path_not_a_file/);
		expect(post).not.toHaveBeenCalled();
	});

	it('does not let confirm widen the roots for an escape that is not confirmed', async () => {
		await expect(mediaUpload(ctx(), { path: join(outside, 'secret.txt'), confirm: false }))
			.rejects.toThrow(/media_path_outside_allowed_roots/);
	});
});

describe('direct media-upload: url', () => {
	let post: ReturnType<typeof vi.fn<(path: string, opts: { body: FormData }) => Promise<unknown>>>;
	let stateDir: string;
	let previousState: string | undefined;

	function ctx(fetchImpl: unknown, lookupImpl?: Lookup): MediaToolContext {
		return {
			client: { post } as never,
			site,
			writeMode: 'on',
			env: { STONEWRIGHT_STATE_DIR: stateDir },
			fetchImpl: fetchImpl as typeof fetch,
			lookupImpl: lookupImpl ?? (() => Promise.resolve([{ address: PUBLIC_IP, family: 4 }])),
		};
	}

	beforeEach(() => {
		stateDir = mkdtempSync(join(tmpdir(), 'sw-media-url-'));
		post = vi.fn((_path: string, _opts: { body: FormData }) => Promise.resolve({ id: 9, source_url: 'https://example.com/x.png' }));
		previousState = process.env['STONEWRIGHT_STATE_DIR'];
		process.env['STONEWRIGHT_STATE_DIR'] = stateDir;
	});
	afterEach(() => {
		if (previousState === undefined) delete process.env['STONEWRIGHT_STATE_DIR'];
		else process.env['STONEWRIGHT_STATE_DIR'] = previousState;
		rmSync(stateDir, { recursive: true, force: true });
	});

	it('downloads from a public https url without following redirects on its own', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(bytesResponse()));
		const out = await mediaUpload(ctx(fetchImpl), { url: 'https://cdn.example.org/a/photo.png' });
		expect(out.id).toBe(9);
		expect(fetchImpl).toHaveBeenCalledTimes(1);
		expect(fetchImpl.mock.calls[0]?.[1]).toMatchObject({ redirect: 'manual' });
		expect(post).toHaveBeenCalledTimes(1);
	});

	it.each(['file:///etc/passwd', 'ftp://cdn.example.org/a.png', 'data:text/plain;base64,AAAA', 'gopher://x/'])(
		'refuses the scheme of %s',
		async (url) => {
			const fetchImpl = vi.fn();
			await expect(mediaUpload(ctx(fetchImpl), { url })).rejects.toThrow(/media_url_scheme_not_allowed/);
			expect(fetchImpl).not.toHaveBeenCalled();
		},
	);

	it('refuses a url that does not parse', async () => {
		const fetchImpl = vi.fn();
		await expect(mediaUpload(ctx(fetchImpl), { url: 'not a url' })).rejects.toThrow(/media_url_invalid/);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it.each([
		'http://127.0.0.1/a.png',
		'http://127.1/a.png',
		'http://2130706433/a.png',
		'http://0x7f000001/a.png',
		'http://0.0.0.0/a.png',
		'http://10.1.2.3/a.png',
		'http://100.64.0.1/a.png',
		'http://172.16.0.1/a.png',
		'http://172.31.255.255/a.png',
		'http://192.168.1.10/a.png',
		'http://169.254.169.254/latest/meta-data',
		'http://224.0.0.1/a.png',
		'http://239.255.255.250/a.png',
		'http://255.255.255.255/a.png',
		'http://[::1]/a.png',
		'http://[::]/a.png',
		'http://[fe80::1]/a.png',
		'http://[fc00::1]/a.png',
		'http://[fd12:3456::1]/a.png',
		'http://[ff02::1]/a.png',
		'http://[::ffff:127.0.0.1]/a.png',
		'http://[::ffff:7f00:1]/a.png',
		'http://[::ffff:10.0.0.1]/a.png',
		'http://[64:ff9b::7f00:1]/a.png',
		'http://[2002:7f00:1::1]/a.png',
	])('refuses the private, loopback, link-local or multicast address %s', async (url) => {
		const fetchImpl = vi.fn();
		await expect(mediaUpload(ctx(fetchImpl), { url })).rejects.toThrow(/media_url_address_not_public/);
		expect(fetchImpl).not.toHaveBeenCalled();
		expect(post).not.toHaveBeenCalled();
	});

	it('accepts public literal addresses, IPv4 and IPv6', async () => {
		for (const url of [`http://${PUBLIC_IP}/a.png`, 'https://[2606:2800:220:1:248:1893:25c8:1946]/a.png']) {
			const fetchImpl = vi.fn(() => Promise.resolve(bytesResponse()));
			await mediaUpload(ctx(fetchImpl), { url });
			expect(fetchImpl).toHaveBeenCalledTimes(1);
		}
	});

	it('checks the resolved address, not only the name', async () => {
		const fetchImpl = vi.fn();
		const lookup: Lookup = () => Promise.resolve([{ address: '10.0.0.8', family: 4 }]);
		await expect(mediaUpload(ctx(fetchImpl, lookup), { url: 'https://harmless-name.example.org/a.png' }))
			.rejects.toThrow(/media_url_address_not_public/);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('refuses when any resolved address is not public', async () => {
		const fetchImpl = vi.fn();
		const lookup: Lookup = () => Promise.resolve([
			{ address: PUBLIC_IP, family: 4 },
			{ address: '::1', family: 6 },
		]);
		await expect(mediaUpload(ctx(fetchImpl, lookup), { url: 'https://mixed.example.org/a.png' }))
			.rejects.toThrow(/media_url_address_not_public/);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('refuses a name that does not resolve', async () => {
		const fetchImpl = vi.fn();
		const lookup: Lookup = () => Promise.reject(new Error('ENOTFOUND'));
		await expect(mediaUpload(ctx(fetchImpl, lookup), { url: 'https://nowhere.example.org/a.png' }))
			.rejects.toThrow(/media_url_unresolved/);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('follows a redirect to a public address and checks every hop', async () => {
		const seen: string[] = [];
		const fetchImpl = vi.fn((url: string) => {
			seen.push(url);
			return Promise.resolve(url.includes('/start')
				? bytesResponse(302, { location: 'https://files.example.org/final/pic.png' })
				: bytesResponse());
		});
		const lookup = vi.fn<Lookup>(() => Promise.resolve([{ address: PUBLIC_IP, family: 4 }]));
		await mediaUpload(ctx(fetchImpl, lookup), { url: 'https://cdn.example.org/start' });
		expect(seen).toEqual(['https://cdn.example.org/start', 'https://files.example.org/final/pic.png']);
		expect(lookup).toHaveBeenCalledTimes(2);
		expect(post).toHaveBeenCalledTimes(1);
	});

	it.each([
		['a private literal', 'http://169.254.169.254/latest/meta-data'],
		['a loopback literal', 'http://127.0.0.1:8080/admin'],
		['a relative location on a public host that resolves privately', '/internal'],
		['an IPv6 loopback literal', 'http://[::1]/x'],
	])('refuses a redirect to %s', async (_label, location) => {
		const privateNames = location === '/internal';
		const fetchImpl = vi.fn((url: string) =>
			Promise.resolve(url.includes('start') ? bytesResponse(301, { location }) : bytesResponse()));
		let calls = 0;
		const lookup: Lookup = () => {
			calls += 1;
			return Promise.resolve([{ address: privateNames && calls > 1 ? '192.168.0.9' : PUBLIC_IP, family: 4 }]);
		};
		await expect(mediaUpload(ctx(fetchImpl, lookup), { url: 'https://cdn.example.org/start' }))
			.rejects.toThrow(/media_url_address_not_public/);
		expect(fetchImpl).toHaveBeenCalledTimes(1);
		expect(post).not.toHaveBeenCalled();
	});

	it('refuses a redirect to a scheme that is not http or https', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(bytesResponse(302, { location: 'file:///etc/passwd' })));
		await expect(mediaUpload(ctx(fetchImpl), { url: 'https://cdn.example.org/start' }))
			.rejects.toThrow(/media_url_scheme_not_allowed/);
		expect(post).not.toHaveBeenCalled();
	});

	it('stops after too many redirects', async () => {
		const fetchImpl = vi.fn(() => Promise.resolve(bytesResponse(302, { location: 'https://cdn.example.org/again' })));
		await expect(mediaUpload(ctx(fetchImpl), { url: 'https://cdn.example.org/start' }))
			.rejects.toThrow(/media_url_too_many_redirects/);
		expect(fetchImpl.mock.calls.length).toBeLessThanOrEqual(6);
		expect(post).not.toHaveBeenCalled();
	});

	it('names the filename from the final url', async () => {
		const fetchImpl = vi.fn((url: string) =>
			Promise.resolve(url.includes('start') ? bytesResponse(302, { location: 'https://files.example.org/x/final.png' }) : bytesResponse()));
		await mediaUpload(ctx(fetchImpl), { url: 'https://cdn.example.org/start' });
		const form = post.mock.calls[0]?.[1].body;
		expect((form.get('file') as File).name).toBe('final.png');
	});
});

describe('direct media-upload: limits', () => {
	let work: string;
	let root: string;
	let stateDir: string;
	let post: ReturnType<typeof vi.fn<(path: string, opts: { body: FormData }) => Promise<unknown>>>;

	function ctx(env: NodeJS.ProcessEnv = {}, over: Partial<MediaToolContext> = {}): MediaToolContext {
		return {
			client: { post } as never,
			site,
			writeMode: 'on',
			env: { STONEWRIGHT_STATE_DIR: stateDir, ...env },
			cwd: root,
			lookupImpl: () => Promise.resolve([{ address: PUBLIC_IP, family: 4 }]),
			...over,
		};
	}
	function sentName(): string {
		return (post.mock.calls[0]?.[1].body.get('file') as File).name;
	}

	beforeEach(() => {
		work = realpathSync(mkdtempSync(join(tmpdir(), 'sw-media-lim-')));
		root = join(work, 'project');
		stateDir = join(work, 'state');
		mkdirSync(root, { recursive: true });
		mkdirSync(stateDir, { recursive: true });
		post = vi.fn((_path: string, _opts: { body: FormData }) => Promise.resolve({ id: 5, source_url: 'https://example.com/x.png' }));
	});
	afterEach(() => {
		rmSync(work, { recursive: true, force: true });
	});

	describe('type allowlist', () => {
		it.each(['a.svg', 'a.svgz', 'a.html', 'a.htm', 'a.xhtml', 'a.php', 'a.phtml', 'a.php5', 'a.phar', 'a.js', 'a.exe', 'a.sh', 'a.xml', 'a.json', 'a.zip', 'a.htaccess', 'noext', '.htaccess'])(
			'refuses %s, even with confirm:true',
			async (name) => {
				writeFileSync(join(root, name), PNG);
				await expect(mediaUpload(ctx(), { path: join(root, name), confirm: true })).rejects.toThrow(/media_type_not_allowed/);
				expect(post).not.toHaveBeenCalled();
			},
		);

		it('refuses an extension given through filename that is not allowed', async () => {
			writeFileSync(join(root, 'in.png'), PNG);
			await expect(mediaUpload(ctx(), { path: join(root, 'in.png'), filename: 'x.php' })).rejects.toThrow(/media_type_not_allowed/);
			await expect(mediaUpload(ctx(), { path: join(root, 'in.png'), filename: 'x.svg' })).rejects.toThrow(/media_type_not_allowed/);
			expect(post).not.toHaveBeenCalled();
		});

		it('accepts images, documents, audio and video by extension, in any letter case', async () => {
			const names = ['a.png', 'b.JPG', 'c.jpeg', 'd.gif', 'e.webp', 'f.pdf'];
			writeFileSync(join(root, 'a.png'), PNG);
			writeFileSync(join(root, 'b.JPG'), JPEG);
			writeFileSync(join(root, 'c.jpeg'), JPEG);
			writeFileSync(join(root, 'd.gif'), GIF);
			writeFileSync(join(root, 'e.webp'), Buffer.concat([Buffer.from('RIFF'), Buffer.from([0, 0, 0, 0]), Buffer.from('WEBPVP8 ')]));
			writeFileSync(join(root, 'f.pdf'), Buffer.from('%PDF-1.7\n'));
			for (const name of ['g.mp4', 'h.mp3', 'i.docx', 'j.csv', 'k.txt']) {
				writeFileSync(join(root, name), 'data');
				names.push(name);
			}
			for (const name of names) await mediaUpload(ctx(), { path: join(root, name) });
			expect(post).toHaveBeenCalledTimes(names.length);
		});

		it('marks an inner executable extension and keeps the allowed final one', async () => {
			writeFileSync(join(root, 'shell.php.png'), PNG);
			await mediaUpload(ctx(), { path: join(root, 'shell.php.png') });
			expect(sentName()).toBe('shell.php_.png');
		});
	});

	describe('content check', () => {
		it.each([
			['a png that is really html', 'a.png', '<!DOCTYPE html><script>alert(1)</script>'],
			['a jpg that is php', 'a.jpg', '<?php system($_GET["c"]);'],
			['a gif that carries php after its header', 'a.gif', 'GIF89a\n<?php echo 1; ?>'],
			['an svg body named png', 'a.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
			['a txt that starts with a script tag', 'a.txt', '  <script>alert(1)</script>'],
			['a png with jpeg bytes', 'a.png', JPEG],
			['a pdf with png bytes', 'a.pdf', PNG],
		])('refuses %s', async (_label, name, content) => {
			writeFileSync(join(root, name), content);
			await expect(mediaUpload(ctx(), { path: join(root, name) })).rejects.toThrow(/media_content_mismatch/);
			expect(post).not.toHaveBeenCalled();
		});
	});

	describe('size', () => {
		it('refuses a local file over the limit before reading it', async () => {
			writeFileSync(join(root, 'big.png'), Buffer.concat([PNG, Buffer.alloc(2000)]));
			await expect(mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: '1000' }), { path: join(root, 'big.png') }))
				.rejects.toThrow(/media_file_too_large/);
			expect(post).not.toHaveBeenCalled();
		});

		it('accepts a file exactly at the limit', async () => {
			writeFileSync(join(root, 'edge.png'), Buffer.concat([PNG, Buffer.alloc(1000 - PNG.length)]));
			await mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: '1000' }), { path: join(root, 'edge.png') });
			expect(post).toHaveBeenCalledTimes(1);
		});

		it('applies a 25 MiB default and ignores an unusable override', async () => {
			// A 26 MiB sparse file: the size check must refuse it.
			const fd = openSync(join(root, 'huge.png'), 'w');
			writeSync(fd, PNG);
			ftruncateSync(fd, 26 * 1024 * 1024);
			closeSync(fd);
			await expect(mediaUpload(ctx(), { path: join(root, 'huge.png') })).rejects.toThrow(/media_file_too_large/);
			await expect(mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: 'lots' }), { path: join(root, 'huge.png') }))
				.rejects.toThrow(/media_file_too_large/);
			await expect(mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: '-1' }), { path: join(root, 'huge.png') }))
				.rejects.toThrow(/media_file_too_large/);
			expect(post).not.toHaveBeenCalled();
		});

		it('refuses a url whose content-length is over the limit without reading the body', async () => {
			let pulled = false;
			const body = new ReadableStream<Uint8Array>({ pull() { pulled = true; } }, { highWaterMark: 0 });
			const fetchImpl = vi.fn(() => Promise.resolve(new Response(body, { status: 200, headers: { 'content-length': '5000' } })));
			await expect(mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: '1000' }, { fetchImpl: fetchImpl as never }), { url: 'https://cdn.example.org/a.png' }))
				.rejects.toThrow(/media_file_too_large/);
			expect(pulled).toBe(false);
			expect(post).not.toHaveBeenCalled();
		});

		it('stops reading a url body that grows past the limit without a content-length', async () => {
			let chunks = 0;
			const body = new ReadableStream<Uint8Array>({
				pull(controller) {
					chunks += 1;
					controller.enqueue(new Uint8Array(400));
					if (chunks > 50) controller.close();
				},
			}, { highWaterMark: 0 });
			const fetchImpl = vi.fn(() => Promise.resolve(new Response(body, { status: 200 })));
			await expect(mediaUpload(ctx({ STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES: '1000' }, { fetchImpl: fetchImpl as never }), { url: 'https://cdn.example.org/a.png' }))
				.rejects.toThrow(/media_file_too_large/);
			expect(chunks).toBeLessThan(10);
			expect(post).not.toHaveBeenCalled();
		});

		it('passes an abort signal to every download request', async () => {
			const fetchImpl = vi.fn(() => Promise.resolve(bytesResponse()));
			await mediaUpload(ctx({}, { fetchImpl: fetchImpl as never }), { url: 'https://cdn.example.org/a.png' });
			const init = (fetchImpl.mock.calls[0] as unknown as [string, RequestInit])[1];
			expect(init.signal).toBeInstanceOf(AbortSignal);
		});
	});

	describe('url types', () => {
		function typed(contentType: string, body: Uint8Array = PNG): typeof fetch {
			return (() => Promise.resolve(new Response(new Uint8Array(body), { status: 200, headers: { 'content-type': contentType } }))) as never;
		}

		it('takes the extension from the content type when the url has none', async () => {
			await mediaUpload(ctx({}, { fetchImpl: typed('image/png') }), { url: 'https://cdn.example.org/render?id=3' });
			expect(sentName()).toBe('render.png');
		});

		it('refuses a url with no extension and a content type that is not an allowed type', async () => {
			await expect(mediaUpload(ctx({}, { fetchImpl: typed('text/html; charset=utf-8', Buffer.from('<html>')) }), { url: 'https://cdn.example.org/page' }))
				.rejects.toThrow(/media_type_not_allowed/);
			await expect(mediaUpload(ctx({}, { fetchImpl: typed('image/svg+xml', Buffer.from('<svg/>')) }), { url: 'https://cdn.example.org/logo' }))
				.rejects.toThrow(/media_type_not_allowed/);
			expect(post).not.toHaveBeenCalled();
		});

		it('never lets the content type rename a blocked extension', async () => {
			await expect(mediaUpload(ctx({}, { fetchImpl: typed('image/png') }), { url: 'https://cdn.example.org/run.php' }))
				.rejects.toThrow(/media_type_not_allowed/);
			await expect(mediaUpload(ctx({}, { fetchImpl: typed('image/png') }), { url: 'https://cdn.example.org/logo.svg' }))
				.rejects.toThrow(/media_type_not_allowed/);
			expect(post).not.toHaveBeenCalled();
		});

		it('refuses an html page served at an image url', async () => {
			await expect(mediaUpload(ctx({}, { fetchImpl: typed('text/html', Buffer.from('<html><body>login</body></html>')) }), { url: 'https://cdn.example.org/a.png' }))
				.rejects.toThrow(/media_content_mismatch/);
			expect(post).not.toHaveBeenCalled();
		});
	});

	describe('filename', () => {
		beforeEach(() => {
			writeFileSync(join(root, 'in.png'), PNG);
		});
		async function nameFor(filename: string): Promise<string> {
			post.mockClear();
			await mediaUpload(ctx(), { path: join(root, 'in.png'), filename });
			return sentName();
		}

		it('removes directories, separators and traversal', async () => {
			expect(await nameFor('../../etc/evil.png')).toBe('evil.png');
			expect(await nameFor('C:\\Windows\\Temp\\evil.png')).toBe('evil.png');
			expect(await nameFor('a/b\\c.png')).toBe('c.png');
		});

		it('replaces spaces and drops characters WordPress would drop', async () => {
			expect(await nameFor('my holiday  photo?.png')).toBe('my-holiday-photo.png');
			expect(await nameFor('a<b>"c"|d*.png')).toBe('abcd.png');
		});

		it('removes control, null and direction-override characters', async () => {
			expect(await nameFor('a\u0000b\u0007c.png')).toBe('abc.png');
			expect(await nameFor('photo\u202Egnp.png')).toBe('photognp.png');
		});

		it('keeps a supplied name that has no extension and adds the source extension', async () => {
			expect(await nameFor('holiday')).toBe('holiday.png');
		});

		it('limits the length and keeps the extension', async () => {
			const name = await nameFor(`${'x'.repeat(400)}.png`);
			expect(name.length).toBeLessThanOrEqual(120);
			expect(name.endsWith('.png')).toBe(true);
		});

		it('falls back to a plain name when nothing usable is left', async () => {
			expect(await nameFor('???.png')).toBe('upload.png');
		});
	});
});
