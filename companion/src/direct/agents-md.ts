import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { defaultStateDir } from './audit.js';

export const MARK_START = '<!-- stonewright:managed -->';
export const MARK_END = '<!-- /stonewright:managed -->';
export const POINTER_MARKER = 'stonewright-pointer';

export function agentsMdTemplate(): string {
	return [
		MARK_START,
		'# Stonewright — WordPress agent rules',
		'',
		'You are operating a WordPress site through the Stonewright MCP server.',
		'',
		'1. Start every WordPress task with the `stonewright-task-start` tool and follow its response.',
		'2. Skills live in `skills/` next to this file. task-start returns matched skill slugs;',
		'   load a body with `stonewright-skill-get` only when needed — do not preload everything.',
		'3. Memory lives in `memory/`. When the user corrects a repeatable mistake,',
		'   persist it with `stonewright-learning-record`.',
		'4. Never guess schemas, IDs, or endpoints: read before writing, research official docs',
		'   for unknowns, verify after writes, and fix recurring_errors from task-start first.',
		'5. Destructive tools need confirm:true; never work around write gating.',
		'6. Change ONLY the environment the user named — no parallel local/staging edits unless asked.',
		'7. Remote Direct work uses that site\'s REST/admin-HTTP only — never local WP-CLI/MySQL for a remote task.',
		'8. Never scaffold/install ad-hoc plugins as a workaround; registration of new models needs server PHP or existing admin tools.',
		'9. Automate HTTP-first (REST → APIs → form POST); Playwright admin clicks are last resort.',
		'10. Content-model changes are additive — never CPT UI full import that replaces all types.',
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
		'For any WordPress task: read ~/.stonewright/AGENTS.md and start with the stonewright-task-start MCP tool.',
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
