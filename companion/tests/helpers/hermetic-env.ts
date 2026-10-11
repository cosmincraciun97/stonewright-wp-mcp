import { mkdirSync, mkdtempSync, rmSync } from 'node:fs';
import { homedir, tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterAll } from 'vitest';
import { FIXTURE_RUN_ID_ENV } from './oauth-fixture-cleanup.js';
import { installTestNetworkGuard } from './hermetic-net.js';

/**
 * Suite-wide isolation. This runs before any test file imports the code under test, once per test
 * file, and:
 *
 * - points the home folder and every per-user folder (`HOME`, `USERPROFILE`, `APPDATA`,
 *   `LOCALAPPDATA`, `XDG_*`) and the Stonewright state and sites file at a fresh temp folder, so
 *   no test can read or write the developer's own `~/.stonewright` or credential store;
 * - removes every `STONEWRIGHT_*` variable the shell had (site URL, user, Application Password,
 *   mode, alias, ...) and the other site, proxy and listener names the code reads (`WP_*`, `MCP_PROXY_*`, `COMPANION_*`) so a test sees only what it sets itself;
 * - refuses any connection that is not to this machine (see hermetic-net.ts).
 *
 * `SW_TEST_SANDBOX` and `SW_TEST_REAL_HOME` exist for the guard test only.
 */

const realHome = homedir();
const sandbox = mkdtempSync(join(tmpdir(), 'sw-test-'));

const folders = {
	home: join(sandbox, 'home'),
	state: join(sandbox, 'state'),
	appData: join(sandbox, 'appdata'),
	localAppData: join(sandbox, 'localappdata'),
	xdgConfig: join(sandbox, 'xdg-config'),
	xdgData: join(sandbox, 'xdg-data'),
	xdgState: join(sandbox, 'xdg-state'),
	xdgCache: join(sandbox, 'xdg-cache'),
};
for (const folder of Object.values(folders)) mkdirSync(folder, { recursive: true });

// Other names the code reads for a site, a proxy target or the companion's own listener.
const OTHER_NAMES = /^(WP_API_URL|WP_API_USERNAME|WP_API_PASSWORD|WP_USERNAME|WP_APP_PASSWORD|MCP_PROXY_[A-Z0-9_]+|COMPANION_[A-Z0-9_]+)$/;
for (const key of Object.keys(process.env)) {
	if ((key.startsWith('STONEWRIGHT_') && key !== FIXTURE_RUN_ID_ENV) || OTHER_NAMES.test(key)) delete process.env[key];
}

process.env['HOME'] = folders.home;
process.env['USERPROFILE'] = folders.home;
process.env['APPDATA'] = folders.appData;
process.env['LOCALAPPDATA'] = folders.localAppData;
process.env['XDG_CONFIG_HOME'] = folders.xdgConfig;
process.env['XDG_DATA_HOME'] = folders.xdgData;
process.env['XDG_STATE_HOME'] = folders.xdgState;
process.env['XDG_CACHE_HOME'] = folders.xdgCache;
process.env['STONEWRIGHT_STATE_DIR'] = folders.state;
// A file that does not exist: the site comes from the variables a test sets.
process.env['STONEWRIGHT_SITES_FILE'] = join(folders.state, 'sites.json');
process.env['SW_TEST_SANDBOX'] = sandbox;
process.env['SW_TEST_REAL_HOME'] = realHome;

installTestNetworkGuard();

afterAll(() => {
	try {
		rmSync(sandbox, { recursive: true, force: true });
	} catch {
		// Best effort: the folder is in the system temp area.
	}
});
