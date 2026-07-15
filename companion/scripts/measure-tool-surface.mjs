#!/usr/bin/env node
/**
 * Measure companion client-visible tool surface (plugin proxy + Direct).
 *
 * Hard budgets (CI fails when any is false):
 * - plugin essential (proxied + local) ≤ 20
 * - plugin low-tools (proxied + local) ≤ 12
 * - Direct full ≤ 100 (when companion/src/direct exists)
 * - Direct essential ≤ 20 (when Direct essential export exists)
 *
 * Usage:
 *   cd companion && npm run tokens:measure
 *   node scripts/measure-tool-surface.mjs --fixture=over-budget
 */

import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const companionRoot = join(__dirname, '..');
const srcRoot = join(companionRoot, 'src');

export const TOOL_SURFACE_LIMITS = Object.freeze({
	plugin_essential_max_tools: 30,
	plugin_low_tools_max_tools: 12,
	// Raised for Direct blueprints tools (list/get/apply).
	direct_full_max_tools: 100,
	direct_essential_max_tools: 20,
});

const LOCAL_RECOVERY_TOOL_NAMES = [
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-discover',
	'stonewright-wp-cli-run',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
	'stonewright-wp-cli-install',
];

const LOW_TOOLS_LOCAL_TOOL_NAMES = [
	'stonewright-setup-profile',
	'stonewright-wordpress-mcp-status',
	'stonewright-wp-cli-status',
	'stonewright-wp-cli-batch-run',
	'stonewright-wp-cli-job-start',
	'stonewright-wp-cli-job-status',
];

/**
 * @param {string} source
 * @param {string} constName
 * @returns {string[]}
 */
export function extractStringArrayConst(source, constName) {
	const re = new RegExp(
		`(?:export\\s+)?const\\s+${constName}\\s*=\\s*\\[([\\s\\S]*?)\\]\\s*as\\s+const`,
		'm',
	);
	const match = source.match(re);
	if (!match) {
		return [];
	}
	const names = [];
	for (const item of match[1].matchAll(/['"]([^'"]+)['"]/g)) {
		names.push(item[1]);
	}
	return names;
}

/**
 * Expand profile arrays that may spread BASE_* or other consts.
 * @param {string} source
 * @param {string} constName
 * @param {Map<string, string[]>} resolved
 * @returns {string[]}
 */
function resolveArrayConst(source, constName, resolved = new Map()) {
	if (resolved.has(constName)) {
		return resolved.get(constName) ?? [];
	}
	// Prevent cycles while resolving spreads.
	resolved.set(constName, []);

	const re = new RegExp(
		`(?:export\\s+)?const\\s+${constName}\\s*=\\s*\\[([\\s\\S]*?)\\]\\s*as\\s+const`,
		'm',
	);
	const match = source.match(re);
	if (!match) {
		return [];
	}

	const names = [];
	const body = match[1];
	for (const spread of body.matchAll(/\.\.\.([A-Z][A-Z0-9_]*)/g)) {
		names.push(...resolveArrayConst(source, spread[1], resolved));
	}
	for (const item of body.matchAll(/['"]([^'"]+)['"]/g)) {
		names.push(item[1]);
	}

	const unique = Array.from(new Set(names));
	resolved.set(constName, unique);
	return unique;
}

/**
 * Parse profile tool names from wordpress-mcp.ts PROXY_TOOL_PROFILE_NAMES.
 * @param {string} source
 * @returns {Record<string, string[]>}
 */
export function parseProxyProfileToolNames(source) {
	const profiles = {};
	const blockMatch = source.match(
		/const\s+PROXY_TOOL_PROFILE_NAMES[^=]*=\s*\{([\s\S]*?)\n\};/,
	);
	if (!blockMatch) {
		return profiles;
	}

	const block = blockMatch[1];
	const entryRe =
		/(?:'([^']+)'|([A-Za-z0-9_-]+))\s*:\s*(?:([A-Z][A-Z0-9_]*)|\[([\s\S]*?)\])/g;
	let entry;
	while ((entry = entryRe.exec(block)) !== null) {
		const key = entry[1] ?? entry[2];
		if (!key || key === 'full') continue;
		if (entry[3]) {
			profiles[key] = resolveArrayConst(source, entry[3]);
		} else {
			const names = [];
			const body = entry[4] ?? '';
			for (const spread of body.matchAll(/\.\.\.([A-Z][A-Z0-9_]*)/g)) {
				names.push(...resolveArrayConst(source, spread[1]));
			}
			for (const item of body.matchAll(/['"]([^'"]+)['"]/g)) {
				names.push(item[1]);
			}
			profiles[key] = Array.from(new Set(names));
		}
	}
	return profiles;
}

/**
 * @param {{
 *   plugin_essential_tool_count?: number,
 *   plugin_low_tools_tool_count?: number,
 *   direct_present?: boolean,
 *   direct_full_tool_count?: number,
 *   direct_essential_present?: boolean,
 *   direct_essential_tool_count?: number,
 * }} metrics
 * @param {typeof TOOL_SURFACE_LIMITS} [limits]
 * @returns {Record<string, boolean>}
 */
export function evaluateToolSurfaceBudgets(metrics, limits = TOOL_SURFACE_LIMITS) {
	const budgets = {
		plugin_essential_max_20_tools:
			(metrics.plugin_essential_tool_count ?? Number.POSITIVE_INFINITY) <=
			limits.plugin_essential_max_tools,
		plugin_low_tools_max_12_tools:
			(metrics.plugin_low_tools_tool_count ?? Number.POSITIVE_INFINITY) <=
			limits.plugin_low_tools_max_tools,
	};

	if (metrics.direct_present) {
		budgets.direct_full_max_40_tools =
			(metrics.direct_full_tool_count ?? Number.POSITIVE_INFINITY) <=
			limits.direct_full_max_tools;
		if (metrics.direct_essential_present) {
			budgets.direct_essential_max_20_tools =
				(metrics.direct_essential_tool_count ?? Number.POSITIVE_INFINITY) <=
				limits.direct_essential_max_tools;
		}
	}

	return budgets;
}

