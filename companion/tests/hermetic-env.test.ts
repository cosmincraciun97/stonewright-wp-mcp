import http, { createServer } from 'node:http';
import net from 'node:net';
import { existsSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join, resolve, sep } from 'node:path';
import { describe, expect, it } from 'vitest';
import { defaultStateDir } from '../src/direct/audit.js';
import { loadSitesConfig } from '../src/direct/sites-config.js';
import { defaultSitesPath } from '../src/cli/connect/registry.js';
import {
	allowTestNetworkHost,
	blockedTestNetworkAttempts,
	isTestNetworkAllowed,
	resetTestNetworkAllowList,
} from './helpers/hermetic-net.js';

const sandbox = process.env['SW_TEST_SANDBOX'] ?? '';
const realHome = process.env['SW_TEST_REAL_HOME'] ?? '';

function inside(path: string): boolean {
	const root = resolve(sandbox) + sep;
	return (resolve(path) + sep).startsWith(root);
}

describe('the test suite is isolated from the developer machine', () => {
	it('runs in a temp sandbox that is not the real home', () => {
		expect(sandbox).not.toBe('');
		expect(resolve(sandbox).startsWith(resolve(tmpdir()))).toBe(true);
		expect(realHome).not.toBe('');
		expect(inside(realHome)).toBe(false);
	});

	it('every per-user folder points into the sandbox', () => {
		expect(inside(homedir())).toBe(true);
		for (const name of ['HOME', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_STATE_HOME', 'XDG_CACHE_HOME']) {
			expect(inside(process.env[name] ?? ''), name).toBe(true);
		}
	});

	it('the state folder and the sites file are in the sandbox, and the sites file is absent', () => {
		expect(inside(defaultStateDir(process.env))).toBe(true);
		expect(inside(process.env['STONEWRIGHT_SITES_FILE'] ?? '')).toBe(true);
		expect(existsSync(process.env['STONEWRIGHT_SITES_FILE'] ?? '')).toBe(false);
	});

	it('the default locations, with no environment at all, are also in the sandbox', () => {
		// Code that is given an empty environment falls back to the home folder.
		expect(inside(defaultStateDir({}))).toBe(true);
		expect(inside(defaultSitesPath({}))).toBe(true);
		expect(inside(join(homedir(), '.stonewright', 'sites.json'))).toBe(true);
		expect(join(homedir(), '.stonewright')).not.toBe(join(realHome, '.stonewright'));
	});

	it('no site can be loaded from a file: the developer sites file is out of reach', () => {
		expect(() => loadSitesConfig({ env: process.env })).toThrow(/credentials missing/i);
		expect(() => loadSitesConfig({ env: {} })).toThrow(/credentials missing/i);
	});

	it('no site credentials or mode variables are inherited from the shell', () => {
		const inherited = Object.keys(process.env).filter(
			(key) =>
				(key.startsWith('STONEWRIGHT_') && !['STONEWRIGHT_STATE_DIR', 'STONEWRIGHT_SITES_FILE', 'STONEWRIGHT_OAUTH_FIXTURE_RUN_ID'].includes(key)) ||
				/^(WP_API_URL|WP_API_USERNAME|WP_API_PASSWORD|WP_USERNAME|WP_APP_PASSWORD)$/.test(key),
		);
		expect(inherited).toEqual([]);
	});
});

describe('the test network guard', () => {
	it('classifies hosts', () => {
		expect(isTestNetworkAllowed('127.0.0.1')).toBe(true);
		expect(isTestNetworkAllowed('localhost')).toBe(true);
		expect(isTestNetworkAllowed('::1')).toBe(true);
		expect(isTestNetworkAllowed('example.com')).toBe(false);
		expect(isTestNetworkAllowed('203.0.113.7')).toBe(false);
		allowTestNetworkHost('Allowed.example.net');
		expect(isTestNetworkAllowed('allowed.example.net')).toBe(true);
		resetTestNetworkAllowList();
		expect(isTestNetworkAllowed('allowed.example.net')).toBe(false);
	});

	it('a real fetch to a remote host fails before anything is sent', async () => {
		const before = blockedTestNetworkAttempts().length;
		const error = await fetch('https://sites-file-guard.example.com/wp-json/').then(
			() => null,
			(e: unknown) => e as Error & { cause?: Error },
		);
		expect(error).not.toBeNull();
		expect(`${error?.message} ${error?.cause?.message ?? ''}`).toMatch(/Test network guard/);
		expect(blockedTestNetworkAttempts().slice(before)).toContain('sites-file-guard.example.com');
	});

	it('a raw socket to a public address is refused too', async () => {
		const error = await new Promise<Error>((done) => {
			const socket = net.connect({ host: '203.0.113.9', port: 443 });
			socket.on('error', done);
		});
		expect(error.message).toMatch(/Test network guard/);
	});

	it('a name that the caller resolves itself is refused when the answer is a public address', async () => {
		const lookup = (_host: string, options: { all?: boolean }, callback: (...args: unknown[]) => void): void => {
			if (options.all) callback(null, [{ address: '203.0.113.9', family: 4 }]);
			else callback(null, '203.0.113.9', 4);
		};
		const error = await new Promise<Error>((done) => {
			const socket = net.connect({ host: 'pinned.example.com', port: 443, lookup: lookup as never });
			socket.on('error', done);
		});
		expect(error.message).toMatch(/Test network guard/);
	});

	it('a name that the caller resolves itself to loopback is allowed through', async () => {
		const server = createServer((_req, res) => res.end('pinned'));
		await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
		try {
			const { port } = server.address() as net.AddressInfo;
			const lookup = (_host: string, options: { all?: boolean }, callback: (...args: unknown[]) => void): void => {
				if (options.all) callback(null, [{ address: '127.0.0.1', family: 4 }]);
				else callback(null, '127.0.0.1', 4);
			};
			const body = await new Promise<string>((done, fail) => {
				const request = http.request({ host: 'pinned.example.test', port, lookup: lookup as never, agent: false }, (response) => {
					let text = '';
					response.on('data', (chunk: Buffer) => (text += chunk.toString()));
					response.on('end', () => done(text));
				});
				request.on('error', fail);
				request.end();
			});
			expect(body).toBe('pinned');
		} finally {
			await new Promise<void>((done) => server.close(() => done()));
		}
	});

	it('loopback still works', async () => {
		const server = createServer((_req, res) => res.end('ok'));
		await new Promise<void>((done) => server.listen(0, '127.0.0.1', done));
		try {
			const { port } = server.address() as net.AddressInfo;
			const body = await (await fetch(`http://127.0.0.1:${port}/`)).text();
			expect(body).toBe('ok');
		} finally {
			await new Promise<void>((done) => server.close(() => done()));
		}
	});
});
