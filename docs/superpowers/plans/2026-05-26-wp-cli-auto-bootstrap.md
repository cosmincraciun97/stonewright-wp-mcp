# WP-CLI Auto-Bootstrap Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** WP-CLI is automatically available at every fresh installation of the Stonewright MCP companion — no manual configuration required.

**Architecture:** The companion's `main()` startup sequence gains a non-blocking WP-CLI readiness probe. If `wp` is not on PATH and no phar is found in the discovery chain, it silently downloads `wp-cli.phar` into the Stonewright cache (`%LOCALAPPDATA%\Stonewright\wp-cli\` on Windows, `~/.stonewright/wp-cli/` elsewhere). A lightweight `wpCliEnsureReady()` helper encapsulates this logic. The existing `wpCliInstall()` and `resolveWpCliInvocation()` functions are reused — no new dependencies.

**Tech Stack:** TypeScript (Node 20+), existing `wp-cli.ts` module, Vitest for unit tests, `npm run test` for CI gate.

---

## Context

- `companion/src/wp-cli.ts` already has `wpCliInstall()` (downloads phar), `resolveWpCliInvocation()` (finds wp/php/phar), `wpCliStatus()`.
- `companion/src/mcp-server.ts` registers `stonewright-wp-cli-install` as an MCP tool but the tool is call-triggered, not startup-triggered.
- `companion/src/index.ts` `main()` boots stdio + optional HTTP but does NOT probe WP-CLI readiness.
- LocalWP ships WP-CLI at `D:\Work\LocalWP\resources\extraResources\bin\wp-cli\wp-cli.phar` — auto-discovery already handles this when `cwd` is near the WP root, but the companion cache is NOT checked at all unless `STONEWRIGHT_WP_CLI_INSTALL_DIR` is set.
- The fix: at startup, call `wpCliStatus()`. If `available: false`, call `wpCliInstall()` silently. Log the result.

---

## Task 1: Add `wpCliEnsureReady()` helper to `wp-cli.ts`

**Files:**
- Modify: `companion/src/wp-cli.ts`
- Test: `companion/tests/wp-cli.test.ts`

### Step 1.1 — Write failing tests

Add to `companion/tests/wp-cli.test.ts`:

```typescript
import { wpCliEnsureReady } from '../src/wp-cli.js';

describe('wpCliEnsureReady', () => {
  it('returns {ensured: true, source: "already_available"} when wp is found on PATH', async () => {
    const runner: ExecFileRunner = (_file, args) => {
      // Simulate `wp cli info --format=json` succeeding
      return Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
    };
    const env = { STONEWRIGHT_WP_CLI_BIN: 'wp', STONEWRIGHT_WP_ROOT: process.cwd() } as NodeJS.ProcessEnv;
    const result = await wpCliEnsureReady({ runner, env });
    expect(result.ensured).toBe(true);
    expect(result.source).toBe('already_available');
    expect(result.installed).toBe(false);
  });

  it('downloads phar and returns {ensured: true, source: "installed"} when wp is unavailable', async () => {
    const temp = mkdtempSync(join(tmpdir(), 'stonewright-ensure-'));
    try {
      const installDir = join(temp, 'cache');
      // First call (status) fails with ENOENT, second call (after install) succeeds
      let callCount = 0;
      const runner: ExecFileRunner = (_file, args) => {
        callCount++;
        if (callCount === 1) {
          const err = Object.assign(new Error('wp: not found'), { code: 'ENOENT' });
          return Promise.resolve({ stdout: '', stderr: '', exitCode: 1, errorCode: 'ENOENT', errorMessage: err.message });
        }
        return Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
      };
      const pharBytes = Buffer.from('fake phar');
      const fetchImpl = () => Promise.resolve(new Response(pharBytes));
      const env = {
        STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
        STONEWRIGHT_WP_ROOT: process.cwd(),
      } as NodeJS.ProcessEnv;

      const result = await wpCliEnsureReady({ runner, env, fetchImpl });
      expect(result.ensured).toBe(true);
      expect(result.source).toBe('installed');
      expect(result.installed).toBe(true);
      expect(existsSync(join(installDir, 'wp-cli.phar'))).toBe(true);
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });

  it('returns {ensured: false} when wp is unavailable and phar download fails', async () => {
    const temp = mkdtempSync(join(tmpdir(), 'stonewright-ensure-fail-'));
    try {
      const installDir = join(temp, 'cache');
      const runner: ExecFileRunner = () =>
        Promise.resolve({ stdout: '', stderr: '', exitCode: 1, errorCode: 'ENOENT', errorMessage: 'not found' });
      const fetchImpl = () => Promise.resolve(new Response('fail', { status: 500 }));
      const env = {
        STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
        STONEWRIGHT_WP_ROOT: process.cwd(),
      } as NodeJS.ProcessEnv;

      const result = await wpCliEnsureReady({ runner, env, fetchImpl });
      expect(result.ensured).toBe(false);
      expect(result.installed).toBe(false);
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });
});
```

