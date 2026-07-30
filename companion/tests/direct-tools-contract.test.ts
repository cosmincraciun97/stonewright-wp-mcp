import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { DIRECT_TOOL_NAMES } from '../src/direct/registry.js';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const contractPath = join(repoRoot, 'docs', 'contracts', 'direct-tools-v1.json');

type DirectToolsContract = {
	version: number;
	allowlist?: {
		removed?: string[];
		renamed?: Record<string, string>;
		schema_changes?: string[];
	};
	tools: Array<{ name: string }>;
};

function loadContract(): DirectToolsContract {
	const raw = readFileSync(contractPath, 'utf8');
	return JSON.parse(raw) as DirectToolsContract;
}

function compatibilityViolations(
	frozen: DirectToolsContract,
	liveNames: readonly string[],
): string[] {
	const live = new Set(liveNames);
	const removedAllow = new Set(frozen.allowlist?.removed ?? []);
	const renamedAllow = frozen.allowlist?.renamed ?? {};
	const violations: string[] = [];

	for (const tool of frozen.tools ?? []) {
		const name = tool?.name;
		if (!name) {
			violations.push('frozen tool entry missing name');
			continue;
		}
		if (live.has(name)) {
			continue;
		}
		if (name in renamedAllow) {
			const target = renamedAllow[name];
			if (!live.has(target)) {
				violations.push(`renamed tool missing target: ${name} -> ${target}`);
			}
			continue;
		}
		if (removedAllow.has(name)) {
			continue;
		}
		violations.push(`removed direct tool without allowlist: ${name}`);
	}

	return violations;
}

describe('direct-tools contract', () => {
	it('frozen contract is compatible with DIRECT_TOOL_NAMES', () => {
		const frozen = loadContract();
		expect(frozen.version).toBe(1);
		expect(Array.isArray(frozen.tools)).toBe(true);
		expect(frozen.tools.length).toBeGreaterThanOrEqual(98);

		const names = frozen.tools.map((t) => t.name);
		const sorted = [...names].sort((a, b) => a.localeCompare(b));
		expect(names).toEqual(sorted);

		const violations = compatibilityViolations(frozen, DIRECT_TOOL_NAMES);
		expect(violations).toEqual([]);
	});

	it('live DIRECT_TOOL_NAMES may grow beyond the frozen set', () => {
		const frozen = loadContract();
		const frozenNames = new Set(frozen.tools.map((t) => t.name));
		// Every live name is either new or already contracted — no extra assert
		// beyond ensuring the registry export is a non-empty unique list.
		const live = [...DIRECT_TOOL_NAMES];
		expect(live.length).toBeGreaterThanOrEqual(frozenNames.size);
		expect(new Set(live).size).toBe(live.length);
	});

	it('allowlist permits intentional removal', () => {
		const frozen: DirectToolsContract = {
			version: 1,
			allowlist: { removed: ['stonewright-legacy'], renamed: {}, schema_changes: [] },
			tools: [{ name: 'stonewright-content-list' }, { name: 'stonewright-legacy' }],
		};
		expect(compatibilityViolations(frozen, ['stonewright-content-list'])).toEqual([]);
	});

	it('removal without allowlist fails', () => {
		const frozen: DirectToolsContract = {
			version: 1,
			allowlist: { removed: [], renamed: {}, schema_changes: [] },
			tools: [{ name: 'stonewright-content-list' }, { name: 'stonewright-legacy' }],
		};
		const violations = compatibilityViolations(frozen, ['stonewright-content-list']);
		expect(violations).toEqual(['removed direct tool without allowlist: stonewright-legacy']);
	});
});
