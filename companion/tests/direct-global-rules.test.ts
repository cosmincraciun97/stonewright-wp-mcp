import { createHash } from 'node:crypto';
import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import './helpers/task-start.js';
import {
	globalRules,
	globalRulesDigest,
	parseGlobalRules,
	rulesGet,
} from '../src/direct/global-rules.js';
import { DIRECT_TOOL_NAMES } from '../src/direct/registry.js';
import {
	taskStart,
	type SelfImproveContext,
} from '../src/direct/tools/self-improve.js';

function ctx(): SelfImproveContext {
	return {
		env: {
			STONEWRIGHT_SITES_FILE: '/tmp/does-not-exist-sw-sites-global-rules.json',
		} as NodeJS.ProcessEnv,
		baseDir: mkdtempSync(join(tmpdir(), 'sw-rules-')),
		directToolCount: DIRECT_TOOL_NAMES.length,
	};
}

const PLUGIN_REGISTRY = resolve(
	import.meta.dirname,
	'../../plugin/data/global-rules.json',
);

/**
 * Direct mode has no plugin to ask, so it ships its own copy of the registry.
 * A copy that drifts is worse than no copy: the agent would be told a digest
 * the plugin never issued. These tests pin the copy to the plugin source and
 * to the plugin's digest algorithm.
 */
describe('direct global rules', () => {
	it('ships a byte-identical copy of the plugin registry', () => {
		const shipped = resolve(import.meta.dirname, '../data/global-rules.json');
		expect(existsSync(shipped)).toBe(true);
		expect(JSON.parse(readFileSync(shipped, 'utf8'))).toEqual(
			JSON.parse(readFileSync(PLUGIN_REGISTRY, 'utf8')),
		);
	});

	it('computes the same digest the plugin computes', () => {
		const records: unknown = JSON.parse(readFileSync(PLUGIN_REGISTRY, 'utf8'));
		// Mirror of GlobalRules::digest_of(): sha1 over the canonical encoding.
		const expected = createHash('sha1')
			.update(JSON.stringify(records))
			.digest('hex');

		expect(globalRulesDigest()).toBe(expected);
	});

	it('rejects malformed or contradictory registry records', () => {
		const valid = globalRules()[0];

		expect(() => parseGlobalRules({ rules: [] })).toThrow(/list/i);
		expect(() => parseGlobalRules([{ ...valid, why: '' }])).toThrow(/why/i);
		expect(() =>
			parseGlobalRules([
				{
					...valid,
					severity: 'hard',
					enforcement: { kind: 'instruction', guard: '' },
				},
			]),
		).toThrow(/runtime guard/i);
		expect(() => parseGlobalRules([valid, valid])).toThrow(/duplicate/i);
	});

	it('returns every rule with its enforcement claim intact', () => {
		const result = rulesGet({});

		expect(result.ok).toBe(true);
		expect(result.unchanged).toBe(false);
		expect(result.count).toBe(globalRules().length);
		for (const rule of result.rules) {
			expect(Object.keys(rule).sort()).toEqual([
				'enforcement',
				'id',
				'rule',
				'scope',
				'severity',
				'why',
			]);
			if (rule.severity === 'hard') {
				expect(rule.enforcement.kind).toBe('runtime');
				expect(rule.enforcement.guard).not.toBe('');
			}
		}
	});

	it('filters by severity and keeps global rules when scoped', () => {
		const hard = rulesGet({ severity: 'hard' });
		expect(hard.rules.every((r) => r.severity === 'hard')).toBe(true);
		expect(hard.digest).not.toBe(globalRulesDigest());

		const elementor = rulesGet({ scope: 'elementor' });
		const scopes = [...new Set(elementor.rules.map((r) => r.scope))].sort();
		expect(scopes).toEqual(['all', 'elementor']);
	});

	it('rejects unknown severity and scope values', () => {
		expect(() => rulesGet({ severity: 'medium' })).toThrow(/severity/i);
		expect(() => rulesGet({ scope: 'seo' })).toThrow(/scope/i);
	});

	it('omits bodies when the caller already has the digest', () => {
		const first = rulesGet({});
		const cached = rulesGet({ knownDigest: first.digest });

		expect(cached.unchanged).toBe(true);
		expect(cached.rules).toEqual([]);
		expect(cached.count).toBe(first.count);
	});

	it('short-circuits per filter, not globally', () => {
		const hard = rulesGet({ severity: 'hard' });
		const cached = rulesGet({ severity: 'hard', knownDigest: hard.digest });

		expect(cached.unchanged).toBe(true);
		// A digest cached for the hard slice must not satisfy the full request.
		expect(rulesGet({ knownDigest: hard.digest }).unchanged).toBe(false);
	});
});

describe('direct task start rule reference', () => {
	it('reports the same digest the plugin reports', () => {
		const start = taskStart(ctx(), { task: 'update a page title' });

		expect(start.hard_rules).toEqual({
			digest: globalRulesDigest(),
			tool: 'stonewright-rules-get',
		});
	});

	it('exposes the rules tool on the Direct surface', () => {
		expect(DIRECT_TOOL_NAMES).toContain('stonewright-rules-get');
	});
});
