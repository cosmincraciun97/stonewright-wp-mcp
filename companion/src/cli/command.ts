/**
 * `stonewright command <subcommand>` CLI.
 *
 * Exit codes: 0 = success + verification passed; 1 = input/store/runtime/
 * verification failure; 2 = write plan valid, approval required.
 * No --yes, no shell strings, no password flags — ever.
 */

import { readFileSync } from 'node:fs';
import { findSiteByAlias, loadRegistry, defaultSitesPath } from '../cli/connect/registry.js';
import type { SiteRecordV2 } from '../cli/connect/types.js';
import type { ExecFileRunner } from '../wp-cli.js';
import {
	CommandError,
	auditCommandAction,
	getRecipe,
	listRecipes,
	parseCommandRecipe,
	planCommand,
	removeRecipe,
	runCommand,
	saveRecipe,
	type CommandPlanV1,
	type CommandRecipeV1,
} from '../commands/index.js';

function writeOut(msg: string): void {
	process.stdout.write(`${msg}\n`);
}

function writeErr(msg: string): void {
	process.stderr.write(`${msg}\n`);
}

interface ParsedArgs {
	positionals: string[];
	flags: Record<string, string | boolean | string[]>;
}

function parseArgs(argv: string[]): ParsedArgs {
	const positionals: string[] = [];
	const flags: Record<string, string | boolean | string[]> = {};
	for (let i = 0; i < argv.length; i++) {
		const token = argv[i] ?? '';
		if (token.startsWith('--')) {
			const eq = token.indexOf('=');
			let name: string;
			let value: string;
			if (eq > 0) {
				name = token.slice(2, eq);
				value = token.slice(eq + 1);
			} else {
				const next = argv[i + 1];
				if (next !== undefined && !next.startsWith('--')) {
					name = token.slice(2);
					value = next;
					i++;
				} else {
					flags[token.slice(2)] = true;
					continue;
				}
			}
			// Repeated flags collect into arrays (needed for --param k=v --param k2=v2).
			const existing = flags[name];
			if (existing === undefined) {
				flags[name] = value;
			} else if (Array.isArray(existing)) {
				existing.push(value);
			} else if (typeof existing === 'string') {
				flags[name] = [existing, value];
			} else {
				flags[name] = value;
			}
		} else {
			positionals.push(token);
		}
	}
	return { positionals, flags };
}

function flag(value: string | boolean | string[] | undefined): string | undefined {
	return typeof value === 'string' && value.length > 0 ? value : undefined;
}

function parseParams(raw: string | string[] | boolean | undefined): Record<string, string> {
	const list = raw === undefined || typeof raw === 'boolean'
		? []
		: Array.isArray(raw)
			? raw
			: [raw];
	const params: Record<string, string> = {};
	for (const pair of list) {
		const eq = pair.indexOf('=');
		if (eq <= 0) {
			throw new CommandError('command_param_format', `--param expects key=value, got "${pair}".`);
		}
		params[pair.slice(0, eq)] = pair.slice(eq + 1);
	}
	return params;
}

function resolveSite(aliasFlag: string | undefined, env: NodeJS.ProcessEnv): SiteRecordV2 {
	const sitesFile = flag(env['STONEWRIGHT_SITES_FILE']) ?? defaultSitesPath(env);
	const { registry } = loadRegistry({ sitesFile });
	let site = aliasFlag ? findSiteByAlias(registry, aliasFlag) : undefined;
	if (!site && !aliasFlag && registry.default_site_id) {
		site = registry.sites.find((s) => s.id === registry.default_site_id);
	}
	if (!site) {
		throw new CommandError('command_site_not_found', aliasFlag ? `No site with alias "${aliasFlag}".` : 'No default site. Pass --site <alias>.');
	}
	return site;
}

function loadRecipeFile(path: string): CommandRecipeV1 {
	let contents: string;
	try {
		contents = readFileSync(path, 'utf8');
	} catch (err) {
		throw new CommandError('command_recipe_unreadable', `Cannot read recipe file: ${err instanceof Error ? err.message : String(err)}`);
	}
	const parsed = parseCommandRecipe(contents);
	if (!parsed.ok) throw new CommandError('command_recipe_invalid', parsed.error);
	return parsed.recipe;
}

function planSummaryJson(plan: CommandPlanV1): string {
	return JSON.stringify(
		{
			plan_id: plan.plan_id,
			plan_sha256: plan.plan_sha256,
			site_id: plan.site_id,
			recipe_slug: plan.recipe_slug,
			risk: plan.risk,
			steps: plan.steps.map((s) => ({ id: s.id, risk: s.risk })),
			expires_at: plan.expires_at,
		},
		null,
		'\t',
	);
}

