/**
 * WP-CLI command policy: which argv the companion refuses, and which argv the batch runner accepts.
 *
 * Two lists, one parser.
 * - `wpCliDenyReason()` is the deny-list every runner applies (run, batch, jobs): it names the commands that
 *   run code, install or change code, change users and roles, or touch the database or configuration directly.
 * - `wpCliBatchReason()` is the allowlist of the batch runner on top of the deny-list: read-oriented commands
 *   and cache or rewrite flushes only. Writes go through the typed abilities, or through a single run on a
 *   profile that lists the runner.
 *
 * Both read the argv the way WP-CLI does, not the way a caller spells it: a leading `@alias`, global flags
 * before the command or between its words, quotes around a word, case, padding, and a whole command in one
 * token all reduce to the same command words. A global flag with a space-separated value is read both ways
 * (value skipped, value kept) and the argv is refused when either reading is refused.
 */

const VALUE_FLAGS = new Set([
	'--path',
	'--url',
	'--user',
	'--context',
	'--ssh',
	'--http',
	'--config',
	'--require',
	'--exec',
	'--skip-plugins',
	'--skip-themes',
	'--skip-packages',
]);

/** Command groups that run arbitrary code or install packages; refused for every caller. */
const CODE_RUNNING_GROUPS = new Set(['eval', 'eval-file', 'shell', 'package']);

/** Command groups refused whole for agent-facing runners. */
const BLOCKED_GROUPS = new Set([...CODE_RUNNING_GROUPS, 'config', 'scaffold']);

/** Command words refused with any arguments. */
const BLOCKED_PATHS: readonly string[][] = [
	['plugin', 'install'],
	['plugin', 'update'],
	['plugin', 'delete'],
	['plugin', 'activate'],
	['plugin', 'uninstall'],
	['plugin', 'toggle'],
	['theme', 'install'],
	['theme', 'update'],
	['theme', 'delete'],
	['theme', 'activate'],
	['theme', 'enable'],
	['core', 'download'],
	['core', 'update'],
	['core', 'install'],
	['core', 'multisite-install'],
	['core', 'multisite-convert'],
	['cron', 'event', 'run'],
	['cli', 'update'],
	['cli', 'alias', 'add'],
	['cli', 'alias', 'update'],
	['cli', 'alias', 'set'],
	['cli', 'alias', 'delete'],
	['role', 'create'],
	['role', 'delete'],
	['role', 'reset'],
	['cap', 'add'],
	['cap', 'remove'],
	['super-admin', 'add'],
	['super-admin', 'remove'],
];

/** Groups of which only the listed command words are accepted. */
const ALLOWED_ONLY: Readonly<Record<string, readonly string[][]>> = {
	db: [['check'], ['size'], ['tables'], ['columns'], ['prefix'], ['search']],
	user: [['get'], ['list'], ['list-caps'], ['meta', 'get'], ['meta', 'list']],
};

const CODE_RUNNING_FLAGS = ['--exec', '--require', '--prompt'];
const BLOCKED_FLAGS = [...CODE_RUNNING_FLAGS, '--config', '--ssh', '--http'];

/**
 * agent: runners an agent can reach (the run, batch and job tools and their HTTP routes): the full deny-list.
 * operator: commands the operator's own tooling builds (setup, rescue, approved command recipes): only the
 * entry points that run arbitrary code.
 */
export type WpCliPolicyLevel = 'agent' | 'operator';

const OPTION_WRITES = new Set(['add', 'update', 'delete', 'patch', 'set-autoload']);
const SENSITIVE_OPTIONS = new Set([
	'default_role',
	'users_can_register',
	'wp_user_roles',
	'active_plugins',
	'active_sitewide_plugins',
	'siteurl',
	'home',
	'admin_email',
	'new_admin_email',
	'template',
	'stylesheet',
	'auto_update_plugins',
	'auto_update_themes',
]);

