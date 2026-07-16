import { afterEach, describe, expect, it, vi } from 'vitest';
import './helpers/task-start.js';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { WpRestClient } from '../src/direct/wp-rest-client.js';
import type { ResolvedSite } from '../src/direct/sites-config.js';
import { DIRECT_TOOL_NAMES, DIRECT_WAVE2_TOOL_NAMES } from '../src/direct/registry.js';
import { siteDiscover } from '../src/direct/tools/site-discover.js';
import { settingsUpdate } from '../src/direct/tools/settings.js';
import { pluginActivate, pluginInstall } from '../src/direct/tools/plugins.js';
import { menuDelete } from '../src/direct/tools/menus.js';
import { globalStylesUpdate } from '../src/direct/tools/global-styles.js';
import { gutenbergCompose } from '../src/direct/tools/gutenberg-compose.js';

const site: ResolvedSite = {
	alias: 'remote',
	url: 'https://example.com',
	restBase: 'https://example.com/wp-json',
	username: 'admin',
	appPassword: 'pass',
	disabledTools: [],
};

describe('direct wave 2 tools', () => {
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

	it('exports wave-2 tools and total Direct surface >= 35', () => {
		expect(DIRECT_WAVE2_TOOL_NAMES).toContain('stonewright-site-discover');
		expect(DIRECT_WAVE2_TOOL_NAMES).toContain('stonewright-menu-list');
		expect(DIRECT_WAVE2_TOOL_NAMES).toContain('stonewright-gutenberg-compose');
		expect(DIRECT_TOOL_NAMES.length).toBeGreaterThanOrEqual(35);
	});

	it('site-discover returns namespaces, types, detections, and plugin-only gaps', async () => {
		const fetchImpl = vi.fn(async (input: RequestInfo | URL) => {
			const url = String(input);
			if (url.endsWith('/wp-json/') || url.endsWith('/wp-json')) {
				return new Response(
					JSON.stringify({
						name: 'Demo',
						namespaces: ['oembed/1.0', 'wp/v2', 'elementor/v1', 'wc/v3', 'acf/v3'],
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				);
			}
			if (url.includes('/wp/v2/types')) {
				return new Response(
					JSON.stringify({
						page: { slug: 'page', name: 'Pages', rest_base: 'pages', hierarchical: true },
						post: { slug: 'post', name: 'Posts', rest_base: 'posts', hierarchical: false },
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				);
			}
			if (url.includes('/wp/v2/taxonomies')) {
				return new Response(
					JSON.stringify({
						category: { slug: 'category', name: 'Categories', rest_base: 'categories', hierarchical: true },
					}),
					{ status: 200, headers: { 'content-type': 'application/json' } },
				);
			}
			return new Response('{}', { status: 404 });
		});
		const client = new WpRestClient(site, { fetchImpl });
		const result = await siteDiscover({ client, site, writeMode: 'confirm', fetchImpl });
		expect(result.mode).toBe('direct');
		expect(result.detected_plugins.elementor).toBe(true);
		expect(result.detected_plugins.woocommerce).toBe(true);
		expect(result.detected_plugins.acf).toBe(true);
		expect(result.post_types.some((t) => t.slug === 'page')).toBe(true);
		expect(result.unavailable_without_plugin.some((c) => c.id === 'elementor-engine')).toBe(true);
		expect(result.upgrade_hint).toMatch(/Stonewright plugin/i);
	});

	it('blocks remote settings update without confirm', async () => {
		homeDir();
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			settingsUpdate({ client, site, writeMode: 'confirm' }, { settings: { title: 'X' } }),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('allows settings update when confirm:true', async () => {
		homeDir();
		const fetchImpl = vi.fn(async () =>
			new Response(JSON.stringify({ title: 'X' }), {
				status: 200,
				headers: { 'content-type': 'application/json' },
			}),
		);
		const client = new WpRestClient(site, { fetchImpl });
		const result = await settingsUpdate(
			{ client, site, writeMode: 'confirm' },
			{ settings: { title: 'X' }, confirm: true },
		);
		expect(result.settings).toMatchObject({ title: 'X' });
		expect(fetchImpl).toHaveBeenCalledOnce();
	});

	it('blocks plugin activate/install without confirm in confirm mode', async () => {
		homeDir();
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			pluginActivate({ client, site, writeMode: 'confirm' }, { plugin: 'akismet/akismet' }),
		).rejects.toThrow(/confirm:true/i);
		await expect(
			pluginInstall({ client, site, writeMode: 'confirm' }, { slug: 'akismet' }),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('blocks menu delete and global styles update without confirm', async () => {
		homeDir();
		const fetchImpl = vi.fn(async () => new Response('{}', { status: 200 }));
		const client = new WpRestClient(site, { fetchImpl });
		await expect(
			menuDelete({ client, site, writeMode: 'confirm' }, { id: 3 }),
		).rejects.toThrow(/confirm:true/i);
		await expect(
			globalStylesUpdate({ client, site, writeMode: 'confirm' }, { styles: { color: {} } }),
		).rejects.toThrow(/confirm:true/i);
		expect(fetchImpl).not.toHaveBeenCalled();
	});

	it('composes gutenberg markup from a simple block spec (round-trip fixtures)', () => {
		const result = gutenbergCompose(
			{ site },
			{
				blocks: [
					{ type: 'heading', level: 2, content: 'Hello' },
					{ type: 'paragraph', content: 'World' },
					{
						type: 'buttons',
						children: [{ type: 'button', text: 'Go', url: 'https://example.com' }],
					},
				],
			},
		);
		expect(result.markup).toContain('<!-- wp:heading');
		expect(result.markup).toContain('<h2 class="wp-block-heading">Hello</h2>');
		expect(result.markup).toContain('<!-- wp:paragraph');
		expect(result.markup).toContain('<p class="wp-block-paragraph">World</p>');
		expect(result.markup).toContain('<!-- wp:buttons');
		expect(result.markup).toContain('wp-block-button__link');
		expect(result.block_count).toBe(3);
	});

	it('escapes HTML in composed text blocks', () => {
		const result = gutenbergCompose(
			{ site },
			{ blocks: [{ type: 'paragraph', content: '<script>x</script>' }] },
		);
		expect(result.markup).toContain('&lt;script&gt;x&lt;/script&gt;');
		expect(result.markup).not.toContain('<script>x</script>');
	});
});
