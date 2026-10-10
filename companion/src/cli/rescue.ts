/**
 * `stonewright rescue <status|rollback>` CLI.
 *
 * Runs the plugin's `wp stonewright rescue` command on a site that has a local WordPress root, so an
 * incident can be listed and rolled back while the site itself is down. WordPress is loaded with every
 * plugin but Stonewright skipped, and with the theme skipped, so another plugin or the theme cannot stop
 * the command from starting.
 *
 * Every process starts through the shared WP-CLI runner: `execFile` with an argv array and no shell.
 * The argv is assembled only from values that passed a strict pattern here (the incident id, the user,
 * the plugin slugs), and never contains a code-evaluating, file-loading or prompt flag. The confirmation
 * token goes to the child through its environment, not its arguments.
 *
 * Exit codes: 0 = done; 1 = failed; 2 = the rollback needs a confirmation token.
 */

import { defaultSitesPath, findSiteByAlias, loadRegistry } from './connect/registry.js';
import type { SiteRecordV2 } from './connect/types.js';
import { requireValidLocalWpRoot } from '../commands/store.js';
import { appendDirectAudit } from '../direct/audit.js';
import { runWpCli, type ExecFileRunner, type WpCliResult } from '../wp-cli.js';

/** The same pattern the plugin applies to an incident id. */
const INCIDENT_ID = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,95}$/;
/** A WordPress login without spaces, or a numeric user id. */
const WP_USER = /^(?:[0-9]{1,10}|[A-Za-z0-9_][A-Za-z0-9_.@+-]{0,59})$/;
/** One path segment of a plugin directory, as WP-CLI names a plugin. */
const PLUGIN_SLUG = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}(?:\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}){0,2}$/;
const CONFIRMATION_TOKEN = /^swc_[A-Za-z0-9_-]{16,4000}\.[A-Za-z0-9_-]{16,1000}$/;
const STONEWRIGHT_MAIN_FILE = /(^|\/)stonewright\.php$/;
const TOKEN_ENV = 'STONEWRIGHT_CONFIRMATION_TOKEN';
const MAX_SKIPPED_PLUGINS = 500;

const VALUE_FLAGS = new Set(['site', 'user', 'token']);
const BOOLEAN_FLAGS = new Set(['json', 'issue-token']);

class RescueCliError extends Error {
	readonly exitCode: number;

	constructor(message: string, exitCode = 1) {
		super(message);
		this.name = 'RescueCliError';
		this.exitCode = exitCode;
	}
}

interface ParsedArgs {
	positionals: string[];
	flags: Map<string, string | true>;
}

function writeOut(text: string): void {
	process.stdout.write(text.endsWith('\n') ? text : `${text}\n`);
}

function writeErr(text: string): void {
	process.stderr.write(text.endsWith('\n') ? text : `${text}\n`);
}

function parseArgs(argv: string[]): ParsedArgs {
	const positionals: string[] = [];
	const flags = new Map<string, string | true>();
	for (let i = 0; i < argv.length; i++) {
		const token = argv[i] ?? '';
		if (!token.startsWith('-')) {
			positionals.push(token);
			continue;
		}
		if (!token.startsWith('--')) {
			throw new RescueCliError(`Unknown option: ${JSON.stringify(token)}.`);
		}
		const eq = token.indexOf('=');
		const name = token.slice(2, eq === -1 ? undefined : eq);
		if (BOOLEAN_FLAGS.has(name)) {
			if (eq !== -1) throw new RescueCliError(`--${name} takes no value.`);
			flags.set(name, true);
		} else if (VALUE_FLAGS.has(name)) {
			let value: string | undefined;
			if (eq !== -1) {
				value = token.slice(eq + 1);
			} else {
				const next = argv[i + 1];
				if (next === undefined || next.startsWith('-')) throw new RescueCliError(`--${name} needs a value.`);
				value = next;
				i++;
			}
			flags.set(name, value);
		} else {
			throw new RescueCliError(`Unknown option: ${JSON.stringify(token.slice(0, 40))}.`);
		}
	}
	return { positionals, flags };
}

function flagValue(flags: Map<string, string | true>, name: string): string | undefined {
	const value = flags.get(name);
	return typeof value === 'string' ? value : undefined;
}