/** Read-oriented command words of the batch runner. Arguments and flags after the words are free. */
const BATCH_ALLOWED: readonly string[][] = [
	['core', 'version'],
	['core', 'is-installed'],
	['core', 'check-update'],
	['cli', 'info'],
	['cli', 'version'],
	['post', 'get'],
	['post', 'list'],
	['post', 'exists'],
	['post', 'meta', 'get'],
	['post', 'meta', 'list'],
	['post', 'term', 'list'],
	['post-type', 'get'],
	['post-type', 'list'],
	['taxonomy', 'get'],
	['taxonomy', 'list'],
	['term', 'get'],
	['term', 'list'],
	['term', 'meta', 'get'],
	['term', 'meta', 'list'],
	['option', 'get'],
	['option', 'list'],
	['plugin', 'get'],
	['plugin', 'list'],
	['plugin', 'status'],
	['plugin', 'is-installed'],
	['plugin', 'is-active'],
	['plugin', 'path'],
	['theme', 'get'],
	['theme', 'list'],
	['theme', 'status'],
	['theme', 'is-installed'],
	['theme', 'is-active'],
	['theme', 'path'],
	['theme', 'mod', 'get'],
	['theme', 'mod', 'list'],
	['user', 'get'],
	['user', 'list'],
	['site', 'list'],
	['menu', 'list'],
	['menu', 'item', 'list'],
	['menu', 'location', 'list'],
	['media', 'image-size'],
	['comment', 'get'],
	['comment', 'list'],
	['widget', 'list'],
	['sidebar', 'list'],
	['role', 'list'],
	['role', 'exists'],
	['cap', 'list'],
	['transient', 'get'],
	['cron', 'event', 'list'],
	['cron', 'schedule', 'list'],
	['db', 'check'],
	['db', 'size'],
	['db', 'tables'],
	['db', 'columns'],
	['db', 'prefix'],
	['db', 'search'],
	['language', 'core', 'list'],
	['language', 'plugin', 'list'],
	['language', 'theme', 'list'],
	['search-replace'],
	// Flushes: they rebuild caches and rewrite rules and change no content.
	['cache', 'type'],
	['cache', 'flush'],
	['rewrite', 'list'],
	['rewrite', 'flush'],
	['elementor', 'flush-css'],
	['elementor', 'flush_css'],
];

interface ParsedArgv {
	/** Command words under each reading of the argv (value skipped, value kept). */
	paths: string[][];
	/** Flags, lower case, quotes removed. */
	flags: string[];
	/** The argv starts with an alias, a flag, or anything but a command word. */
	leadingNonCommand: boolean;
}

