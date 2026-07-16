import { describe, expect, it } from 'vitest';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { listMemory, recordMemory } from '../src/direct/memory-store.js';

function tempBase() {
	return mkdtempSync(join(tmpdir(), 'sw-mem-'));
}

describe('memory store', () => {
	it('appends and lists newest-first', () => {
		const baseDir = tempBase();
		recordMemory({ baseDir, scope: 'mysite', text: 'first', kind: 'fact' });
		recordMemory({ baseDir, scope: 'mysite', text: 'second', kind: 'correction' });
		const { items } = listMemory({ baseDir, scope: 'mysite' });
		expect(items[0]?.text).toBe('second');
		expect(items[1]?.text).toBe('first');
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
		expect(() => recordMemory({ baseDir, scope: 'ok', text: 'x'.repeat(4001) })).toThrow(/4000|text/i);
	});
});
