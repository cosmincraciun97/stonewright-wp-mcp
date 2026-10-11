import { describe, expect, it } from 'vitest';
import { appendFileSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
	effectiveConfidence,
	effectiveLastVerified,
	getMemory,
	listMemory,
	memoryScore,
	rankMemory,
	recordMemory,
	setMemoryStatus,
	type MemoryEntry,
} from '../src/direct/memory-store.js';
import {
	learningRecord,
	memoryList,
	taskStart,
	type SelfImproveContext,
} from '../src/direct/tools/self-improve.js';

const DAY = 24 * 60 * 60 * 1000;
const NOW = Date.parse('2026-10-11T12:00:00Z');
const daysAgo = (n: number) => new Date(NOW - n * DAY).toISOString();

function entry(overrides: Partial<MemoryEntry> = {}): MemoryEntry {
	return { id: 'a'.repeat(16), ts: daysAgo(1), kind: 'lesson', text: 'x', tags: [], status: 'active', ...overrides };
}

function tempBase() {
	return mkdtempSync(join(tmpdir(), 'sw-mem-score-'));
}

function ctx(baseDir = tempBase()): SelfImproveContext {
	return {
		env: { STONEWRIGHT_SITES_FILE: '/tmp/does-not-exist-sw-sites-scoring.json' } as NodeJS.ProcessEnv,
		baseDir,
		directToolCount: 0,
	};
}

describe('memory scoring', () => {
	it('reads a file written before scores existed and gives its entries a default', () => {
		const baseDir = tempBase();
		mkdirSync(join(baseDir, 'memory'), { recursive: true });
		const file = join(baseDir, 'memory', 'old.jsonl');
		writeFileSync(file, `${JSON.stringify({ id: '1111111111111111', ts: daysAgo(10), kind: 'correction', text: 'legacy lesson', tags: [], status: 'active', source: 'explicit-user-request' })}\n`);
		const [legacy] = listMemory({ baseDir, scope: 'old' }).items;
		expect(legacy?.text).toBe('legacy lesson');
		expect(legacy).not.toHaveProperty('confidence');
		expect(effectiveConfidence(legacy)).toBeGreaterThan(0);
		expect(effectiveConfidence(legacy)).toBeLessThanOrEqual(1);
		// A file that holds no new fields is not rewritten by a read.
		expect(readFileSync(file, 'utf8')).not.toContain('last_verified');
	});

	it('stores a confidence and the time of the last verification with a new entry', () => {
		const baseDir = tempBase();
		const saved = recordMemory({ baseDir, scope: 's', text: 'use the media tool', confidence: 0.4 });
		expect(saved.confidence).toBe(0.4);
		expect(Date.parse(saved.last_verified ?? '')).not.toBeNaN();
		const stored = getMemory({ baseDir, scope: 's', id: saved.id });
		expect(stored).toMatchObject({ confidence: 0.4, last_verified: saved.last_verified });
	});

	it('refuses a confidence outside 0 to 1', () => {
		const baseDir = tempBase();
		expect(() => recordMemory({ baseDir, scope: 's', text: 'a', confidence: 1.5 })).toThrow(/confidence/i);
		expect(() => recordMemory({ baseDir, scope: 's', text: 'b', confidence: -0.1 })).toThrow(/confidence/i);
	});

	it('records the same lesson again as a fresh verification', () => {
		const baseDir = tempBase();
		const first = recordMemory({ baseDir, scope: 's', text: 'same lesson', confidence: 0.5 });
		// Age the stored verification, then record the lesson again.
		const file = join(baseDir, 'memory', 's.jsonl');
		const aged = { ...first, last_verified: daysAgo(200), ts: daysAgo(200) };
		writeFileSync(file, `${JSON.stringify(aged)}\n`);
		const again = recordMemory({ baseDir, scope: 's', text: 'same lesson' });
		expect(again.id).toBe(first.id);
		expect(Date.parse(again.last_verified ?? '')).toBeGreaterThan(Date.parse(daysAgo(1)));
		// A repeat without a new confidence keeps the confidence it had.
		expect(again.confidence).toBe(0.5);
	});

	it('ranks a stale or low-confidence entry below a fresh, confident one', () => {
		const fresh = entry({ id: 'f'.repeat(16), text: 'fresh', confidence: 0.9, last_verified: daysAgo(2) });
		const old = entry({ id: 'o'.repeat(16), text: 'old', confidence: 0.9, last_verified: daysAgo(400), ts: daysAgo(400) });
		const unsure = entry({ id: 'u'.repeat(16), text: 'unsure', confidence: 0.2, last_verified: daysAgo(2) });
		const stale = entry({ id: 's'.repeat(16), text: 'stale', confidence: 0.9, last_verified: daysAgo(2), status: 'stale' });
		const ranked = rankMemory([stale, unsure, old, fresh], NOW).map((row) => row.text);
		expect(ranked[0]).toBe('fresh');
		expect(ranked.at(-1)).toBe('stale');
		expect(ranked.indexOf('old')).toBeLessThan(ranked.indexOf('stale'));
		expect(ranked.indexOf('unsure')).toBeLessThan(ranked.indexOf('stale'));
		expect(memoryScore(fresh, NOW)).toBeGreaterThan(memoryScore(old, NOW));
		expect(memoryScore(fresh, NOW)).toBeGreaterThan(memoryScore(unsure, NOW));
		expect(memoryScore(old, NOW)).toBeGreaterThan(memoryScore(stale, NOW));
	});

	it('breaks a tie by the newer entry', () => {
		const a = entry({ id: 'a'.repeat(16), text: 'a', confidence: 0.8, last_verified: daysAgo(5), ts: daysAgo(5) });
		const b = entry({ id: 'b'.repeat(16), text: 'b', confidence: 0.8, last_verified: daysAgo(5), ts: daysAgo(3) });
		expect(rankMemory([a, b], NOW).map((row) => row.text)).toEqual(['b', 'a']);
	});

	it('falls back to the entry time when there is no verification time', () => {
		expect(effectiveLastVerified(entry({ ts: daysAgo(30) }))).toBe(daysAgo(30));
		expect(effectiveLastVerified(entry({ ts: daysAgo(30), last_verified: daysAgo(3) }))).toBe(daysAgo(3));
	});

	it('ignores a malformed confidence or time in a file', () => {
		const baseDir = tempBase();
		mkdirSync(join(baseDir, 'memory'), { recursive: true });
		appendFileSync(
			join(baseDir, 'memory', 'bad.jsonl'),
			`${JSON.stringify({ id: '2222222222222222', ts: daysAgo(1), text: 'odd', confidence: 'high', last_verified: 7, status: 'active' })}\n`,
		);
		const [row] = listMemory({ baseDir, scope: 'bad' }).items;
		expect(row).toBeDefined();
		expect(row).not.toHaveProperty('confidence');
		expect(row).not.toHaveProperty('last_verified');
	});
});

