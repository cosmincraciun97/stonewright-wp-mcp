import { afterEach, beforeEach } from 'vitest';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../../src/direct/writes.js';

/**
 * Shared Direct write-gate fixture: mark task-start as seen for suites that
 * exercise write tools. Import this module from every affected test file.
 *
 * Latches both the unscoped default and the common fixture site aliases used
 * in unit tests (`local` / `remote`), because assertWriteAllowed is now
 * site-scoped and no longer accepts "any site" latch.
 */
export function installTaskStartGateFixture(): void {
	beforeEach(() => {
		markTaskStartSeen();
		markTaskStartSeen('local');
		markTaskStartSeen('remote');
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
	});
}

// Auto-install when imported for side effects.
installTaskStartGateFixture();