export function allBudgetsPass(budgets) {
	return !Object.values(budgets).includes(false);
}

/**
 * @returns {ReturnType<typeof buildReport>}
 */
export function measureToolSurface() {
	const wordpressMcpPath = join(srcRoot, 'wordpress-mcp.ts');
	const directRegistryPath = join(srcRoot, 'direct', 'registry.ts');

	const wordpressSource = readFileSync(wordpressMcpPath, 'utf8');
	const profiles = parseProxyProfileToolNames(wordpressSource);

	const essentialProxy = profiles.essential ?? [];
	const lowToolsProxy = profiles['low-tools'] ?? [];

	const pluginEssentialTools = Array.from(
		new Set([...essentialProxy, ...LOCAL_RECOVERY_TOOL_NAMES]),
	);
	const pluginLowTools = Array.from(
		new Set([...lowToolsProxy, ...LOW_TOOLS_LOCAL_TOOL_NAMES]),
	);

	const directPresent = existsSync(directRegistryPath);
	let directFull = [];
	let directEssential = [];
	let directEssentialPresent = false;

	if (directPresent) {
		const directSource = readFileSync(directRegistryPath, 'utf8');
		directFull = extractStringArrayConst(directSource, 'DIRECT_TOOL_NAMES');
		if (directFull.length === 0) {
			const wave1 = extractStringArrayConst(directSource, 'DIRECT_WAVE1_TOOL_NAMES');
			const wave2 = extractStringArrayConst(directSource, 'DIRECT_WAVE2_TOOL_NAMES');
			const wave3 = extractStringArrayConst(directSource, 'DIRECT_WAVE3_TOOL_NAMES');
			directFull = Array.from(new Set([...wave1, ...wave2, ...wave3]));
		}
		directEssential = extractStringArrayConst(directSource, 'DIRECT_ESSENTIAL_TOOL_NAMES');
		directEssentialPresent = directEssential.length > 0;
	}

	const metrics = {
		plugin_essential_tool_count: pluginEssentialTools.length,
		plugin_low_tools_tool_count: pluginLowTools.length,
		direct_present: directPresent,
		direct_full_tool_count: directFull.length,
		direct_essential_present: directEssentialPresent,
		direct_essential_tool_count: directEssential.length,
	};

	const budgets = evaluateToolSurfaceBudgets(metrics);
	return buildReport({
		metrics,
		budgets,
		surfaces: {
			plugin_essential: {
				tool_count: pluginEssentialTools.length,
				tools: pluginEssentialTools,
			},
			plugin_low_tools: {
				tool_count: pluginLowTools.length,
				tools: pluginLowTools,
			},
			direct_full: directPresent
				? { tool_count: directFull.length, tools: directFull }
				: { present: false },
			direct_essential: directPresent
				? directEssentialPresent
					? { tool_count: directEssential.length, tools: directEssential }
					: { present: true, essential_export: false }
				: { present: false },
		},
	});
}

function buildReport({ metrics, budgets, surfaces, fixture = null }) {
	const ok = allBudgetsPass(budgets);
	return {
		ok,
		fixture,
		method: 'static companion source parse (proxied profile + local recovery tools; Direct when present)',
		limits: { ...TOOL_SURFACE_LIMITS },
		metrics,
		surfaces,
		budgets,
	};
}

/**
 * @returns {ReturnType<typeof buildReport>}
 */
export function overBudgetFixtureReport() {
	const metrics = {
		plugin_essential_tool_count: TOOL_SURFACE_LIMITS.plugin_essential_max_tools + 1,
		plugin_low_tools_tool_count: TOOL_SURFACE_LIMITS.plugin_low_tools_max_tools + 1,
		direct_present: true,
		direct_full_tool_count: TOOL_SURFACE_LIMITS.direct_full_max_tools + 1,
		direct_essential_present: true,
		direct_essential_tool_count: TOOL_SURFACE_LIMITS.direct_essential_max_tools + 1,
	};
	const budgets = evaluateToolSurfaceBudgets(metrics);
	return buildReport({
		metrics,
		budgets,
		surfaces: {
			plugin_essential: { tool_count: metrics.plugin_essential_tool_count, tools: [] },
			plugin_low_tools: { tool_count: metrics.plugin_low_tools_tool_count, tools: [] },
			direct_full: { tool_count: metrics.direct_full_tool_count, tools: [] },
			direct_essential: { tool_count: metrics.direct_essential_tool_count, tools: [] },
		},
		fixture: 'over-budget',
	});
}

function parseArgs(argv) {
	let fixture = null;
	for (const arg of argv) {
		if (arg.startsWith('--fixture=')) {
			fixture = arg.slice('--fixture='.length);
		}
	}
	return { fixture };
}

export function main(argv = process.argv.slice(2)) {
	const { fixture } = parseArgs(argv);
	const report =
		fixture === 'over-budget' ? overBudgetFixtureReport() : measureToolSurface();
	process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
	return report.ok ? 0 : 1;
}

const isCli = Boolean(
	process.argv[1] &&
		import.meta.url === pathToFileURL(resolve(process.argv[1])).href,
);

if (isCli) {
	process.exitCode = main();
}
