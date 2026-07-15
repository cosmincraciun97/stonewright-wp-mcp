import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
	allBudgetsPass,
	evaluateToolSurfaceBudgets,
	main,
	measureToolSurface,
	overBudgetFixtureReport,
	TOOL_SURFACE_LIMITS,
} from '../scripts/measure-tool-surface.mjs';

const companionRoot = join(dirname(fileURLToPath(import.meta.url)), '..');

describe('measure-tool-surface budgets', () => {
	it('marks over-budget metrics as failed', () => {
		const budgets = evaluateToolSurfaceBudgets({
			plugin_essential_tool_count: TOOL_SURFACE_LIMITS.plugin_essential_max_tools + 1,
			plugin_low_tools_tool_count: TOOL_SURFACE_LIMITS.plugin_low_tools_max_tools + 1,
			direct_present: true,
			direct_full_tool_count: TOOL_SURFACE_LIMITS.direct_full_max_tools + 1,
			direct_essential_present: true,
			direct_essential_tool_count: TOOL_SURFACE_LIMITS.direct_essential_max_tools + 1,
		});

		expect(allBudgetsPass(budgets)).toBe(false);
		expect(budgets.plugin_essential_max_20_tools).toBe(false);
		expect(budgets.plugin_low_tools_max_12_tools).toBe(false);
		expect(budgets.direct_full_max_40_tools).toBe(false);
		expect(budgets.direct_essential_max_20_tools).toBe(false);
	});

	it('fixture report is not ok', () => {
		const report = overBudgetFixtureReport();
		expect(report.ok).toBe(false);
		expect(report.fixture).toBe('over-budget');
		expect(Object.values(report.budgets).every(Boolean)).toBe(false);
	});

	it('live companion surface stays under plugin budgets', () => {
		const report = measureToolSurface();
		expect(report.metrics.plugin_essential_tool_count).toBeLessThanOrEqual(
			TOOL_SURFACE_LIMITS.plugin_essential_max_tools,
		);
		expect(report.metrics.plugin_low_tools_tool_count).toBeLessThanOrEqual(
			TOOL_SURFACE_LIMITS.plugin_low_tools_max_tools,
		);
		expect(report.budgets.plugin_essential_max_20_tools).toBe(true);
		expect(report.budgets.plugin_low_tools_max_12_tools).toBe(true);
		expect(report.ok).toBe(true);
	});

	it('CLI exits non-zero for over-budget fixture', () => {
		const result = spawnSync(
			process.execPath,
			[join(companionRoot, 'scripts/measure-tool-surface.mjs'), '--fixture=over-budget'],
			{ encoding: 'utf8' },
		);
		expect(result.status).toBe(1);
		const report = JSON.parse(result.stdout);
		expect(report.ok).toBe(false);
		expect(report.budgets.plugin_essential_max_20_tools).toBe(false);
	});

	it('main() returns 1 for over-budget fixture without exiting process', () => {
		expect(main(['--fixture=over-budget'])).toBe(1);
	});
});