describe('scored recall', () => {
	it('task-start highlights put a fresh confident lesson before a stale and an unsure one', () => {
		const c = ctx();
		const base = c.baseDir as string;
		const stale = recordMemory({ baseDir: base, scope: '_global', text: 'stale lesson', confidence: 1 });
		recordMemory({ baseDir: base, scope: '_global', text: 'unsure lesson', confidence: 0.1 });
		recordMemory({ baseDir: base, scope: '_global', text: 'confident lesson', confidence: 0.95 });
		setMemoryStatus({ baseDir: base, scope: '_global', id: stale.id, status: 'stale' });
		const start = taskStart(c, { task: 'edit a page' });
		const texts = start.memory_highlights.map((row) => row.text);
		expect(texts[0]).toBe('confident lesson');
		expect(texts).not.toContain('stale lesson');
		expect(texts).toContain('unsure lesson');
	});

	it('shows the sixth lesson when it outranks five newer weak ones', () => {
		const c = ctx();
		const base = c.baseDir as string;
		recordMemory({ baseDir: base, scope: '_global', text: 'the one that matters', confidence: 1 });
		for (let i = 0; i < 6; i += 1) {
			recordMemory({ baseDir: base, scope: '_global', text: `weak ${i}`, confidence: 0.05 });
		}
		const texts = taskStart(c, { task: 'edit a page' }).memory_highlights.map((row) => row.text);
		expect(texts).toContain('the one that matters');
		expect(texts).toHaveLength(5);
	});

	it('highlights carry the id, confidence and last verification, and flag an entry to re-check', () => {
		const c = ctx();
		const base = c.baseDir as string;
		const file = join(base, 'memory');
		mkdirSync(file, { recursive: true });
		writeFileSync(
			join(file, '_global.jsonl'),
			`${JSON.stringify({ id: '3333333333333333', ts: daysAgo(400), kind: 'lesson', text: 'ancient lesson', tags: [], status: 'active' })}\n`,
		);
		const [row] = taskStart(c, { task: 'edit a page' }).memory_highlights;
		expect(row).toMatchObject({ id: '3333333333333333', text: 'ancient lesson' });
		expect(typeof row?.confidence).toBe('number');
		expect(row?.last_verified).toBe(daysAgo(400));
		expect(row?.verify_before_use).toBe(true);
	});

	it('memory-list keeps newest first by default and ranks by score on request, with the scores shown', () => {
		const c = ctx();
		const base = c.baseDir as string;
		recordMemory({ baseDir: base, scope: '_global', text: 'confident', confidence: 1 });
		recordMemory({ baseDir: base, scope: '_global', text: 'unsure and newer', confidence: 0.1 });
		const recent = memoryList(c);
		expect(recent.items.map((row) => row.text)).toEqual(['unsure and newer', 'confident']);
		expect(recent.items[0]).toMatchObject({ confidence: 0.1 });
		expect(typeof recent.items[0]?.score).toBe('number');
		const ranked = memoryList(c, { order: 'score' });
		expect(ranked.items.map((row) => row.text)).toEqual(['confident', 'unsure and newer']);
	});

	it('learning-record takes a confidence, defaults it by source and refreshes it on a repeat', () => {
		const c = ctx();
		const sure = learningRecord(c, { topic: 't1', correction: 'always read before writing', source: 'explicit-user-request' });
		const guess = learningRecord(c, { topic: 't2', correction: 'maybe prefer the group block', source: 'write-recovery' });
		const given = learningRecord(c, { topic: 't3', correction: 'columns need a wrapper', confidence: 0.33 });
		expect(sure.memory.confidence).toBeGreaterThan(guess.memory.confidence ?? 1);
		expect(given.memory.confidence).toBe(0.33);
		expect(sure.verified).toBe(true);
	});

	it('does not claim a source the caller did not give', () => {
		const c = ctx();
		const out = learningRecord(c, { topic: 'x', correction: 'a lesson with no stated source' });
		expect(out.memory.source).not.toBe('explicit-user-request');
		expect(out.memory.source).toBe('agent-recorded');
	});
});