- [ ] **Step 1.2: Run tests to verify they fail**

```bash
cd companion
npm test -- --reporter=verbose 2>&1 | grep -A3 "wpCliEnsureReady"
```

Expected: FAIL — `wpCliEnsureReady` is not exported.

- [ ] **Step 1.3: Implement `wpCliEnsureReady` in `companion/src/wp-cli.ts`**

Add after the `wpCliDiscover` function (around line 311):

```typescript
export interface WpCliEnsureReadyInput {
  runner?: ExecFileRunner;
  env?: NodeJS.ProcessEnv;
  fetchImpl?: typeof fetch;
  timeoutMs?: number;
}

export interface WpCliEnsureReadyResult {
  ensured: boolean;
  source: 'already_available' | 'installed' | 'install_failed' | 'status_error';
  installed: boolean;
  installPath?: string;
  error?: string;
}

/**
 * Ensures WP-CLI is available for use by the companion.
 *
 * 1. Runs `wp cli info` to check current availability.
 * 2. If unavailable (ENOENT), downloads wp-cli.phar into the Stonewright
 *    companion cache and re-checks.
 * 3. Returns a structured result indicating whether WP-CLI is ready.
 *
 * This is called once at companion startup and is safe to call repeatedly
 * (wpCliInstall is idempotent when the phar already exists).
 */
export async function wpCliEnsureReady(
  input: WpCliEnsureReadyInput = {},
): Promise<WpCliEnsureReadyResult> {
  const env = input.env ?? process.env;
  const runner = input.runner;
  const fetchImpl = input.fetchImpl ?? fetch;

  // Step 1: Check if WP-CLI is already reachable.
  const status = await wpCliStatus({}, runner, env);
  if (status.available) {
    return { ensured: true, source: 'already_available', installed: false };
  }

  // Step 2: WP-CLI not on PATH / not found — try installing phar into cache.
  const installResult = await wpCliInstall(
    { timeoutMs: input.timeoutMs },
    fetchImpl,
    env,
  );

  if (!installResult.ok) {
    return {
      ensured: false,
      source: 'install_failed',
      installed: false,
      error: installResult.error,
    };
  }

  // Step 3: Re-check — now the phar is in the install dir so discovery picks it up.
  const recheck = await wpCliStatus({}, runner, env);
  return {
    ensured: recheck.available,
    source: 'installed',
    installed: true,
    installPath: installResult.path,
    ...(recheck.available ? {} : { error: recheck.error }),
  };
}
```

- [ ] **Step 1.4: Run tests to verify they pass**

```bash
cd companion
npm test -- --reporter=verbose 2>&1 | grep -A5 "wpCliEnsureReady"
```

Expected: PASS (3 new tests green).

- [ ] **Step 1.5: Commit**

```bash
git add companion/src/wp-cli.ts companion/tests/wp-cli.test.ts
git commit -m "feat(companion): add wpCliEnsureReady auto-bootstrap helper"
```

---

## Task 2: Call `wpCliEnsureReady()` at companion startup in `index.ts`

