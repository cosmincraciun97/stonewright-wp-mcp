import { describe, expect, it, vi } from 'vitest';
import { PERMANENT_GATEWAY_TOOL_NAMES } from '../src/connection/permanent-gateways.js';
import {
	INSPECT_LOCAL_TOOL_NAMES,
	coerceProxyToolProfile,
	effectiveInitialProxyProfile,
	maxToolsFromEnv,
	proxyToolNamesForProfile,
	proxyToolProfileFromEnv,
	resolvePluginProxyToolNames,
	trimToolsToMax,
} from '../src/wordpress-mcp.js';

describe('tool profile resolve + client cap', () => {
	it('falls back to local lists when plugin resolve is unavailable', async () => {
		const client = {
			callTool: vi.fn(() => Promise.reject(new Error('offline'))),
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
			callTool: vi.fn(() =>
				Promise.resolve({
					ok: true,
					ordered: true,
					source: 'plugin',
					tools: [
						'stonewright-task-start',
						'stonewright-blueprint-apply',
						'stonewright-elementor-v3-build-page-from-spec',
					],
					mcp_surface: 'full',
					surface_revision: 4,
				}),
			),
		};
		const result = await resolvePluginProxyToolNames(client, 'elementor-design');
		expect(result.source).toBe('plugin');
		expect(result.tools[0]).toBe('stonewright-task-start');
		expect(result.tools).toContain('stonewright-blueprint-apply');
		expect(result.configuredSurface).toBe('full');
		expect(result.surfaceRevision).toBe(4);
		expect(client.callTool).toHaveBeenCalledWith(
			'stonewright-tool-profile',
			expect.objectContaining({ action: 'resolve', profile: 'elementor-design' }),
		);
	});

	it('supports the real bootstrap surface instead of coercing it to essential', () => {
		expect(coerceProxyToolProfile('bootstrap')).toBe('bootstrap');
		expect(proxyToolNamesForProfile('bootstrap')).toContain('stonewright-task-start');
		expect(proxyToolNamesForProfile('bootstrap')).toContain('stonewright-php-execute');
		expect(proxyToolNamesForProfile('bootstrap')).toContain('stonewright-security-issue-confirmation-token');
		expect(proxyToolNamesForProfile('bootstrap').length).toBeLessThanOrEqual(12);
	});

	it('defaults fresh companion installs to essential-static', () => {
		expect(proxyToolProfileFromEnv({})).toBe('essential-static');
		expect(coerceProxyToolProfile('essential-static')).toBe('essential-static');
		expect(proxyToolNamesForProfile('essential-static')).toContain('stonewright-php-execute');
	});

	it('uses the saved plugin surface for normal clients and preserves strict overrides', () => {
		expect(effectiveInitialProxyProfile('essential', 'full', {})).toBe('full');
		expect(effectiveInitialProxyProfile('essential', 'bootstrap', {})).toBe('bootstrap');
		expect(effectiveInitialProxyProfile('low-tools', 'full', {})).toBe('low-tools');
		expect(effectiveInitialProxyProfile('essential', 'full', { STONEWRIGHT_MCP_TOOL_PROFILE_LOCK: '1' })).toBe('essential');
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
		expect(names).toContain('stonewright-elementor-document-health');
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
			'stonewright-security-audit-reconcile',
			'stonewright-security-runtime-data-purge',
			'stonewright-oauth-header-diagnostic',
			'stonewright-capability-preflight',
			'stonewright-form-delivery-diagnostic',
		]) {
			expect(names).toContain(n);
		}
	});

	it('fallback visual profiles expose the new batch and diagnostic contracts', () => {
		const elementor = proxyToolNamesForProfile('elementor-design');
		expect(elementor).toEqual(
			expect.arrayContaining([
				'stonewright-design-section-manifest',
				'stonewright-design-visual-compare',
				'stonewright-elementor-v3-legacy-debt-migrate',
				'stonewright-form-delivery-diagnostic',
				'stonewright-capability-preflight',
			]),
		);
		const gutenberg = proxyToolNamesForProfile('gutenberg');
		expect(gutenberg).toEqual(
			expect.arrayContaining([
				'stonewright-blocks-batch-mutate',
				'stonewright-design-section-manifest',
				'stonewright-design-visual-compare',
			]),
		);
	});

	it('discover-execute fallback is a protocol surface without php-execute', () => {
		expect(coerceProxyToolProfile('discover-execute')).toBe('discover-execute');
		const names = proxyToolNamesForProfile('discover-execute');
		expect(names).toEqual(
			expect.arrayContaining([
				'stonewright-task-start',
				'stonewright-tool-profile',
				'stonewright-discover-abilities',
				'stonewright-get-ability-info',
				'stonewright-execute-ability',
				'stonewright-security-issue-confirmation-token',
			]),
		);
		expect(names).not.toContain('stonewright-php-execute');
		expect(names.length).toBeLessThanOrEqual(16);
	});

	it('inspect fallback is a read-only surface without php-execute or any write tool', () => {
		expect(coerceProxyToolProfile('inspect')).toBe('inspect');
		const names = proxyToolNamesForProfile('inspect');
		expect(names).toEqual(
			expect.arrayContaining([
				'stonewright-context-bootstrap',
				'stonewright-task-start',
				'stonewright-tool-profile',
				'stonewright-skills-get',
				'stonewright-site-info',
				'stonewright-content-get-page',
				'stonewright-elementor-v3-get-page-structure',
				'stonewright-elementor-post-write-verify',
				'stonewright-elementor-document-health',
				'stonewright-site-health',
			]),
		);
		for (const forbidden of [
			'stonewright-php-execute',
			'stonewright-execute-ability',
			'stonewright-security-issue-confirmation-token',
			'stonewright-blueprint-apply',
			'stonewright-brand-kit-apply',
			'stonewright-design-direction-save',
			'stonewright-elementor-v3-batch-mutate',
			'stonewright-elementor-css-regenerate',
			'stonewright-settings-update',
			'stonewright-theme-file-patch',
			'stonewright-wp-cli-run',
		]) {
			expect(names, forbidden).not.toContain(forbidden);
		}
		expect(new Set(names).size).toBe(names.length);
		expect(names.length).toBeLessThanOrEqual(30);
	});

	it('inspect keeps only the local tools that read', () => {
		expect(INSPECT_LOCAL_TOOL_NAMES).toEqual(
			expect.arrayContaining([
				...PERMANENT_GATEWAY_TOOL_NAMES,
				'stonewright-wp-cli-status',
				'stonewright-wp-cli-discover',
			]),
		);
		for (const forbidden of [
			'stonewright-wp-cli-run',
			'stonewright-wp-cli-batch-run',
			'stonewright-wp-cli-job-start',
			'stonewright-wp-cli-job-status',
			'stonewright-wp-cli-install',
		]) {
			expect(INSPECT_LOCAL_TOOL_NAMES as readonly string[], forbidden).not.toContain(forbidden);
		}
	});

	it('never selects inspect implicitly', () => {
		for (const raw of ['', 'auto', 'default', 'fast', 'general', 'compact', 'unknown', 'read', 'readonly']) {
			expect(coerceProxyToolProfile(raw), raw).not.toBe('inspect');
		}
		expect(proxyToolProfileFromEnv({})).not.toBe('inspect');
		expect(proxyToolProfileFromEnv({ STONEWRIGHT_MCP_TOOL_PROFILE: 'inspect' })).toBe('inspect');
	});

	it('does not widen an inspect session when the plugin reports the profile back', () => {
		expect(coerceProxyToolProfile('Inspect')).toBe('inspect');
		expect(effectiveInitialProxyProfile('inspect', 'full', {})).toBe('inspect');
		expect(effectiveInitialProxyProfile('inspect', 'bootstrap', {})).toBe('inspect');
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
