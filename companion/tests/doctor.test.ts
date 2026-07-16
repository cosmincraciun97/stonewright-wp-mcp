import { describe, expect, it } from 'vitest';
import {
	checkCredentialsPresent,
	checkNodeVersion,
	checkRestIndex,
	checkStaleToolCacheHint,
	resolveCredentials,
	runDoctorChecks,
} from '../src/cli/doctor.js';

describe('companion doctor', () => {
	it('passes node version >= 20', () => {
		expect(checkNodeVersion('v20.11.0').status).toBe('passed');
		expect(checkNodeVersion('v18.0.0').status).toBe('failed');
	});

	it('resolves credentials from environment without exposing password', () => {
		const creds = resolveCredentials({
			STONEWRIGHT_WP_URL: 'https://example.test/',
			STONEWRIGHT_WP_USERNAME: 'admin',
			STONEWRIGHT_WP_APP_PASSWORD: 'secret-value',
		});
		expect(creds).toEqual({
			url: 'https://example.test',
			username: 'admin',
			hasPassword: true,
			source: 'environment',
		});
		expect(JSON.stringify(creds)).not.toContain('secret-value');
	});

	it('fails credentials check when missing', () => {
		const check = checkCredentialsPresent(null);
		expect(check.status).toBe('failed');
		expect(check.fix).toMatch(/init|STONEWRIGHT_WP_/);
	});

	it('tool cache hint is always present as warn with client refresh fix', () => {
		const check = checkStaleToolCacheHint();
		expect(check.status).toBe('warn');
		expect(check.fix).toMatch(/Codex|Cursor|Claude/i);
	});

	it('checkRestIndex passes when wp/v2 namespace is present', async () => {
		const fetchImpl = (() =>
			Promise.resolve(
				new Response(JSON.stringify({ namespaces: ['oembed/1.0', 'wp/v2'] }), {
					status: 200,
					headers: { 'Content-Type': 'application/json' },
				}),
			)) as typeof fetch;
		const check = await checkRestIndex(
			{ url: 'https://example.test', username: 'admin', hasPassword: true, source: 'environment' },
			fetchImpl,
		);
		expect(check.status).toBe('passed');
		expect(check.id).toBe('rest_index');
	});

	it('runDoctorChecks marks ok only when mcp initialize passes', async () => {
		const fetchImpl = ((input: Parameters<typeof fetch>[0], _init?: RequestInit) => {
			const url = String(input);
			if (url.endsWith('/wp-json/') || url.includes('/wp-json/?') || /\/wp-json\/?$/.test(url)) {
				return Promise.resolve(
					new Response(JSON.stringify({ namespaces: ['wp/v2', 'mcp'] }), {
						status: 200,
						headers: { 'Content-Type': 'application/json' },
					}),
				);
			}
			if (url.includes('/users/me')) {
				return Promise.resolve(new Response(JSON.stringify({ name: 'Admin' }), { status: 200 }));
			}
			if (url.includes('/mcp/stonewright')) {
				return Promise.resolve(
					new Response(
						JSON.stringify({
							jsonrpc: '2.0',
							id: 1,
							result: { protocolVersion: '2025-06-18', serverInfo: { name: 'stonewright' } },
						}),
						{ status: 200, headers: { 'Content-Type': 'application/json' } },
					),
				);
			}
			return Promise.resolve(new Response('not found', { status: 404 }));
		}) as typeof fetch;

		const report = await runDoctorChecks({
			nodeVersion: 'v22.0.0',
			env: {
				PATH: '/usr/bin',
				STONEWRIGHT_WP_URL: 'https://example.test',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'aaaa bbbb cccc dddd',
			},
			fetchImpl,
		});

		expect(report.ok).toBe(true);
		expect(report.checks.find((c) => c.id === 'mcp_initialize')?.status).toBe('passed');
		expect(JSON.stringify(report)).not.toContain('aaaa bbbb');
	});

	it('runDoctorChecks fails on 401 rest auth', async () => {
		const fetchImpl = (() => Promise.resolve(new Response('nope', { status: 401 }))) as typeof fetch;
		const report = await runDoctorChecks({
			nodeVersion: 'v22.0.0',
			env: {
				PATH: '/usr/bin',
				STONEWRIGHT_WP_URL: 'https://example.test',
				STONEWRIGHT_WP_USERNAME: 'admin',
				STONEWRIGHT_WP_APP_PASSWORD: 'bad-pass',
			},
			fetchImpl,
		});
		expect(report.ok).toBe(false);
		expect(report.checks.find((c) => c.id === 'rest_auth')?.status).toBe('failed');
	});
});