export async function runCommandCli(
	argv: string[],
	env: NodeJS.ProcessEnv = process.env,
	/** Injectable execFile runner (tests). */
	runner?: ExecFileRunner,
): Promise<number> {
	const { command, positionals, flags } = (() => {
		const [head, ...rest] = argv;
		return { ...parseArgs(rest), command: head ?? '' };
	})();

	try {
		switch (command) {
			case 'add': {
				const file = flag(flags['file']);
				if (!file) {
					writeErr('Usage: stonewright command add --site <alias> --file <recipe.json> [--replace]');
					return 1;
				}
				const recipe = loadRecipeFile(file);
				const site = resolveSite(flag(flags['site']), env);
				saveRecipe(site.id, recipe, {
					replace: flags['replace'] === true,
					env,
					local_wp_root: site.local_wp_root,
				});
				auditCommandAction('save', site, recipe.slug, 'ok');
				writeOut(`Saved command "${recipe.slug}" for site "${site.alias}".`);
				return 0;
			}
			case 'list': {
				const site = resolveSite(flag(flags['site']), env);
				const rows = listRecipes(site.id, env);
				if (flags['json'] === true) {
					writeOut(JSON.stringify(rows, null, '\t'));
				} else if (rows.length === 0) {
					writeOut('No commands stored for this site.');
				} else {
					for (const row of rows) {
						writeOut(`${row.slug}  ${row.step_count} steps  params: ${row.parameters.join(', ') || '(none)'}`);
					}
				}
				return 0;
			}
			case 'show': {
				const slug = positionals[0];
				if (!slug) {
					writeErr('Usage: stonewright command show <slug> --site <alias>');
					return 1;
				}
				const site = resolveSite(flag(flags['site']), env);
				const recipe = getRecipe(site.id, slug, env);
				writeOut(flags['json'] === true ? JSON.stringify(recipe, null, '\t') : `${recipe.title}\n\n${recipe.description}`);
				return 0;
			}
			case 'remove': {
				const slug = positionals[0];
				if (!slug) {
					writeErr('Usage: stonewright command remove <slug> --site <alias> --confirm <slug>');
					return 1;
				}
				const site = resolveSite(flag(flags['site']), env);
				removeRecipe(site.id, slug, flag(flags['confirm']) ?? '', env);
				auditCommandAction('remove', site, slug, 'ok');
				writeOut(`Removed command "${slug}".`);
				return 0;
			}
			case 'plan': {
				const slug = positionals[0];
				if (!slug) {
					writeErr('Usage: stonewright command plan <slug> --site <alias> --param key=value [--json]');
					return 1;
				}
				const site = resolveSite(flag(flags['site']), env);
				const recipe = getRecipe(site.id, slug, env);
				const params = parseParams(flags['param']);
				const outcome = planCommand({
					site_id: site.id,
					local_wp_root: site.local_wp_root,
					recipe,
					params,
					env,
				});
				auditCommandAction('plan', site, recipe.slug, 'ok');
				if (outcome.kind === 'read') {
					writeOut('Read-only recipe; no plan needed. Run it directly.');
					return 0;
				}
				writeOut(flags['json'] === true ? planSummaryJson(outcome.plan) : `plan_id: ${outcome.plan.plan_id}\napprove: ${outcome.plan.plan_sha256}\nexpires: ${outcome.plan.expires_at}`);
				return 0;
			}
			case 'run': {
				const slug = positionals[0];
				if (!slug) {
					writeErr('Usage: stonewright command run <slug> --site <alias> --param key=value [--plan <id> --approve <sha256>] [--json]');
					return 1;
				}
				const site = resolveSite(flag(flags['site']), env);
				const recipe = getRecipe(site.id, slug, env);
				const rawParams = parseParams(flags['param']);
				const receipt = await runCommand({
					site,
					recipe,
					params: rawParams,
					plan_id: flag(flags['plan']),
					plan_sha256: flag(flags['approve']),
					response_mode: 'full',
					env,
					runner,
				});
				writeOut(
					flags['json'] === true
						? JSON.stringify(receipt, null, '\t')
						: `${receipt.ok ? 'OK' : 'FAILED'} (${receipt.verification_status}) steps ${receipt.completed_steps}/${receipt.steps.length}${receipt.failed_step ? ` failed at "${receipt.failed_step}"` : ''}`,
				);
				return receipt.ok ? 0 : 1;
			}
			default:
				writeErr(`Unknown command subcommand: ${command || '(none)'}`);
				writeErr('Subcommands: add, list, show, remove, plan, run');
				return 1;
		}
	} catch (err) {
		if (err instanceof CommandError) {
			if (err.code === 'command_approval_required') {
				writeErr(err.message);
				return 2;
			}
			writeErr(err.message);
			return 1;
		}
		writeErr(err instanceof Error ? err.message : String(err));
		return 1;
	}
}
