import { afterEach, beforeEach } from 'vitest';
import { markTaskStartSeen, resetTaskStartSeenForTests } from '../../src/direct/writes.js';

/**
 * Shared Direct write-gate fixture: mark task-start as seen for suites that
 * exercise write tools. Import this module from every affected test file.
 */
export function installTaskStartGateFixture(): void {
	beforeEach(() => {
		markTaskStartSeen();
	});
	afterEach(() => {
		resetTaskStartSeenForTests();
	});
}

// Auto-install when imported for side effects.
installTaskStartGateFixture();
