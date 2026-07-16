import { afterEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { DIRECT_TOOL_NAMES, DIRECT_WAVE3_TOOL_NAMES, registerDirectTools } from '../src/direct/registry.js';
import { commentList, commentUpdate, commentDelete } from '../src/direct/tools/comments.js';
import { userCreate, userDelete, appPasswordList } from '../src/direct/tools/users.js';
import { healthCheck } from '../src/direct/tools/health.js';
import { restRequest } from '../src/direct/tools/rest-request.js';
import { mediaDelete } from '../src/direct/tools/media.js';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';

const site: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('direct wave 3 tools', () => {
	const dirs: string[] = [];
	const originalHome = process.env.HOME;

	afterEach(() => {
		if (originalHome === undefined) delete process.env.HOME;
		else process.env.HOME = originalHome;
		for (const dir of dirs.splice(0)) {
			rmSync(dir, { recursive: true, force: true });
		}
		vi.restoreAllMocks();
	});

	it('exports wave-3 tools and total Direct surface >= 80', () => {
		expect(DIRECT_WAVE3_TOOL_NAMES).toContain('stonewright-comment-list');
		expect(DIRECT_WAVE3_TOOL_NAMES).toContain('stonewright-health-check');
		expect(DIRECT_WAVE3_TOOL_NAMES).toContain('stonewright-rest-request');
		expect(DIRECT_TOOL_NAMES.length).toBeGreaterThanOrEqual(80);
	});

	it('registers every wave-3 tool exactly once', () => {
		const registered: string[] = [];
		const server = {
			tool: (name: string) => {
				registered.push(name);
			},
		} as unknown as McpServer;
		registerDirectTools(server, { env: process.env });
		for (const name of DIRECT_WAVE3_TOOL_NAMES) {
			expect(registered.filter((n) => n === name)).toHaveLength(1);
		}
	});

	it('comment-list hits /wp/v2/comments with compact fields', async () => {
		const fetchImpl = vi.fn(async () =>
			new Response(
				JSON.stringify([
					{ id: 5, post: 10, status: 'hold', author_name: 'A', content: { rendered: 'hi' }, date: '2026-01-01' },
				]),
				{ status: 200, headers: { 'content-type': 'application/json' } },
			),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await commentList({ client, site, writeMode: 'confirm' }, {});
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/comments');
		expect(result.items[0]).toMatchObject({ id: 5, post: 10, status: 'hold' });
	});

	it('comment-update moderates via status and requires confirm on remote', async () => {
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			commentUpdate({ client, site, writeMode: 'confirm' }, { id: 5, status: 'approved' }),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('comment-delete requires confirm', async () => {
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			commentDelete({ client, site, writeMode: 'confirm' }, { id: 5, force: true }),
		).rejects.toThrow(/confirm:true/i);
	});

	it('userDelete requires confirm even when writeMode is on', async () => {
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			userDelete({ client, site, writeMode: 'on' }, { id: 2, reassign: 1 }),
		).rejects.toThrow(/confirm:true/i);
	});

	it('userCreate posts to /wp/v2/users', async () => {
		const fetchImpl = vi.fn(async () =>
			new Response(JSON.stringify({ id: 9, username: 'n', email: 'n@example.com', name: 'N', roles: ['author'] }), {
				status: 201,
				headers: { 'content-type': 'application/json' },
			}),
		);
		const client = new WpRestClient(site, { fetchImpl });
		await userCreate(
			{ client, site, writeMode: 'on' },
			{ username: 'n', email: 'n@example.com', password: 'longpassword12', confirm: true },
		);
		expect(String(fetchImpl.mock.calls[0]?.[0])).toContain('/wp/v2/users');
	});

	it('appPasswordList omits password hashes', async () => {
		const fetchImpl = vi.fn(async () =>
			new Response(
				JSON.stringify([{ uuid: 'u1', name: 'cli', created: '2026-01-01', last_used: null, password: 'secret' }]),
				{ status: 200, headers: { 'content-type': 'application/json' } },
			),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await appPasswordList({ client, site, writeMode: 'on' }, { user_id: 1 });
		expect(result.items[0]).toMatchObject({ uuid: 'u1', name: 'cli' });
		expect(result.items[0]).not.toHaveProperty('password');
	});

	it('health-check captures per-test failures without rejecting', async () => {
		const fetchImpl = vi.fn(async (input: RequestInfo | URL) => {
			const url = String(input);
			if (url.includes('authorization-header')) {
				return new Response(JSON.stringify({ code: 'rest_forbidden' }), { status: 403 });
			}
			if (url.includes('directory-sizes')) {
				return new Response(JSON.stringify({ wordpress_size: 1 }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				});
			}
			return new Response(JSON.stringify({ status: 'good' }), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			});
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await healthCheck({ client, site, writeMode: 'on' });
		expect(result.tests.some((t) => 'error' in t)).toBe(true);
		expect(result.tests.some((t) => 'result' in t)).toBe(true);
	});

	it('rest-request rejects absolute URLs and path traversal', async () => {
		const client = new WpRestClient(site, { fetchImpl: vi.fn() });
		await expect(
			restRequest({ client, site, writeMode: 'on' }, { method: 'GET', path: 'https://evil.example/x' }),
		).rejects.toThrow(/REST route/);
		await expect(
			restRequest({ client, site, writeMode: 'on' }, { method: 'GET', path: '/wp/v2/../secret' }),
		).rejects.toThrow(/REST route/);
	});

	it('rest-request rejects write methods (read-only passthrough)', async () => {
		const client = new WpRestClient(site, { fetchImpl: vi.fn() });
		await expect(
			// @ts-expect-error intentional invalid write method
			restRequest({ client, site, writeMode: 'on' }, { method: 'POST', path: '/custom/v1/x', body: {} }),
		).rejects.toThrow(/read-only/i);
	});

	it('media-delete requires confirm on remote', async () => {
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			mediaDelete({ client, site, writeMode: 'confirm' }, { id: 3, force: true }),
		).rejects.toThrow(/confirm:true/i);
	});
});