function resolveSite(alias: string | undefined, env: NodeJS.ProcessEnv): SiteRecordV2 {
	const sitesFile = (env['STONEWRIGHT_SITES_FILE'] ?? '').trim() || defaultSitesPath(env);
	const { registry } = loadRegistry({ sitesFile });
	let site = alias ? findSiteByAlias(registry, alias) : undefined;
	if (!site && !alias && registry.default_site_id) {
		site = registry.sites.find((candidate) => candidate.id === registry.default_site_id);
	}
	if (!site) {
		throw new RescueCliError(alias ? `No site with alias "${alias}".` : 'No default site. Pass --site <alias>.');
	}
	return site;
}

/** The name WP-CLI uses for a plugin in --skip-plugins: its folder, or the file name for a single-file plugin. */
function pluginSlug(basename: string): string {
	const slash = basename.lastIndexOf('/');
	return slash >= 0 ? basename.slice(0, slash) : basename.replace(/\.php$/, '');
}

async function readActivePlugins(wpRoot: string, runner: ExecFileRunner | undefined, env: NodeJS.ProcessEnv): Promise<string[]> {
	const active = (await runWpCli(
		{
			command: ['option', 'get', 'active_plugins', '--format=json', '--skip-plugins', '--skip-themes'],
			path: wpRoot,
			parseJson: true,
			responseMode: 'full',
			timeoutMs: 30_000,
		},
		runner,
		env,
	)) as WpCliResult;
	const listed = active.parsed_json;
	if (!active.ok || !Array.isArray(listed) || !listed.every((entry) => typeof entry === 'string')) {
		throw new RescueCliError(
			'Could not read the active plugins with WP-CLI. Check that WordPress starts with all plugins skipped: wp option get active_plugins --skip-plugins --skip-themes',
		);
	}
	const plugins: string[] = [...listed];

	// Plugins active for a whole multisite network; the command fails on a single site, which is fine.
	const network = (await runWpCli(
		{
			command: ['network', 'meta', 'get', '1', 'active_sitewide_plugins', '--format=json', '--skip-plugins', '--skip-themes'],
			path: wpRoot,
			parseJson: true,
			responseMode: 'full',
			timeoutMs: 30_000,
		},
		runner,
		env,
	)) as WpCliResult;
	const sitewide = network.parsed_json;
	if (network.ok && sitewide !== null && typeof sitewide === 'object' && !Array.isArray(sitewide)) {
		plugins.push(...Object.keys(sitewide as Record<string, unknown>));
	}
	return plugins;
}

/** The --skip-plugins value: every active plugin but Stonewright, or null when nothing is left to skip. */
function skipPluginsValue(plugins: string[]): string | null {
	const stonewright = plugins.find((plugin) => STONEWRIGHT_MAIN_FILE.test(plugin));
	if (!stonewright) {
		throw new RescueCliError('Stonewright is not an active plugin on this site, so there is nothing to roll back through.');
	}
	const keep = pluginSlug(stonewright);
	const slugs: string[] = [];
	for (const plugin of plugins) {
		const slug = pluginSlug(plugin);
		if (slug === keep || slugs.includes(slug)) continue;
		if (!PLUGIN_SLUG.test(slug)) {
			throw new RescueCliError(
				`The active plugin ${JSON.stringify(plugin.slice(0, 80))} has a name that cannot be passed to --skip-plugins safely. Deactivate it first, or run the command by hand.`,
			);
		}
		slugs.push(slug);
	}
	if (slugs.length > MAX_SKIPPED_PLUGINS) {
		throw new RescueCliError(`More than ${MAX_SKIPPED_PLUGINS} plugins are active; run the command by hand.`);
	}
	return slugs.length === 0 ? null : slugs.join(',');
}

interface RescueRun {
	subcommand: 'status' | 'rollback';
	incident: string | undefined;
	user: string;
	json: boolean;
	issueToken: boolean;
	token: string | undefined;
}

function rescueArgv(run: RescueRun, skipPlugins: string | null): string[] {
	const argv = ['stonewright', 'rescue', run.subcommand];
	if (run.incident !== undefined) argv.push(run.incident);
	if (skipPlugins !== null) argv.push(`--skip-plugins=${skipPlugins}`);
	argv.push('--skip-themes');
	if (run.json) argv.push('--format=json');
	if (run.issueToken) argv.push('--issue-token');
	return argv;
}

