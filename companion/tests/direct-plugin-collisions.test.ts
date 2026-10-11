import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { DIRECT_TOOL_NAMES } from '../src/direct/registry.js';
import { DIRECT_ARG_ALIASES, DIRECT_PLUGIN_ONLY_ARGS } from '../src/direct/arg-aliases.js';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (...parts: string[]) => readFileSync(join(repoRoot, ...parts), 'utf8');

/** The tool names that exist in both modes. A new one is a decision: add its row to the docs, then to this list. */
const SHARED_TOOL_NAMES = [
	'blueprint-apply',
	'blueprint-get',
	'blueprint-list',
	'comment-create',
	'comment-delete',
	'comment-get',
	'comment-list',
	'comment-update',
	'content-create-page',
	'content-create-post',
	'incident-repair-record',
	'learning-record',
	'media-get',
	'media-list',
	'media-upload',
	'memory-list',
	'menu-create',
	'menu-delete',
	'menu-list',
	'plugin-activate',
	'plugin-deactivate',
	'plugin-delete',
	'rules-get',
	'settings-get',
	'settings-update',
	'task-start',
	'theme-activate',
	'theme-list',
	'user-create',
	'user-delete',
	'user-get',
	'user-list',
	'user-update',
	'wc-sales-report',
	'widget-list',
].map((name) => `stonewright-${name}`);

function pluginToolNames(): Set<string> {
	const matrix = read('docs', 'ability-truth-matrix.md');
	return new Set([...matrix.matchAll(/`(stonewright-[a-z0-9-]+)`/g)].map((m) => m[1]));
}

describe('tool names that exist in both Direct and Plugin mode', () => {
	it('are exactly the pinned list', () => {
		const plugin = pluginToolNames();
		const shared = DIRECT_TOOL_NAMES.filter((name) => plugin.has(name)).sort();
		expect(shared).toEqual([...SHARED_TOOL_NAMES].sort());
	});

	it('each have a row in the table of differences', () => {
		const doc = read('docs', 'direct-mode-e2e.md');
		const section = doc.slice(doc.indexOf('## Tools that exist in both modes'), doc.indexOf('## Live run log'));
		expect(section.length).toBeGreaterThan(0);
		for (const name of SHARED_TOOL_NAMES) {
			expect(section.includes(`| \`${name}\` |`), `${name} has no row`).toBe(true);
		}
	});

	it('only shared tools carry plugin argument names', () => {
		for (const tool of [...Object.keys(DIRECT_ARG_ALIASES), ...Object.keys(DIRECT_PLUGIN_ONLY_ARGS)]) {
			expect(SHARED_TOOL_NAMES, tool).toContain(tool);
		}
	});

	it('every plugin argument name is documented in the companion guide', () => {
		const guide = read('docs', 'companion.md');
		for (const rules of Object.values(DIRECT_ARG_ALIASES)) {
			for (const rule of rules) expect(guide.includes(`\`${rule.alias}\``), rule.alias).toBe(true);
		}
	});

	it('the plugin names in the alias table are real arguments of the plugin ability', () => {
		// The plugin's input schemas are PHP; check the names against the source of each ability.
		const sources: Record<string, string> = {
			'stonewright-comment-list': 'plugin/includes/Abilities/Comments/CommentList.php',
			'stonewright-comment-create': 'plugin/includes/Abilities/Comments/CommentCreate.php',
			'stonewright-menu-delete': 'plugin/includes/Abilities/Menu/MenuDelete.php',
			'stonewright-media-upload': 'plugin/includes/Abilities/Media/UploadMedia.php',
			'stonewright-user-create': 'plugin/includes/Abilities/Users/UserCreate.php',
			'stonewright-user-update': 'plugin/includes/Abilities/Users/UserUpdate.php',
			'stonewright-user-list': 'plugin/includes/Abilities/Users/UserList.php',
			'stonewright-blueprint-get': 'plugin/includes/Abilities/Blueprints/GetBlueprint.php',
			'stonewright-blueprint-apply': 'plugin/includes/Abilities/Blueprints/ApplyBlueprint.php',
			'stonewright-wc-sales-report': 'plugin/includes/Abilities/WooCommerce/WcSalesReport.php',
		};
		for (const [tool, rules] of Object.entries(DIRECT_ARG_ALIASES)) {
			const file = sources[tool];
			expect(file, `${tool} has no plugin source listed`).toBeDefined();
			const php = read(...file.split('/'));
			for (const rule of rules) expect(php.includes(`'${rule.alias}'`), `${tool}: ${rule.alias}`).toBe(true);
		}
		for (const [tool, args] of Object.entries(DIRECT_PLUGIN_ONLY_ARGS)) {
			const file = sources[tool];
			if (!file) continue;
			const php = read(...file.split('/'));
			for (const { arg } of args) expect(php.includes(`'${arg}'`), `${tool}: ${arg}`).toBe(true);
		}
	});
});
