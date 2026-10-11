import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { defaultStateDir } from './audit.js';

export const MARK_START = '<!-- stonewright:managed -->';
export const MARK_END = '<!-- /stonewright:managed -->';
export const POINTER_MARKER = 'stonewright-pointer';

/**
 * The managed block of ~/.stonewright/AGENTS.md. One file serves every client, and a client may be in
 * Plugin mode or Direct mode, so the rules that name a tool or a safeguard of one mode sit in a section
 * labelled with that mode. The two modes do not offer the same tools: the shared rules name only
 * stonewright-task-start, which both modes have.
 */
export function agentsMdTemplate(): string {
	return [
		MARK_START,
		'# Stonewright — WordPress agent rules',
		'',
		'You are operating a WordPress site through the Stonewright MCP server. A session runs in one of two modes:',
		'Plugin mode (the Stonewright plugin is active on the site) or Direct mode (no plugin: core WordPress REST).',
		'The modes do not offer the same tools. Call only tools that your tool list shows.',
		'',
		'## Both modes',
		'',
		'1. Start every WordPress task with the `stonewright-task-start` tool and follow its response.',
		'   Its `mode` and `backend` fields say which mode and which store answered; read the section for that mode below.',
		'2. Never guess schemas, IDs, or endpoints: read before writing, research official docs',
		'   for unknowns, verify after writes, and fix the recurring errors task-start reports first.',
		'3. Never work around write gating; use the confirmation a tool asks for.',
		'4. Change ONLY the environment the user named — no parallel local/staging edits unless asked.',
		'5. Never scaffold/install ad-hoc plugins as a workaround; registration of new models needs server PHP or existing admin tools.',
		'6. Automate HTTP-first (REST → APIs → form POST); browser admin clicks are last resort.',
		'7. Content-model changes are additive — never CPT UI full import that replaces all types.',
		'8. Never store credentials, Application Passwords, API keys, tokens, private customer names,',
		'   project domains, usernames, raw logs, screenshots, or site IDs in memory, skills, or AGENTS.md.',
		'   Generalize reusable lessons and keep private runtime state outside public repositories.',
		'9. Companion updates must preserve ~/.stonewright memory, user skills, audit, and sites.json.',
		'   Never reset or seed user data during an update; packaged _builtin skills are product assets.',
		'',
		'## Direct mode only (task-start reports mode: direct)',
		'',
		'10. Skills live in `skills/` next to this file. task-start returns matched skill slugs;',
		'    load a body with `stonewright-skill-get` only when needed — do not preload everything.',
		'11. Memory lives in `memory/`. When the user corrects a repeatable mistake,',
		'    persist it with `stonewright-learning-record` and report its memory_id and scope.',
		'12. Destructive tools need a confirmation: repeat the refused call with its `confirmation_id` (`confirm: true` counts on a local site only). Read the result back after a write;',
		'    a tool keeps a pre-image only when its description says so.',
		'13. Remote Direct work uses that site\'s REST/admin-HTTP only — never local WP-CLI/MySQL for a remote task.',
		'14. If task-start says the plugin is active on the site, Direct writes are refused: switch the session to Plugin mode.',
		'',
		'## Plugin mode only (task-start reports mode: plugin)',
		'',
		'15. Pass the `context_token` that task-start returns to each write ability, and a confirmation token',
		'    when the site is in production-safe mode.',
		'16. Use the plugin\'s own skill and memory abilities that your tool list shows; their names differ from Direct\'s.',
		MARK_END,
		'',
	].join('\n');
}

export function ensureStonewrightAgentsMd(baseDir?: string, env: NodeJS.ProcessEnv = process.env): {
	path: string;
	created: boolean;
	updated: boolean;
} {
	const root = baseDir ?? defaultStateDir(env);
	mkdirSync(root, { recursive: true, mode: 0o700 });
	const path = join(root, 'AGENTS.md');
	const managed = agentsMdTemplate();
	if (!existsSync(path)) {
		writeFileSync(path, managed, { encoding: 'utf8', mode: 0o600 });
		return { path, created: true, updated: false };
	}
	const existing = readFileSync(path, 'utf8');
	const start = existing.indexOf(MARK_START);
	const end = existing.indexOf(MARK_END);
	let next: string;
	if (start === -1 || end === -1 || end < start) {
		// No managed block — append one, preserve user content.
		next = `${existing.trimEnd()}\n\n${managed}`;
	} else {
		const before = existing.slice(0, start);
		const after = existing.slice(end + MARK_END.length);
		next = `${before}${managed.trimEnd()}${after.startsWith('\n') ? after : `\n${after}`}`;
	}
	if (next === existing) {
		return { path, created: false, updated: false };
	}
	writeFileSync(path, next, { encoding: 'utf8', mode: 0o600 });
	return { path, created: false, updated: true };
}

export function pointerSnippet(): string {
	return [
		`<!-- ${POINTER_MARKER} -->`,
		'For any WordPress task: read ~/.stonewright/AGENTS.md (follow the section for the mode task-start reports) and start with the stonewright-task-start MCP tool.',
		`<!-- /${POINTER_MARKER} -->`,
	].join('\n');
}

const DEFAULT_CANDIDATES = [
	'AGENTS.md',
	'.claude/CLAUDE.md',
	'.codex/AGENTS.md',
	'.gemini/GEMINI.md',
	'.cursorrules',
];

export function agentsMdSync(
	env: NodeJS.ProcessEnv = process.env,
	input: { extra_paths?: string[] } = {},
): {
	agents_md: string;
	pointer_marker: string;
	pointer_snippet: string;
	targets: Array<{ path: string; exists: boolean; has_pointer: boolean }>;
	next: string;
} {
	const ensured = ensureStonewrightAgentsMd(undefined, env);
	const home = env.HOME?.trim() || homedir();
	const candidates = [
		...DEFAULT_CANDIDATES.map((rel) => join(home, rel)),
		...(input.extra_paths ?? []),
	];
	const targets = candidates.map((p) => {
		const exists = existsSync(p);
		let has_pointer = false;
		if (exists) {
			try {
				const body = readFileSync(p, 'utf8');
				has_pointer = body.includes(POINTER_MARKER);
			} catch {
				has_pointer = false;
			}
		}
		return { path: p, exists, has_pointer };
	});
	return {
		agents_md: ensured.path,
		pointer_marker: POINTER_MARKER,
		pointer_snippet: pointerSnippet(),
		targets,
		next: 'Offer the user to add pointer_snippet to one global config; you (the agent) edit the file with user consent — this server never writes outside ~/.stonewright.',
	};
}

export function pointerInstalled(env: NodeJS.ProcessEnv = process.env): boolean {
	const sync = agentsMdSync(env);
	return sync.targets.some((t) => t.exists && t.has_pointer);
}