function validateRun(subcommand: 'status' | 'rollback', parsed: ParsedArgs, site: SiteRecordV2): RescueRun {
	const { positionals, flags } = parsed;
	const user = flagValue(flags, 'user') ?? site.username_hint;
	if (!WP_USER.test(user)) {
		throw new RescueCliError('Pass --user with a WordPress login without spaces, or a user id.');
	}
	let incident: string | undefined;
	if (subcommand === 'rollback') {
		incident = positionals[0];
		if (positionals.length !== 1 || incident === undefined || !INCIDENT_ID.test(incident)) {
			throw new RescueCliError('Usage: stonewright rescue rollback <incident> [--token <token> | --issue-token] [--user <login>] [--site <alias>] [--json]');
		}
	} else if (positionals.length > 0) {
		throw new RescueCliError('Usage: stonewright rescue status [--user <login>] [--site <alias>] [--json]');
	}
	const token = flagValue(flags, 'token');
	if (token !== undefined && !CONFIRMATION_TOKEN.test(token)) {
		throw new RescueCliError('The token is not a confirmation token. Get one with: stonewright rescue rollback <incident> --issue-token');
	}
	const issueToken = flags.get('issue-token') === true;
	if (subcommand === 'status' && (token !== undefined || issueToken)) {
		throw new RescueCliError('--token and --issue-token belong to the rollback subcommand.');
	}
	if (issueToken && token !== undefined) {
		throw new RescueCliError('Use either --token or --issue-token, not both.');
	}
	return { subcommand, incident, user, json: flags.get('json') === true, issueToken, token };
}

export async function runRescueCli(
	argv: string[],
	env: NodeJS.ProcessEnv = process.env,
	/** Injectable execFile runner (tests). */
	runner?: ExecFileRunner,
): Promise<number> {
	const started = Date.now();
	let audit: { site: SiteRecordV2; subcommand: string; incident: string | undefined } | undefined;
	let exitCode = 1;
	try {
		const [head, ...rest] = argv;
		if (head !== 'status' && head !== 'rollback') {
			writeErr('Usage: stonewright rescue <status|rollback> ...');
			return 1;
		}
		const parsed = parseArgs(rest);
		const site = resolveSite(flagValue(parsed.flags, 'site'), env);
		const run = validateRun(head, parsed, site);
		const wpRoot = (() => {
			try {
				return requireValidLocalWpRoot(site.local_wp_root);
			} catch (error) {
				throw new RescueCliError(
					site.local_wp_root
						? (error instanceof Error ? error.message : String(error))
						: 'This site has no local WordPress root configured. Run `stonewright connect repair <alias> --wp-root <path>`.',
				);
			}
		})();
		audit = { site, subcommand: head, incident: run.incident };

		const skipPlugins = skipPluginsValue(await readActivePlugins(wpRoot, runner, env));
		const childEnv: NodeJS.ProcessEnv = run.token === undefined ? env : { ...env, [TOKEN_ENV]: run.token };
		const result = (await runWpCli(
			{
				command: rescueArgv(run, skipPlugins),
				path: wpRoot,
				user: run.user,
				responseMode: 'full',
				timeoutMs: 120_000,
			},
			runner,
			childEnv,
		)) as WpCliResult;

		if (!result.available) {
			throw new RescueCliError('WP-CLI was not found. Set STONEWRIGHT_WP_CLI_BIN, or install WP-CLI.');
		}
		if (result.stdout.trim() !== '') writeOut(result.stdout);
		if (result.stderr.trim() !== '') writeErr(result.stderr);
		if (result.exit_code === 2) {
			writeErr(`The rollback needs a confirmation token. Run: stonewright rescue rollback ${run.incident ?? '<incident>'} --issue-token, then run it again with --token <token>.`);
			exitCode = 2;
		} else {
			exitCode = result.exit_code === 0 ? 0 : 1;
		}
		return exitCode;
	} catch (error) {
		writeErr(error instanceof Error ? error.message : String(error));
		exitCode = error instanceof RescueCliError ? error.exitCode : 1;
		return exitCode;
	} finally {
		if (audit) {
			try {
				appendDirectAudit({
					tool: `stonewright-rescue-${audit.subcommand}`,
					site: audit.site.canonical_url,
					resource: audit.incident ?? '',
					status: exitCode === 0 ? 'ok' : 'error',
					eventType: 'rescue_command',
					operationClass: 'local_rescue_command',
					executionStatus: 'executed',
					durationMs: Date.now() - started,
				});
			} catch {
				// The audit trail never changes the outcome of the command.
			}
		}
	}
}
