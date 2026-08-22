/**
 * Commands v1 risk classification and argv hardening.
 *
 * v1 ships a small read-only allowlist; everything unknown is treated as a
 * write and requires a one-use approved plan plus a final verified readback.
 * After parameter substitution every argv passes through validateWpCliCommand()
 * (wp-cli.ts) AND validateRecipeWpCliCommand() here — this second gate blocks
 * target overrides and eval-style escape hatches that parameters might try to
 * smuggle in.
 */

import {
	MAX_ARGV_TOKENS,
	MAX_TOKEN_CHARS,
} from './limits.js';
import type { CommandRisk } from './types.js';

export class CommandError extends Error {
	readonly code: string;
	constructor(code: string, message: string) {
		super(message);
		this.name = 'CommandError';
		this.code = code;
	}
}

/** Read-only command paths (prefix match on non-flag argv tokens). */
const READ_ONLY_ALLOWLIST: readonly string[][] = [
	['core', 'version'],
	['cli', 'info'],
	['post', 'get'],
	['post', 'list'],
	['plugin', 'get'],
	['plugin', 'list'],
	['plugin', 'status'],
	['theme', 'get'],
	['theme', 'list'],
	['theme', 'status'],
	['user', 'get'],
	['user', 'list'],
	['term', 'get'],
	['term', 'list'],
	['site', 'list'],
	['db', 'check'],
];

const BLOCKED_COMMAND_GROUPS = new Set(['eval', 'eval-file', 'shell', 'package']);
const BLOCKED_FLAGS = new Set(['--exec', '--require', '--prompt']);
/** Target overrides may never arrive through recipe argv; runner owns them. */
const BLOCKED_TARGET_OVERRIDES = new Set(['--path', '--url', '--user', '--context', '--ssh', '--http']);

function commandPathTokens(argv: string[]): string[] {
	const tokens: string[] = [];
	for (const raw of argv) {
		if (raw.startsWith('-')) break;
		tokens.push(raw);
	}
	return tokens;
}

function matchesAllowList(path: string[]): boolean {
	return READ_ONLY_ALLOWLIST.some((prefix) => prefix.every((token, i) => token === path[i]));
}

/**
 * Classify resolved argv. Unknown commands are writes (fail closed).
 */
export function classifyWpCliRisk(argv: string[]): CommandRisk {
	return matchesAllowList(commandPathTokens(argv)) ? 'read' : 'write';
}

function assertSafeToken(token: string): void {
	if (typeof token !== 'string' || token.trim() === '') {
		throw new CommandError('command_invalid_argv', 'WP-CLI argv tokens must be non-empty strings.');
	}
	if (token.includes('\u0000')) {
		throw new CommandError('command_invalid_argv', 'WP-CLI argv tokens cannot contain NUL bytes.');
	}
	if (token.length > MAX_TOKEN_CHARS) {
		throw new CommandError('command_invalid_argv', `WP-CLI argv token exceeds ${MAX_TOKEN_CHARS} characters.`);
	}
}

/**
 * Recipe-level argv gate applied AFTER substitution. Stricter than
 * validateWpCliCommand(): also refuses target overrides and the config group.
 */
export function validateRecipeWpCliCommand(argv: string[]): string[] {
	if (!Array.isArray(argv) || argv.length === 0) {
		throw new CommandError('command_invalid_argv', 'WP-CLI command must be a non-empty argv array.');
	}
	if (argv.length > MAX_ARGV_TOKENS) {
		throw new CommandError('command_argv_limit', `WP-CLI command exceeds ${MAX_ARGV_TOKENS} tokens.`);
	}

	for (const token of argv) {
		assertSafeToken(token);
		const lowered = token.toLowerCase();
		for (const flag of BLOCKED_FLAGS) {
			if (lowered === flag || lowered.startsWith(`${flag}=`)) {
				throw new CommandError('command_blocked_flag', `Blocked WP-CLI flag: ${flag}`);
			}
		}
		for (const override of BLOCKED_TARGET_OVERRIDES) {
			if (lowered === override || lowered.startsWith(`${override}=`)) {
				throw new CommandError('command_blocked_target_override', `Blocked WP-CLI target override: ${override}`);
			}
		}
	}

	const group = commandPathTokens(argv)[0] ?? '';
	if (BLOCKED_COMMAND_GROUPS.has(group)) {
		throw new CommandError('command_blocked_group', `Blocked WP-CLI command group: ${group}`);
	}
	if (group === 'config') {
		throw new CommandError('command_config_refused', 'The config command group is refused in command recipes v1.');
	}

	return argv;
}

/**
 * Substitute whole-token {{parameter}} placeholders. Partial interpolation is
 * rejected before substitution ever runs (schema layer also rejects it).
 */
export function substituteParams(
	argvTemplate: string[],
	params: Record<string, string | number | boolean>,
	stepId: string,
): string[] {
	return argvTemplate.map((token) => {
		if (!token.includes('{{')) return token;
		if (!/^\{\{[a-z0-9_]+\}\}$/.test(token)) {
			throw new CommandError(
				'command_partial_placeholder',
				`Step "${stepId}" uses partial interpolation; placeholders must be whole tokens like {{name}}.`,
			);
		}
		const name = token.slice(2, -2);
		if (!(name in params) || params[name] === undefined) {
			throw new CommandError('command_missing_param', `Step "${stepId}" is missing a value for {{${name}}}.`);
		}
		return String(params[name]);
	});
}
