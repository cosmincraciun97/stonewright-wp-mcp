import { beforeEach } from 'vitest';

/**
 * Suite-wide default for the Direct write-time plugin guard: no plugin on any
 * fixture site, and no index request. Most suites assert the exact REST calls a
 * tool makes, so the guard must not add one. Suites that exercise the guard
 * (direct-plugin-guard, direct-mode-resolution) call
 * setPluginPresenceResolverForTests(null) to use the real probe.
 *
 * The guard module is loaded inside the hook, not at the top of this file, so a
 * suite that mocks node:fs sees its mock in the modules this one pulls in.
 */
beforeEach(async () => {
	const guard = await import('../../src/direct/plugin-guard.js');
	guard.forgetPluginPresence();
	guard.setPluginPresenceResolverForTests(() => Promise.resolve('absent'));
});