**Files:**
- Modify: `companion/src/index.ts`
- Test: `companion/tests/http-smoke.test.ts` (add a startup probe assertion)

- [ ] **Step 2.1: Write failing test**

In `companion/tests/http-smoke.test.ts`, add after the existing imports:

```typescript
import { vi } from 'vitest';
import * as wpCliModule from '../src/wp-cli.js';
```

Add a new `describe` block:

```typescript
describe('companion startup WP-CLI probe', () => {
  it('calls wpCliEnsureReady once during startHttp', async () => {
    const spy = vi.spyOn(wpCliModule, 'wpCliEnsureReady').mockResolvedValue({
      ensured: true,
      source: 'already_available',
      installed: false,
    });

    const server = await startHttp(0);
    try {
      expect(spy).toHaveBeenCalledTimes(1);
    } finally {
      await server.close();
      spy.mockRestore();
    }
  });
});
```

- [ ] **Step 2.2: Run test to verify it fails**

```bash
cd companion
npm test -- tests/http-smoke.test.ts --reporter=verbose 2>&1 | tail -20
```

Expected: FAIL — spy not called.

- [ ] **Step 2.3: Implement startup probe in `companion/src/index.ts`**

Add import at top (after the existing wp-cli imports on line 31):

```typescript
import { runWpCli, wpCliDiscover, wpCliStatus, wpCliEnsureReady, type WpCliRunInput } from './wp-cli.js';
```

In `startHttp()`, after `const server = await createMcpServer();` (around line 65), add:

```typescript
  // Probe WP-CLI availability; auto-install phar into cache if missing.
  wpCliEnsureReady({ env: process.env }).then((result) => {
    if (result.ensured) {
      log.info('WP-CLI ready', { source: result.source, installed: result.installed, path: result.installPath });
    } else {
      log.warn('WP-CLI not available after bootstrap attempt', { source: result.source, error: result.error });
    }
  }).catch((err: unknown) => {
    log.warn('WP-CLI bootstrap probe failed', { error: err instanceof Error ? err.message : String(err) });
  });
```

Also add the same probe to `startStdio()` (around line 39, after `await server.connect(transport);`):

```typescript
  // Non-blocking WP-CLI readiness probe — logs result, never blocks stdio startup.
  wpCliEnsureReady({ env: process.env }).then((result) => {
    if (result.ensured) {
      log.info('WP-CLI ready', { source: result.source, installed: result.installed, path: result.installPath });
    } else {
      log.warn('WP-CLI not available after bootstrap attempt', { source: result.source, error: result.error });
    }
  }).catch((err: unknown) => {
    log.warn('WP-CLI bootstrap probe failed', { error: err instanceof Error ? err.message : String(err) });
  });
```

- [ ] **Step 2.4: Run tests to verify they pass**

```bash
cd companion
npm test -- tests/http-smoke.test.ts --reporter=verbose 2>&1 | tail -20
```

Expected: PASS.

- [ ] **Step 2.5: Run full test suite**

```bash
cd companion
npm test 2>&1 | tail -30
```

Expected: all tests pass.

- [ ] **Step 2.6: Commit**

```bash
git add companion/src/index.ts companion/tests/http-smoke.test.ts
git commit -m "feat(companion): probe WP-CLI at startup and auto-bootstrap phar"
```

---

## Task 3: Add `postinstall` npm script for first-install bootstrap

This ensures `npm install` in a fresh companion checkout also downloads the phar.

**Files:**
- Create: `companion/scripts/postinstall.mjs`
- Modify: `companion/package.json`

- [ ] **Step 3.1: Create `companion/scripts/postinstall.mjs`**

