import { appendFileSync, existsSync, mkdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { defaultStonewrightDir } from './skills-store.js';

const SCOPE_RE = /^[a-z0-9_][a-z0-9_.-]{0,63}$/;
const KINDS = new Set(['correction', 'lesson', 'preference', 'fact'] as const);
const MAX_TEXT = 4_000;

export type MemoryKind = 'correction' | 'lesson' | 'preference' | 'fact';

export type MemoryEntry = {
	ts: string;
	kind: MemoryKind;
	text: string;
	tags: string[];
};

function root(baseDir?: string, env?: NodeJS.ProcessEnv): string {
	return baseDir ?? defaultStonewrightDir(env);
}

function assertScope(scope: string): void {
	if (!SCOPE_RE.test(scope)) {
		throw new Error(`Invalid memory scope: ${scope}`);
	}
}

function under(base: string, candidate: string): boolean {
	const b = resolve(base);
	const c = resolve(candidate);
	return c === b || c.startsWith(`${b}/`) || c.startsWith(`${b}\\`);
}

function memoryFile(baseDir: string | undefined, scope: string, env?: NodeJS.ProcessEnv): string {
	assertScope(scope);
	const dir = resolve(join(root(baseDir, env), 'memory'));
	const file = resolve(join(dir, `${scope}.jsonl`));
	if (!under(dir, file)) {
		throw new Error('Scope escapes memory dir');
	}
	return file;
}

export function recordMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	kind?: MemoryKind;
	text: string;
	tags?: string[];
}): MemoryEntry {
	const text = input.text.trim();
	if (!text) {
		throw new Error('Memory text is required');
	}
	if (text.length > MAX_TEXT) {
		throw new Error(`Memory text exceeds ${MAX_TEXT} bytes`);
	}
	const kind = (input.kind ?? 'correction');
	if (!KINDS.has(kind)) {
		throw new Error(`Invalid memory kind: ${kind}`);
	}
	const entry: MemoryEntry = {
		ts: new Date().toISOString(),
		kind,
		text,
		tags: (input.tags ?? []).map(String),
	};
	const file = memoryFile(input.baseDir, input.scope, input.env);
	mkdirSync(resolve(join(root(input.baseDir, input.env), 'memory')), { recursive: true, mode: 0o700 });
	appendFileSync(file, `${JSON.stringify(entry)}\n`, { encoding: 'utf8', mode: 0o600 });
	return entry;
}

export function listMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	limit?: number;
}): { items: MemoryEntry[] } {
	const file = memoryFile(input.baseDir, input.scope, input.env);
	if (!existsSync(file)) {
		return { items: [] };
	}
	const lines = readFileSync(file, 'utf8').split('\n').filter(Boolean);
	const items: MemoryEntry[] = [];
	for (const line of lines) {
		try {
			const row = JSON.parse(line) as MemoryEntry;
			if (row && typeof row.text === 'string' && typeof row.ts === 'string') {
				items.push({
					ts: row.ts,
					kind: (KINDS.has(row.kind) ? row.kind : 'fact'),
					text: row.text,
					tags: Array.isArray(row.tags) ? row.tags.map(String) : [],
				});
			}
		} catch {
			// skip corrupt
		}
	}
	items.reverse();
	return { items: items.slice(0, input.limit ?? 20) };
}
