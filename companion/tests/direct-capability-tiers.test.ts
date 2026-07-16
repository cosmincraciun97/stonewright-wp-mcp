import { describe, expect, it } from 'vitest';
import {
	CAPABILITY_FEATURES,
	CAPABILITY_TIERS,
	PLUGIN_ONLY_FEATURE_IDS,
	describeUnavailable,
	featuresUnavailableForRemoteRest,
	getCapabilityTier,
	isFeatureAvailableInTier,
} from '../src/direct/capability-tiers.js';
import {
	DIRECT_BOOTSTRAP_TOOL_NAMES,
	DIRECT_ESSENTIAL_TOOL_NAMES,
	DIRECT_TOOL_NAMES,
	shouldRegisterDirectTool,
	suggestDirectToolProfile,
} from '../src/direct/registry.js';

describe('Direct capability tiers honesty', () => {
	it('exports exactly four product tiers', () => {
		expect(CAPABILITY_TIERS.map((t) => t.id)).toEqual([
			'remote-rest',
			'local-rest-wpcli',
			'plugin',
			'plugin-browser-qa',
		]);
	});

	it('does not promise plugin-only features for remote-rest', () => {
		const unavailable = featuresUnavailableForRemoteRest().map((f) => f.id);
		for (const id of PLUGIN_ONLY_FEATURE_IDS) {
			expect(unavailable).toContain(id);
			expect(isFeatureAvailableInTier(id, 'remote-rest')).toBe(false);
			const desc = describeUnavailable(id, 'remote-rest');
			expect(desc?.reason.length).toBeGreaterThan(10);
			expect(desc?.upgrade.toLowerCase()).toMatch(/plugin|install|playwright|e2e/);
		}
	});

	it('remote-rest excludes php-execute, elementor-engine, design-spec, tokens', () => {
		const remote = getCapabilityTier('remote-rest');
		for (const feature of [
			'php-execute',
			'elementor-engine',
			'design-spec-render',
			'confirmation-tokens',
		]) {
			expect(remote.excludes).toContain(feature);
			expect(isFeatureAvailableInTier(feature, 'remote-rest')).toBe(false);
			expect(isFeatureAvailableInTier(feature, 'plugin')).toBe(true);
		}
	});

	it('local-rest-wpcli allows elementor data wpcli but not plugin engines', () => {
		expect(isFeatureAvailableInTier('elementor-data-wpcli', 'local-rest-wpcli')).toBe(true);
		expect(isFeatureAvailableInTier('elementor-data-wpcli', 'remote-rest')).toBe(false);
		expect(isFeatureAvailableInTier('elementor-engine', 'local-rest-wpcli')).toBe(false);
	});

	it('every feature documents upgrade path', () => {
		for (const feature of CAPABILITY_FEATURES) {
			expect(feature.upgradePath.length).toBeGreaterThan(8);
			expect(feature.availableIn.length).toBeGreaterThan(0);
		}
	});
});

describe('DIRECT_ESSENTIAL_TOOL_NAMES registration filter', () => {
	it('bootstrap is capped and always exposes task-start', () => {
		expect(DIRECT_BOOTSTRAP_TOOL_NAMES.length).toBeLessThanOrEqual(8);
		expect(DIRECT_BOOTSTRAP_TOOL_NAMES).toContain('stonewright-task-start');
		expect(shouldRegisterDirectTool('stonewright-task-start', 'bootstrap')).toBe(true);
		expect(shouldRegisterDirectTool('stonewright-content-update', 'bootstrap')).toBe(false);
	});

	it('essential names are a subset of registered Direct tools', () => {
		const all = new Set(DIRECT_TOOL_NAMES as readonly string[]);
		for (const name of DIRECT_ESSENTIAL_TOOL_NAMES) {
			expect(all.has(name)).toBe(true);
		}
	});

	it('selects compact task-aware Direct profiles', () => {
		expect(suggestDirectToolProfile('Implement this Figma card in Elementor')).toBe('elementor-design');
		expect(suggestDirectToolProfile('Create ACF fields for a CPT')).toBe('content-model');
		expect(suggestDirectToolProfile('Update Gutenberg global styles')).toBe('gutenberg');
		expect(suggestDirectToolProfile('Manage users and comments')).toBe('site-admin');
		expect(shouldRegisterDirectTool('stonewright-elementor-data-update', 'elementor-design')).toBe(true);
		expect(shouldRegisterDirectTool('stonewright-comment-list', 'elementor-design')).toBe(false);
		for (const profile of ['elementor-design', 'content-model', 'gutenberg', 'site-admin'] as const) {
			const visible = DIRECT_TOOL_NAMES.filter((name) => shouldRegisterDirectTool(name, profile));
			expect(visible.length).toBeLessThanOrEqual(50);
			expect(visible.length).toBeLessThan(DIRECT_TOOL_NAMES.length);
		}
	});

	it('shouldRegisterDirectTool gates essential profile', () => {
		expect(shouldRegisterDirectTool('stonewright-content-list', 'essential')).toBe(true);
		expect(shouldRegisterDirectTool('stonewright-comment-list', 'essential')).toBe(false);
		expect(shouldRegisterDirectTool('stonewright-comment-list', 'full')).toBe(true);
		expect(shouldRegisterDirectTool('stonewright-php-execute', 'essential')).toBe(false);
	});
});