```javascript
#!/usr/bin/env node
/**
 * Stonewright companion postinstall — downloads wp-cli.phar into cache.
 * Runs automatically after `npm install`. Safe to run repeatedly (idempotent).
 * Never throws — failures are logged as warnings so `npm install` always exits 0.
 */
import { homedir } from 'node:os';
import { join } from 'node:path';
import { existsSync, mkdirSync, createWriteStream, renameSync, unlinkSync } from 'node:fs';
import { createHash } from 'node:crypto';

const WP_CLI_PHAR_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

function resolveInstallDir() {
  const explicit = process.env['STONEWRIGHT_WP_CLI_INSTALL_DIR'];
  if (explicit) return explicit;
  const localAppData = process.env['LOCALAPPDATA'];
  if (localAppData) return join(localAppData, 'Stonewright', 'wp-cli');
  return join(homedir(), '.stonewright', 'wp-cli');
}

async function main() {
  const installDir = resolveInstallDir();
  const pharPath = join(installDir, 'wp-cli.phar');

  if (existsSync(pharPath)) {
    console.log(`[stonewright] WP-CLI phar already present at: ${pharPath}`);
    return;
  }

  console.log(`[stonewright] Downloading WP-CLI phar to: ${pharPath}`);
  mkdirSync(installDir, { recursive: true });

  const tempPath = `${pharPath}.tmp-${process.pid}`;
  try {
    const response = await fetch(WP_CLI_PHAR_URL, { signal: AbortSignal.timeout(60_000) });
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }
    const buffer = Buffer.from(await response.arrayBuffer());
    const sha256 = createHash('sha256').update(buffer).digest('hex');
    const { writeFileSync, chmodSync } = await import('node:fs');
    writeFileSync(tempPath, buffer, { flag: 'w' });
    try { chmodSync(tempPath, 0o755); } catch { /* Windows — no-op */ }
    renameSync(tempPath, pharPath);
    console.log(`[stonewright] WP-CLI phar installed (${buffer.length} bytes, sha256=${sha256.slice(0, 16)}...)`);
  } catch (err) {
    try { unlinkSync(tempPath); } catch { /* no partial file */ }
    console.warn(`[stonewright] WARNING: Could not download WP-CLI phar: ${err instanceof Error ? err.message : String(err)}`);
    console.warn('[stonewright] WP-CLI will be discovered from PATH or LocalWP at runtime.');
  }
}

main();
```

- [ ] **Step 3.2: Add `postinstall` to `companion/package.json`**

In the `"scripts"` block, add:

```json
"postinstall": "node scripts/postinstall.mjs"
```

(Place it after `"typecheck"` — alphabetical order not required, just keep it grouped with other lifecycle scripts.)

- [ ] **Step 3.3: Test the postinstall script manually**

```bash
cd companion
node scripts/postinstall.mjs
```

Expected output (first run):
```
[stonewright] Downloading WP-CLI phar to: C:\Users\<user>\AppData\Local\Stonewright\wp-cli\wp-cli.phar
[stonewright] WP-CLI phar installed (N bytes, sha256=...)
```

Second run:
```
[stonewright] WP-CLI phar already present at: ...
```

- [ ] **Step 3.4: Run full test suite to verify no regressions**

```bash
cd companion
npm test 2>&1 | tail -10
```

Expected: all tests pass.

- [ ] **Step 3.5: Commit**

```bash
git add companion/scripts/postinstall.mjs companion/package.json
git commit -m "feat(companion): add postinstall script to auto-download wp-cli.phar"
```

---

## Task 4: Add integration smoke test for WP-CLI resolution chain

Verifies that either:
(a) WP-CLI is found via PATH / LocalWP discovery, or
(b) phar is found in the Stonewright cache after install.

This test does NOT require a live WordPress — it only checks the binary resolution chain.

**Files:**
- Create: `companion/tests/wp-cli-bootstrap.test.ts`

- [ ] **Step 4.1: Write the test**

