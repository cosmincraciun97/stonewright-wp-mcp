import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve, basename } from 'node:path';
import { createHash } from 'node:crypto';
import { defaultStonewrightDir } from './skills-store.js';
import { assertNoSensitiveMaterial } from './sensitive-content.js';
import { memoryBlockingRules } from './instruction-screen.js';

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
	/**
	 * `needs_review`: the text told an agent to override the plugin's rules or move credentials. The entry is kept,
	 * is never offered at task start, and stays so until a person clears it (edits its status in the memory file, or
	 * deletes it with stonewright-memory-delete).
	 */
	status: 'active' | 'stale' | 'needs_review';
	/** How sure the lesson is, 0 to 1. Files written before scores existed have none; see effectiveConfidence. */
	confidence?: number;
	/** When the lesson was last recorded or confirmed. Files written before scores existed have none. */
	last_verified?: string;
};

/** Confidence of a lesson that arrived without one, by where it came from. */
const SOURCE_CONFIDENCE: Readonly<Record<string, number>> = {
	'explicit-user-request': 1,
	'verified-repair': 0.9,
	'write-recovery': 0.6,
};
const DEFAULT_CONFIDENCE = 0.7;
/** A lesson loses half its weight for every 90 days without a confirmation, down to a quarter of it. */
const HALF_LIFE_DAYS = 90;
const FRESHNESS_FLOOR = 0.25;
/** A stale lesson stays readable but sorts far below any active one. */
const STALE_FACTOR = 0.1;
/** A lesson not confirmed for this long, or held with low confidence, is flagged for a re-check before use. */
const VERIFY_AFTER_DAYS = 180;
const LOW_CONFIDENCE = 0.4;
const DAY_MS = 24 * 60 * 60 * 1000;

export function defaultConfidenceForSource(source: string | undefined): number {
	return SOURCE_CONFIDENCE[source ?? ''] ?? DEFAULT_CONFIDENCE;
}

export function effectiveConfidence(entry: MemoryEntry): number {
	return typeof entry.confidence === 'number' ? entry.confidence : defaultConfidenceForSource(entry.source);
}

export function effectiveLastVerified(entry: MemoryEntry): string {
	return entry.last_verified ?? entry.ts;
}

function ageDays(entry: MemoryEntry, now: number): number {
	const at = Date.parse(effectiveLastVerified(entry));
	return Number.isFinite(at) ? Math.max(0, (now - at) / DAY_MS) : 0;
}

/**
 * Recall weight of a lesson: its confidence, reduced by the time since it was last confirmed, and far
 * lower when it is marked stale. Ranking by it puts a fresh, confident lesson ahead of an old or doubtful one.
 */
export function memoryScore(entry: MemoryEntry, now: number = Date.now()): number {
	const freshness = FRESHNESS_FLOOR + (1 - FRESHNESS_FLOOR) * 0.5 ** (ageDays(entry, now) / HALF_LIFE_DAYS);
	if (entry.status === 'needs_review') return 0;
	return effectiveConfidence(entry) * freshness * (entry.status === 'stale' ? STALE_FACTOR : 1);
}

/** The lessons best first; a tie goes to the newer one. */
export function rankMemory(entries: readonly MemoryEntry[], now: number = Date.now()): MemoryEntry[] {
	return entries
		.map((entry) => ({ entry, score: memoryScore(entry, now) }))
		.sort((a, b) => b.score - a.score || b.entry.ts.localeCompare(a.entry.ts))
		.map((row) => row.entry);
}

/** True when a lesson is old or doubtful enough that it should be checked before it is relied on. */
export function needsVerification(entry: MemoryEntry, now: number = Date.now()): boolean {
	return entry.status === 'stale' || effectiveConfidence(entry) < LOW_CONFIDENCE || ageDays(entry, now) > VERIFY_AFTER_DAYS;
}

