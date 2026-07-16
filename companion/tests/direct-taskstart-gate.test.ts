import { describe, expect, it, beforeEach, afterEach } from 'vitest';
import { assertWriteAllowed, markTaskStartSeen, resetTaskStartSeenForTests } from '../src/direct/writes.js';

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
});