```typescript
/**
 * WP-CLI bootstrap integration test.
 *
 * Verifies the companion can resolve WP-CLI from one of three sources:
 *   1. PATH (system wp or STONEWRIGHT_WP_CLI_BIN)
 *   2. LocalWP discovery (APPDATA / LOCALAPPDATA / HOME scan)
 *   3. Stonewright companion cache (STONEWRIGHT_WP_CLI_INSTALL_DIR)
 *
 * This test does NOT require a live WordPress installation.
 * It uses a fake phar to validate the discovery + phar invocation chain.
 */
import { describe, expect, it } from 'vitest';
import { existsSync, mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { wpCliEnsureReady, resolveWpCliInvocation, type ExecFileRunner } from '../src/wp-cli.js';

describe('WP-CLI bootstrap integration', () => {
  it('resolves WP-CLI from companion cache when phar is present', () => {
    const temp = mkdtempSync(join(tmpdir(), 'sw-bootstrap-'));
    try {
      const installDir = join(temp, 'cache');
      const pharPath = join(installDir, 'wp-cli.phar');
      const phpBin = join(temp, 'php.exe');
      mkdirSync(installDir, { recursive: true });
      writeFileSync(pharPath, 'fake phar');
      writeFileSync(phpBin, 'fake php');

      const invocation = resolveWpCliInvocation(
        {
          STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
          STONEWRIGHT_WP_CLI_PHP_BIN: phpBin,
          STONEWRIGHT_WP_ROOT: temp,
        } as NodeJS.ProcessEnv,
        temp,
      );

      expect(invocation.source).toBe('env_php_phar');
      expect(invocation.prefixArgs).toContain(pharPath);
      expect(invocation.executable).toBe(phpBin);
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });

  it('wpCliEnsureReady is idempotent: skips download when phar already in cache', async () => {
    const temp = mkdtempSync(join(tmpdir(), 'sw-ensure-idem-'));
    try {
      const installDir = join(temp, 'cache');
      const pharPath = join(installDir, 'wp-cli.phar');
      mkdirSync(installDir, { recursive: true });
      writeFileSync(pharPath, 'existing phar');

      let fetchCalled = false;
      const fetchImpl = () => { fetchCalled = true; return Promise.resolve(new Response('unused')); };
      const runner: ExecFileRunner = () =>
        Promise.resolve({ stdout: '{"wp_cli_version":"2.10.0"}', stderr: '', exitCode: 0 });
      const env = {
        STONEWRIGHT_WP_CLI_INSTALL_DIR: installDir,
        STONEWRIGHT_WP_CLI_PHP_BIN: 'php',
        STONEWRIGHT_WP_ROOT: process.cwd(),
      } as NodeJS.ProcessEnv;

      const result = await wpCliEnsureReady({ runner, env, fetchImpl });
      expect(result.ensured).toBe(true);
      // Phar was already present; wpCliStatus succeeded on first check.
      // wpCliInstall should NOT have been called (phar already exists).
      expect(fetchCalled).toBe(false);
    } finally {
      rmSync(temp, { recursive: true, force: true });
    }
  });
});
```

- [ ] **Step 4.2: Run the new test to verify it passes**

```bash
cd companion
npm test -- tests/wp-cli-bootstrap.test.ts --reporter=verbose 2>&1
```

Expected: PASS (2 tests).

- [ ] **Step 4.3: Run full test suite**

```bash
cd companion
npm test 2>&1 | tail -15
```

Expected: all tests pass.

- [ ] **Step 4.4: Commit**

```bash
git add companion/tests/wp-cli-bootstrap.test.ts
git commit -m "test(companion): add WP-CLI bootstrap integration smoke tests"
```

---

## Task 5: Update `.env.example` and README with WP-CLI bootstrap notes

**Files:**
- Modify: `companion/.env.example`
- Modify: `companion/README.md`

- [ ] **Step 5.1: Add `STONEWRIGHT_WP_CLI_INSTALL_DIR` and PHP env vars to `.env.example`**

Add after the existing `STONEWRIGHT_WP_CLI_BIN` line:

