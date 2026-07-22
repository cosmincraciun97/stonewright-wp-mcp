import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import {
	assertWriteAllowed,
	hasTaskStartSeen,
	markTaskStartSeen,
	resetTaskStartSeenForTests,
	TASK_START_TTL_MS,
} from '../src/direct/writes.js';

describe('direct task-start write gate', () => {
	beforeEach(() => {
		resetTaskStartSeenForTests();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
	});

	it('blocks writes before task-start by default', () => {
		expect(() =>
			assertWriteAllowed({ mode: 'on', destructive: false, tool: 'stonewright-content-update' }),
		).toThrow(/task-start/i);
	});

	it('allows writes after markTaskStartSeen', () => {
		markTaskStartSeen();
		expect(() =>
			assertWriteAllowed({ mode: 'on', destructive: false, tool: 'stonewright-content-update' }),
		).not.toThrow();
	});

	it('opt-out via STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off', () => {
		expect(() =>
			assertWriteAllowed({
				mode: 'on',
				destructive: false,
				tool: 't',
				env: { STONEWRIGHT_DIRECT_REQUIRE_TASK_START: 'off' },
			}),
		).not.toThrow();
	});

	it('re-requires task-start after 30 minutes', () => {
		resetTaskStartSeenForTests();
		markTaskStartSeen('site-a', 0);
		expect(() =>
			assertWriteAllowed({
				mode: 'on',
				destructive: false,
				tool: 'stonewright-content-update',
				site: 'site-a',
				now: 29 * 60_000,
			}),
		).not.toThrow();
		expect(() =>
			assertWriteAllowed({
				mode: 'on',
				destructive: false,
				tool: 'stonewright-content-update',
				site: 'site-a',
				now: 31 * 60_000,
			}),
		).toThrow(/task-start/);
		expect(TASK_START_TTL_MS).toBe(30 * 60_000);
	});

	it('task-start is per site', () => {
		resetTaskStartSeenForTests();
		markTaskStartSeen('site-a', 0);
		expect(() =>
			assertWriteAllowed({
				mode: 'on',
				destructive: false,
				tool: 'stonewright-content-update',
				site: 'site-b',
				now: 1000,
			}),
		).toThrow(/task-start/);
		expect(() =>
			assertWriteAllowed({
				mode: 'on',
				destructive: false,
				tool: 'stonewright-content-update',
				site: 'site-a',
				now: 1000,
			}),
		).not.toThrow();
	});

	it('error message mentions 30-minute re-arm', () => {
		expect(() =>
			assertWriteAllowed({ mode: 'on', destructive: false, tool: 't' }),
		).toThrow(/30 minutes/i);
	});

	it('hasTaskStartSeen reflects TTL', () => {
		markTaskStartSeen('site-a', 0);
		expect(hasTaskStartSeen('site-a', TASK_START_TTL_MS)).toBe(true);
		expect(hasTaskStartSeen('site-a', TASK_START_TTL_MS + 1)).toBe(false);
		expect(hasTaskStartSeen('site-b', 1000)).toBe(false);
	});
});
