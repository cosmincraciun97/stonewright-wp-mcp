import { describe, expect, it, vi } from 'vitest';
import {
	maxToolsFromEnv,
	proxyToolNamesForProfile,
	resolvePluginProxyToolNames,
	trimToolsToMax,
} from '../src/wordpress-mcp.js';

describe('tool profile resolve + client cap', () => {
	it('falls back to local lists when plugin resolve is unavailable', async () => {
		const client = {
			callTool: vi.fn(async () => {
				throw new Error('offline');
			}),
		};
		const result = await resolvePluginProxyToolNames(client, 'essential');
		expect(result.source).toBe('fallback');
		expect(result.ordered).toBe(true);
		expect(result.tools).toEqual(proxyToolNamesForProfile('essential'));
		expect(result.tools).toContain('stonewright-blueprint-apply');
		expect(result.tools).toContain('stonewright-brand-kit-apply');
	});

	it('uses plugin-ordered tools when resolve succeeds', async () => {
		const client = {
			callTool: vi.fn(async () => ({
				ok: true,
				ordered: true,
				source: 'plugin',
				tools: [
					'stonewright-task-start',
					'stonewright-blueprint-apply',
					'stonewright-elementor-v3-build-page-from-spec',
				],
			})),
		};
		const result = await resolvePluginProxyToolNames(client, 'elementor-design');
		expect(result.source).toBe('plugin');
		expect(result.tools[0]).toBe('stonewright-task-start');
		expect(result.tools).toContain('stonewright-blueprint-apply');
		expect(client.callTool).toHaveBeenCalledWith(
			'stonewright-tool-profile',
			expect.objectContaining({ action: 'resolve', profile: 'elementor-design' }),
		);
	});

	it('trims tools deterministically from the tail under STONEWRIGHT_MCP_MAX_TOOLS', () => {
		const names = Array.from({ length: 80 }, (_, i) => `stonewright-tool-${i}`);
		const { kept, trimmed } = trimToolsToMax(names, 50);
		expect(kept).toHaveLength(50);
		expect(trimmed).toHaveLength(30);
		expect(kept[0]).toBe('stonewright-tool-0');
		expect(trimmed[0]).toBe('stonewright-tool-50');
	});

	it('reads STONEWRIGHT_MCP_MAX_TOOLS from env', () => {
		expect(maxToolsFromEnv({ STONEWRIGHT_MCP_MAX_TOOLS: '50' })).toBe(50);
		expect(maxToolsFromEnv({})).toBeNull();
	});

	it('keeps blueprints near the front of elementor-design fallback', () => {
		const names = proxyToolNamesForProfile('elementor-design');
		const head = names.slice(0, 12);
		expect(head).toEqual(
			expect.arrayContaining([
				'stonewright-blueprint-list',
				'stonewright-blueprint-get',
				'stonewright-blueprint-apply',
				'stonewright-brand-kit-list',
				'stonewright-brand-kit-apply',
			]),
		);
	});

	it('fallback site-admin includes wave-3 admin ops', () => {
		const names = proxyToolNamesForProfile('site-admin');
		for (const n of [
			'stonewright-comment-list',
			'stonewright-user-list',
			'stonewright-widget-list',
			'stonewright-settings-get',
			'stonewright-theme-activate',
			'stonewright-post-revision-restore',
			'stonewright-site-health-test',
			'stonewright-search-query',
		]) {
			expect(names).toContain(n);
		}
	});

	it('fallback content-model includes wc reads', () => {
		const names = proxyToolNamesForProfile('content-model');
		expect(names).toEqual(
			expect.arrayContaining([
				'stonewright-wc-product-list',
				'stonewright-wc-order-list',
				'stonewright-wc-sales-report',
			]),
		);
	});
});