```dotenv
# Path where wp-cli.phar is downloaded by the postinstall script.
# Defaults to %LOCALAPPDATA%\Stonewright\wp-cli (Windows) or ~/.stonewright/wp-cli
# STONEWRIGHT_WP_CLI_INSTALL_DIR=C:\Users\you\AppData\Local\Stonewright\wp-cli

# Path to PHP binary used to run wp-cli.phar (auto-discovered from LocalWP by default)
# STONEWRIGHT_WP_CLI_PHP_BIN=php

# Path to php.ini for WP-CLI (optional; auto-discovered from LocalWP by default)
# STONEWRIGHT_WP_CLI_PHP_INI=C:\path\to\php.ini

# Path to a pre-downloaded wp-cli.phar (overrides STONEWRIGHT_WP_CLI_INSTALL_DIR discovery)
# STONEWRIGHT_WP_CLI_PHAR_PATH=C:\path\to\wp-cli.phar
```

- [ ] **Step 5.2: Add a "WP-CLI" section to `companion/README.md`**

Find the existing WP-CLI mention and expand it. Add after the environment variables table:

```markdown
## WP-CLI Auto-Bootstrap

The Stonewright companion automatically ensures WP-CLI is available at startup
using a three-step resolution chain:

1. **`STONEWRIGHT_WP_CLI_BIN`** — use this exact binary (e.g. `wp` on PATH, or a full path).
2. **`STONEWRIGHT_WP_CLI_PHP_BIN` + `STONEWRIGHT_WP_CLI_PHAR_PATH`** — run phar through a specific PHP.
3. **LocalWP auto-discovery** — scans `%APPDATA%`, `%LOCALAPPDATA%`, and `%PROGRAMFILES%`
   for LocalWP's bundled PHP and `wp-cli.phar`.
4. **Companion cache** — on first startup (and during `npm install` via the `postinstall`
   script), downloads the official `wp-cli.phar` into:
   - Windows: `%LOCALAPPDATA%\Stonewright\wp-cli\wp-cli.phar`
   - macOS/Linux: `~/.stonewright/wp-cli/wp-cli.phar`

This means **no manual WP-CLI installation is required** for most setups. The download
is idempotent — if the phar already exists it is reused without re-downloading.

### Override

Set `STONEWRIGHT_WP_CLI_BIN=wp` in your `.env` if you have WP-CLI installed globally
and want to skip phar discovery entirely.
```

- [ ] **Step 5.3: Commit**

```bash
git add companion/.env.example companion/README.md
git commit -m "docs(companion): document WP-CLI auto-bootstrap chain"
```

---

## Task 6: Build, full test, and merge to main

- [ ] **Step 6.1: Build companion**

```bash
cd companion
npm run build 2>&1 | tail -20
```

Expected: no TypeScript errors, `dist/` populated.

- [ ] **Step 6.2: Run full test suite one final time**

```bash
cd companion
npm test 2>&1 | tail -20
```

Expected: all tests pass (including the 3 new `wpCliEnsureReady` tests and 2 bootstrap integration tests).

- [ ] **Step 6.3: Typecheck**

```bash
cd companion
npm run typecheck 2>&1
```

Expected: no errors.

- [ ] **Step 6.4: Push branch**

```bash
git push -u origin feat/wp-cli-auto-bootstrap
```

- [ ] **Step 6.5: Merge to main**

```bash
git checkout main
git merge --no-ff feat/wp-cli-auto-bootstrap -m "feat: WP-CLI auto-bootstrap at every Stonewright companion install/start"
git push origin main
```

---

## Verification Plan

### Automated Tests
- `cd companion && npm test` — must show all tests green, including the 5 new tests.
- `cd companion && npm run typecheck` — must show no errors.
- `cd companion && npm run build` — must produce `dist/index.js` without errors.

### Manual Verification (after merge)
1. Delete `%LOCALAPPDATA%\Stonewright\wp-cli\wp-cli.phar` if it exists.
2. Run `cd companion && npm install` — should print `[stonewright] Downloading WP-CLI phar...` and end with `installed (N bytes, sha256=...)`.
3. Run `cd companion && npm run start` — companion log should include `WP-CLI ready` with `source: "installed"` or `source: "already_available"`.
4. Call MCP tool `stonewright-wp-cli-status` from any connected MCP client — should return `available: true`.