function stripQuotes(token: string): string {
	return token.trim().replace(/^[\s'"`]+|[\s'"`]+$/g, '');
}

function parseArgv(argv: readonly string[]): ParsedArgv {
	const cleaned: string[] = [];
	for (const raw of argv) {
		const token = stripQuotes(String(raw)).toLowerCase();
		if (token === '') continue;
		if (token.startsWith('-')) {
			cleaned.push(token);
			continue;
		}
		// A whole command in one token ("plugin install x") reads as separate words.
		for (const word of token.split(/\s+/)) {
			const part = stripQuotes(word);
			if (part !== '') cleaned.push(part);
		}
	}

	const readings: string[][] = [];
	const flags: string[] = [];
	for (const skipValues of [true, false]) {
		const words: string[] = [];
		let skipNext = false;
		for (const token of cleaned) {
			if (token.startsWith('-')) {
				if (skipValues === true) flags.push(token);
				const name = token.includes('=') ? '' : token;
				skipNext = skipValues && VALUE_FLAGS.has(name);
				continue;
			}
			if (skipNext) {
				skipNext = false;
				continue;
			}
			if (token.startsWith('@') && words.length === 0) continue;
			words.push(token);
		}
		readings.push(words);
	}

	const first = cleaned[0] ?? '';
	return {
		paths: readings.filter((words, index) => index === 0 || words.join('\u0000') !== readings[0]?.join('\u0000')),
		flags,
		leadingNonCommand: first.startsWith('-') || first.startsWith('@'),
	};
}

function startsWith(path: readonly string[], prefix: readonly string[]): boolean {
	return prefix.every((word, index) => path[index] === word);
}

function hasDryRun(flags: readonly string[]): boolean {
	return flags.some((flag) => flag === '--dry-run' || flag === '--dry-run=1' || flag === '--dry-run=true' || flag === '--dry-run=yes');
}

function denyReasonForPath(path: readonly string[], flags: readonly string[], level: WpCliPolicyLevel): string | undefined {
	const group = path[0] ?? '';
	if (level === 'operator') {
		return CODE_RUNNING_GROUPS.has(group) ? `Blocked WP-CLI command group: ${group}` : undefined;
	}
	if (BLOCKED_GROUPS.has(group)) {
		return `Blocked WP-CLI command group: ${group}`;
	}
	for (const blocked of BLOCKED_PATHS) {
		if (startsWith(path, blocked)) {
			return `Blocked WP-CLI command: ${blocked.join(' ')}`;
		}
	}
	if (group === 'language' && ['install', 'update', 'uninstall', 'activate'].includes(path[2] ?? '')) {
		return `Blocked WP-CLI command: language ${path[1] ?? ''} ${path[2] ?? ''}`.replace(/\s+/g, ' ');
	}
	const only = ALLOWED_ONLY[group];
	if (only && !only.some((allowedPath) => startsWith(path.slice(1), allowedPath))) {
		return `Blocked WP-CLI command: ${group} ${path[1] ?? ''}`.trim();
	}
	if (group === 'search-replace' && !hasDryRun(flags)) {
		return 'Blocked WP-CLI command: search-replace without --dry-run';
	}
	if (group === 'option' && OPTION_WRITES.has(path[1] ?? '')) {
		const key = path[1] === 'patch' ? path[3] : path[2];
		if (key !== undefined && isSensitiveOption(key)) {
			return `Blocked WP-CLI command: option ${path[1] ?? ''} ${key}`;
		}
	}
	return undefined;
}

function isSensitiveOption(key: string): boolean {
	return SENSITIVE_OPTIONS.has(key) || /(?:^|_)user_roles$/.test(key) || key.startsWith('stonewright_');
}

/** Why the deny-list refuses this argv, or undefined when it does not. */
export function wpCliDenyReason(argv: readonly string[], level: WpCliPolicyLevel = 'agent'): string | undefined {
	const parsed = parseArgv(argv);
	const blockedFlags = level === 'operator' ? CODE_RUNNING_FLAGS : BLOCKED_FLAGS;
	for (const flag of parsed.flags) {
		for (const blocked of blockedFlags) {
			if (flag === blocked || flag.startsWith(`${blocked}=`)) {
				return `Blocked WP-CLI flag: ${blocked}`;
			}
		}
	}
	for (const path of parsed.paths) {
		const reason = denyReasonForPath(path, parsed.flags, level);
		if (reason !== undefined) return reason;
	}
	return undefined;
}

/** Why the batch runner refuses this argv (deny-list first, then the read-oriented allowlist), or undefined. */
export function wpCliBatchReason(argv: readonly string[]): string | undefined {
	const denied = wpCliDenyReason(argv);
	if (denied !== undefined) return denied;

	const parsed = parseArgv(argv);
	if (parsed.leadingNonCommand) {
		return 'WP-CLI batch commands start with the command words; aliases and global flags before them are not allowed. Use the path, url and user inputs instead.';
	}
	for (const path of parsed.paths) {
		if (!BATCH_ALLOWED.some((allowedPath) => startsWith(path, allowedPath))) {
			return `WP-CLI batch runs read-oriented commands only; not allowed: ${path.slice(0, 3).join(' ') || '(empty)'}. Use a typed ability, or the single-command runner where a profile lists it, for writes.`;
		}
	}
	// A separate-value reading and a plain reading must agree on the same allowed command.
	if (parsed.paths.length > 1) {
		const first = parsed.paths[0] ?? [];
		return `WP-CLI batch commands cannot put a flag value before the command words (${first.slice(0, 2).join(' ')}).`;
	}
	return undefined;
}
