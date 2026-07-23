import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve, basename } from 'node:path';
import { createHash } from 'node:crypto';
import { defaultStonewrightDir } from './skills-store.js';

const SCOPE_RE = /^[a-z0-9_][a-z0-9_.-]{0,63}$/;
const KINDS = new Set(['correction', 'lesson', 'preference', 'fact'] as const);
const MAX_TEXT = 4_000;

export type MemoryKind = 'correction' | 'lesson' | 'preference' | 'fact';

export type MemoryEntry = {
	id: string;
	ts: string;
	kind: MemoryKind;
	text: string;
	tags: string[];
	topic?: string;
	scope_label?: string;
	source?: string;
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

function memoryDir(baseDir: string | undefined, env?: NodeJS.ProcessEnv): string {
	return resolve(join(root(baseDir, env), 'memory'));
}

function memoryFile(baseDir: string | undefined, scope: string, env?: NodeJS.ProcessEnv): string {
	assertScope(scope);
	const dir = memoryDir(baseDir, env);
	const file = resolve(join(dir, `${scope}.jsonl`));
	if (!under(dir, file)) {
		throw new Error('Scope escapes memory dir');
	}
	return file;
}

function normalizeText(text: string): string {
	return text.trim().replace(/\s+/g, ' ');
}

function makeId(scope: string, text: string, ts: string): string {
	return createHash('sha256').update(`${scope}|${normalizeText(text)}|${ts}`).digest('hex').slice(0, 16);
}

function parseLine(line: string): MemoryEntry | null {
	try {
		const row = JSON.parse(line) as Partial<MemoryEntry>;
		if (!row || typeof row.text !== 'string' || typeof row.ts !== 'string') {
			return null;
		}
		const text = row.text;
		const id =
			typeof row.id === 'string' && row.id
				? row.id
				: createHash('sha256').update(`${row.ts}|${text}`).digest('hex').slice(0, 16);
		return {
			id,
			ts: row.ts,
			kind: KINDS.has(row.kind as MemoryKind) ? (row.kind as MemoryKind) : 'fact',
			text,
			tags: Array.isArray(row.tags) ? row.tags.map(String) : [],
			...(typeof row.topic === 'string' ? { topic: row.topic } : {}),
			...(typeof row.scope_label === 'string' ? { scope_label: row.scope_label } : {}),
			...(typeof row.source === 'string' ? { source: row.source } : {}),
		};
	} catch {
		return null;
	}
}

function readAll(file: string): MemoryEntry[] {
	if (!existsSync(file)) {
		return [];
	}
	return readFileSync(file, 'utf8')
		.split('\n')
		.filter(Boolean)
		.map(parseLine)
		.filter((row): row is MemoryEntry => row !== null);
}

/** Non-secret logical storage reference (no home path expansion of secrets). */
export function memoryStorageRef(scope: string, id: string): string {
	return `direct:memory/${scope}.jsonl#${id}`;
}

/** Directory basename only — never expand absolute home paths into agent output. */
export function memoryStorageDirLabel(baseDir?: string, env?: NodeJS.ProcessEnv): string {
	const dir = memoryDir(baseDir, env);
	return `~/.stonewright/memory (${basename(dir)})`;
}

export function recordMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	kind?: MemoryKind;
	text: string;
	tags?: string[];
	topic?: string;
	source?: string;
	/** When true, replace an existing entry with the same normalized text. */
	dedupe?: boolean;
}): MemoryEntry {
	const text = input.text.trim();
	if (!text) {
		throw new Error('Memory text is required');
	}
	if (text.length > MAX_TEXT) {
		throw new Error(`Memory text exceeds ${MAX_TEXT} bytes`);
	}
	const kind = input.kind ?? 'correction';
	if (!KINDS.has(kind)) {
		throw new Error(`Invalid memory kind: ${kind}`);
	}

	const file = memoryFile(input.baseDir, input.scope, input.env);
	const dir = memoryDir(input.baseDir, input.env);
	mkdirSync(dir, { recursive: true, mode: 0o700 });

	const existing = readAll(file);
	const norm = normalizeText(text);
	if (input.dedupe !== false) {
		const matchIdx = existing.findIndex((e) => normalizeText(e.text) === norm);
		if (matchIdx >= 0) {
			const prev = existing[matchIdx];
			const updated: MemoryEntry = {
				...prev,
				ts: new Date().toISOString(),
				kind,
				text,
				tags: (input.tags ?? prev.tags).map(String),
				...(input.topic !== undefined ? { topic: input.topic } : {}),
				...(input.source !== undefined ? { source: input.source } : {}),
			};
			// A refreshed correction is newest: move it to the physical tail.
			const next = existing.filter((_, index) => index !== matchIdx);
			next.push(updated);
			writeFileSync(file, `${next.map((e) => JSON.stringify(e)).join('\n')}\n`, {
				encoding: 'utf8',
				mode: 0o600,
			});
			return updated;
		}
	}

	const ts = new Date().toISOString();
	const entry: MemoryEntry = {
		id: makeId(input.scope, text, ts),
		ts,
		kind,
		text,
		tags: (input.tags ?? []).map(String),
		...(input.topic !== undefined ? { topic: input.topic } : {}),
		...(input.source !== undefined ? { source: input.source } : {}),
	};
	appendFileSync(file, `${JSON.stringify(entry)}\n`, { encoding: 'utf8', mode: 0o600 });
	return entry;
}

export function getMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	id: string;
}): MemoryEntry | null {
	const file = memoryFile(input.baseDir, input.scope, input.env);
	return readAll(file).find((e) => e.id === input.id) ?? null;
}

export function listMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	limit?: number;
}): { items: MemoryEntry[] } {
	const items = readAll(memoryFile(input.baseDir, input.scope, input.env));
	items.reverse();
	return { items: items.slice(0, input.limit ?? 20) };
}

export function deleteMemory(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	id: string;
}): boolean {
	const file = memoryFile(input.baseDir, input.scope, input.env);
	const existing = readAll(file);
	const next = existing.filter((e) => e.id !== input.id);
	if (next.length === existing.length) {
		return false;
	}
	writeFileSync(file, next.length ? `${next.map((e) => JSON.stringify(e)).join('\n')}\n` : '', {
		encoding: 'utf8',
		mode: 0o600,
	});
	return true;
}
