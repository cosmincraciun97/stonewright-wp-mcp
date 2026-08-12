import { describe, expect, it } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import {
	getMemory,
	listMemory,
	memoryStorageRef,
	recordMemory,
	setMemoryStatus,
} from '../src/direct/memory-store.js';

function tempBase() {
	return mkdtempSync(`${tmpdir()}/sw-mem-`);
}

describe('memory store', () => {
	it('appends and lists newest-first', () => {
		const baseDir = tempBase();
		recordMemory({ baseDir, scope: 'mysite', text: 'first', kind: 'fact' });
		recordMemory({ baseDir, scope: 'mysite', text: 'second', kind: 'correction' });
		const { items } = listMemory({ baseDir, scope: 'mysite' });
		expect(items[0]?.text).toBe('second');
		expect(items[1]?.text).toBe('first');
		expect(items[0]?.id).toBeTruthy();
	});

	it('respects limit', () => {
		const baseDir = tempBase();
		for (let i = 0; i < 5; i += 1) {
			recordMemory({ baseDir, scope: 's', text: `n${i}` });
		}
		expect(listMemory({ baseDir, scope: 's', limit: 2 }).items).toHaveLength(2);
	});

	it('rejects invalid scope and long text', () => {
		const baseDir = tempBase();
		expect(() => recordMemory({ baseDir, scope: '../x', text: 'a' })).toThrow(/scope/i);
		expect(() => recordMemory({ baseDir, scope: 'ok', text: 'x'.repeat(4001) })).toThrow(
			/4000|text/i,
		);
	});

	it('dedupes identical text and supports get by id', () => {
		const baseDir = tempBase();
		const a = recordMemory({ baseDir, scope: 's', text: 'same rule', kind: 'lesson' });
		const b = recordMemory({ baseDir, scope: 's', text: 'same rule', kind: 'lesson' });
		expect(b.id).toBe(a.id);
		expect(listMemory({ baseDir, scope: 's' }).items).toHaveLength(1);
		expect(getMemory({ baseDir, scope: 's', id: a.id })?.text).toBe('same rule');
		expect(memoryStorageRef('s', a.id)).toBe(`direct:memory/s.jsonl#${a.id}`);
	});

	it('moves a refreshed correction to the newest position before limiting', () => {
		const baseDir = tempBase();
		recordMemory({ baseDir, scope: 's', text: 'old rule' });
		recordMemory({ baseDir, scope: 's', text: 'newer rule' });
		const refreshed = recordMemory({ baseDir, scope: 's', text: 'old rule' });

		const latest = listMemory({ baseDir, scope: 's', limit: 1 }).items;
		expect(latest).toHaveLength(1);
		expect(latest[0]?.id).toBe(refreshed.id);
		expect(latest[0]?.text).toBe('old rule');
	});

	it('marks stale verified learning without deleting its history', () => {
		const baseDir = tempBase();
		const entry = recordMemory({
			baseDir,
			scope: 's',
			text: 'Use typed readback after a normalized repair.',
			source: 'verified-repair',
		});
		expect(setMemoryStatus({ baseDir, scope: 's', id: entry.id, status: 'stale' })).toBe(true);
		expect(getMemory({ baseDir, scope: 's', id: entry.id })?.status).toBe('stale');
		expect(listMemory({ baseDir, scope: 's' }).items).toHaveLength(1);
		expect(listMemory({ baseDir, scope: 's', activeOnly: true }).items).toHaveLength(0);
	});

	it('dedupes verified repairs by stable receipt id instead of shared recipe text', () => {
		const baseDir = tempBase();
		const first = recordMemory({
			baseDir, scope: 's', text: 'Use the same reusable typed repair.', stableId: 'a'.repeat(16),
		});
		const retry = recordMemory({
			baseDir, scope: 's', text: 'Use the same reusable typed repair.', stableId: 'a'.repeat(16),
		});
		const other = recordMemory({
			baseDir, scope: 's', text: 'Use the same reusable typed repair.', stableId: 'b'.repeat(16),
		});
		expect(retry.id).toBe(first.id);
		expect(other.id).not.toBe(first.id);
		expect(listMemory({ baseDir, scope: 's' }).items).toHaveLength(2);
	});
});
