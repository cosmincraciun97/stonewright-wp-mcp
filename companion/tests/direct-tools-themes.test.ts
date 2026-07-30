import { afterEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { customCss } from '../src/direct/tools/themes.js';

const site: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('Direct theme tools', () => {
	afterEach(() => {
		vi.restoreAllMocks();
	});

	it('reads Customizer CSS when core REST exposes it', async () => {
		const fetchImpl = vi.fn(() =>
			Promise.resolve(
				new Response(JSON.stringify({ custom_css: 'body { color: red; }' }), {
					status: 200,
					headers: { 'content-type': 'application/json' },
				}),
			),
		);
		const client = new WpRestClient(site, { fetchImpl });

		const result = await customCss(
			{ client, site, writeMode: 'on' },
			{ action: 'get' },
		);

		expect(result).toMatchObject({
			supported: true,
			action: 'get',
			settings: { custom_css: 'body { color: red; }' },
		});
		expect(fetchImpl).toHaveBeenCalledOnce();
	});

	it('blocks Direct custom CSS updates before any network write', async () => {
		const fetchImpl = vi.fn();
		const client = new WpRestClient(site, { fetchImpl });

		const result = await customCss(
			{ client, site, writeMode: 'on' },
			{ action: 'update', css: 'body { color: red; }', confirm: true },
		);

		expect(result).toMatchObject({
			supported: false,
			action: 'update',
			approval_required: true,
			agent_must_stop: true,
		});
		expect(result.hint).toMatch(/dry_run/);
		expect(result.hint).toMatch(/then stop/);
		expect(fetchImpl).not.toHaveBeenCalled();
	});
});