function assertConfidence(value: number): void {
	if (typeof value !== 'number' || !Number.isFinite(value) || value < 0 || value > 1) {
		throw new Error('Memory confidence must be a number from 0 to 1');
	}
}

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
			status: row.status === 'stale' || row.status === 'needs_review' ? row.status : 'active',
			...(typeof row.topic === 'string' ? { topic: row.topic } : {}),
			...(typeof row.scope_label === 'string' ? { scope_label: row.scope_label } : {}),
			...(typeof row.source === 'string' ? { source: row.source } : {}),
			...(typeof row.confidence === 'number' && row.confidence >= 0 && row.confidence <= 1
				? { confidence: row.confidence }
				: {}),
			...(typeof row.last_verified === 'string' && Number.isFinite(Date.parse(row.last_verified))
				? { last_verified: row.last_verified }
				: {}),
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
	/** Internal deterministic receipt key. Keeps identical recipes isolated by incident proof. */
	stableId?: string;
	/** 0 to 1. Without one a new entry gets the default for its source; a repeat keeps the one it had. */
	confidence?: number;
}): MemoryEntry {
	const text = input.text.trim();
	if (!text) {
		throw new Error('Memory text is required');
	}
	assertNoSensitiveMaterial(
		[
			text,
			input.topic ?? '',
			input.source ?? '',
			...(input.tags ?? []),
		].join('\n'),
		'Memory entry',
	);
	if (text.length > MAX_TEXT) {
		throw new Error(`Memory text exceeds ${MAX_TEXT} bytes`);
	}
	// The same screen as the plugin's memory. A match is kept, as an entry that waits for a person.
	const reviewRules = memoryBlockingRules(text, input.topic ?? '');
	if (input.confidence !== undefined) assertConfidence(input.confidence);
	const kind = input.kind ?? 'correction';
	if (!KINDS.has(kind)) {
		throw new Error(`Invalid memory kind: ${kind}`);
	}
	if (input.stableId !== undefined && !/^[a-f0-9]{16,64}$/.test(input.stableId)) {
		throw new Error('Memory stableId must be a 16-64 character lowercase SHA-256 prefix');
	}

	const file = memoryFile(input.baseDir, input.scope, input.env);
	const dir = memoryDir(input.baseDir, input.env);
	mkdirSync(dir, { recursive: true, mode: 0o700 });

	const existing = readAll(file);
	const norm = normalizeText(text);
	if (input.dedupe !== false) {
		const matchIdx = input.stableId
			? existing.findIndex((e) => e.id === input.stableId)
			: existing.findIndex((e) => normalizeText(e.text) === norm);
		if (matchIdx >= 0) {
			const prev = existing[matchIdx];
			const now = new Date().toISOString();
			const updated: MemoryEntry = {
				...prev,
				ts: now,
				// Recording the same lesson again confirms it.
				last_verified: now,
				confidence: input.confidence ?? prev.confidence ?? defaultConfidenceForSource(input.source ?? prev.source),
				kind,
				text,
				tags: (input.tags ?? prev.tags).map(String),
				// Only a person clears an entry that waits for review; recording it again does not.
				status: reviewRules.length > 0 || prev.status === 'needs_review' ? 'needs_review' : 'active',
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
		id: input.stableId ?? makeId(input.scope, text, ts),
		ts,
		kind,
		text,
		tags: (input.tags ?? []).map(String),
		status: reviewRules.length > 0 ? 'needs_review' : 'active',
		last_verified: ts,
		confidence: input.confidence ?? defaultConfidenceForSource(input.source),
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
	activeOnly?: boolean;
}): { items: MemoryEntry[] } {
	const items = readAll(memoryFile(input.baseDir, input.scope, input.env))
		.filter((entry) => input.activeOnly !== true || entry.status === 'active');
	items.reverse();
	return { items: items.slice(0, input.limit ?? 20) };
}

/**
 * Appends entries that came from another memory file, keeping their ids, timestamps and status.
 * An id the file already holds is skipped, so importing twice adds nothing. Returns how many were added.
 */
export function importMemoryEntries(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	entries: MemoryEntry[];
}): number {
	const file = memoryFile(input.baseDir, input.scope, input.env);
	const known = new Set(readAll(file).map((entry) => entry.id));
	const fresh = input.entries.filter((entry) => {
		if (known.has(entry.id)) return false;
		known.add(entry.id);
		return true;
	});
	if (fresh.length === 0) return 0;
	mkdirSync(memoryDir(input.baseDir, input.env), { recursive: true, mode: 0o700 });
	appendFileSync(file, `${fresh.map((entry) => JSON.stringify(entry)).join('\n')}\n`, {
		encoding: 'utf8',
		mode: 0o600,
	});
	return fresh.length;
}

export function setMemoryStatus(input: {
	baseDir?: string;
	env?: NodeJS.ProcessEnv;
	scope: string;
	id: string;
	status: 'active' | 'stale';
}): boolean {
	const file = memoryFile(input.baseDir, input.scope, input.env);
	const existing = readAll(file);
	const entry = existing.find((row) => row.id === input.id);
	if (!entry) return false;
	entry.status = input.status;
	writeFileSync(file, `${existing.map((row) => JSON.stringify(row)).join('\n')}\n`, {
		encoding: 'utf8',
		mode: 0o600,
	});
	return true;
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
