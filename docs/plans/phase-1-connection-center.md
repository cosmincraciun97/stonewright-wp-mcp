# Phase 1 — Connection Center Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prove a real authenticated MCP connection from Setup (loopback self-test + companion doctor), with one client picker, one method picker, versioned client definitions, and parser-validated snippets — without breaking existing abilities or Application Password flows.

**Architecture:** Keep Application Passwords as the default credential path. Move client metadata into `plugin/data/clients/*.json`. Add a plugin-side loopback MCP HTTP self-test that mints a short-lived test credential, runs initialize → tools/list → read-only `stonewright-task-start`, and returns structured step results. Companion gains `stonewright doctor` for the stdio path. OAuth stays Phase 1B.

**Tech Stack:** PHP 8.1+ (plugin), Node/TypeScript (companion), PHPUnit, Vitest, Playwright (`e2e/`), existing REST under `stonewright/v1/admin/*`.

**Branch:** `feat/connection-center-e2e` (stacked on Phase 0 `fix/alpha72-baseline-lint-doc-truth`)

**Parent plan:** `docs/plans/stonewright-premium-enhancement-plan-2026-07-16-optimized.md` §Phase 1

---

## File map

| Path | Responsibility |
|---|---|
| `plugin/data/clients/*.json` | Versioned per-client definitions (slug, labels, OS paths, snippet kinds, verified-on date) |
| `plugin/includes/Admin/ClientCatalog.php` | Load + validate client JSON; replace hard-coded lists |
| `plugin/includes/Admin/ConnectClientConfig.php` | Snippet generation from catalog + credentials |
| `plugin/includes/Admin/McpLoopbackSelfTest.php` | Loopback initialize → tools/list → task-start |
| `plugin/includes/Admin/RestApi.php` | Route for real connection test (keep preflight separate) |
| `plugin/includes/Admin/ConfigurationPage.php` | One client picker + one method picker UI |
| `plugin/assets/admin/admin.js` / `setup.css` | UI behavior for pickers + connection results |
| `companion/src/cli/doctor.ts` | `stonewright doctor` stdio health |
| `companion/src/cli/init.ts` / package bin | Wire doctor command |
| `plugin/tests/Unit/Admin/*` | Catalog, loopback, snippet parser fixtures |
| `companion/tests/doctor*.test.ts` | Doctor unit tests |
| `docs/admin/configuration.md`, `connect-clients.md` | Truthful setup docs |
| `docs/verified-client-versions.md` | Manual smoke evidence table |

---

### Task 1.1: Client catalog JSON + loader

**Files:**
- Create: `plugin/data/clients/claude-code.json`, `claude-desktop.json`, `codex.json`, `cursor.json`, `vscode.json`, `gemini-cli.json`, `windsurf.json`, `zed.json`, `opencode.json`, `generic-mcp.json` (and keep existing clients as thin JSON so nothing disappears)
- Create: `plugin/includes/Admin/ClientCatalog.php`
- Create: `plugin/tests/Unit/Admin/ClientCatalogTest.php`
- Modify: `plugin/includes/Admin/ConnectClientConfig.php` to consume catalog

- [ ] **Step 1: Write failing ClientCatalogTest** asserting `ClientCatalog::all()` returns ≥ 10 clients with required keys: `slug`, `label`, `kind`, `config_paths` (os-keyed), `snippet_kind`, `verified_against_docs_on`, and that unknown slug returns null.

- [ ] **Step 2: Implement ClientCatalog** reading `STONEWRIGHT_DIR . 'data/clients/*.json'`, validating schema, sorting by label.

Minimal JSON shape:

```json
{
  "slug": "codex",
  "label": "Codex",
  "kind": "cli",
  "snippet_kind": "toml",
  "preferred_method": "stdio",
  "official_cli_add": "codex mcp add",
  "config_paths": {
    "macos": "~/.codex/config.toml",
    "linux": "~/.codex/config.toml",
    "windows": "%USERPROFILE%\\.codex\\config.toml",
    "wsl": "~/.codex/config.toml"
  },
  "notes": "Prefer official CLI add; raw TOML is advanced fallback.",
  "verified_against_docs_on": "2026-07-16",
  "secret_storage": "user-level"
}
```

- [ ] **Step 3: Migrate `ConnectClientConfig::clients()`** to wrap `ClientCatalog::all()` (map to existing return shape for BC).

- [ ] **Step 4:** `composer test -- --filter ClientCatalogTest` green; full related tests green; commit `feat: add versioned MCP client catalog data files`.

---

### Task 1.2: Snippet generators + parser/tokenization fixtures

**Files:**
- Create: `plugin/tests/Unit/Admin/ClientSnippetValidationTest.php`
- Modify: `plugin/includes/Admin/ConnectClientConfig.php` as needed for stable outputs
- Create: `plugin/tests/fixtures/client-snippets/` expected fragments if useful

- [ ] **Step 1: Failing tests** — for each primary client (`claude-code`, `claude-desktop`, `codex`, `cursor`, `vscode-copilot`, `gemini-cli`): generate snippet with fixture username/password; assert JSON parses (`json_decode`), TOML has required keys via simple line assertions, CLI commands tokenize with `str_getcsv($cmd, ' ')` or argv splitter and never embed raw shell metacharacters unquoted.

- [ ] **Step 2: Fix generators** until green. Prefer official CLI forms where documented (`claude mcp add`, `codex mcp add`, `gemini mcp add`).

- [ ] **Step 3:** Never recommend project-tracked files with literal secrets; snippets that include passwords must document user-level/private storage in `notes`.

- [ ] **Step 4:** Commit `test: parser-validate generated MCP client snippets`.

---

### Task 1.3: Plugin loopback MCP self-test

**Files:**
- Create: `plugin/includes/Admin/McpLoopbackSelfTest.php`
- Modify: `plugin/includes/Admin/RestApi.php` (new route `stonewright/v1/admin/connection-verify`)
- Modify: `plugin/includes/Admin/ConfigurationPage.php` + `admin.js` (add "Verify connection" distinct from "Run preflight")
- Test: `plugin/tests/Unit/Admin/McpLoopbackSelfTestTest.php`

**Behavior:**
1. Require `manage_options`.
2. Mint short-lived Application Password (or reuse a one-shot internal credential) labeled `stonewright-connection-test` with immediate revoke after run.
3. HTTP POST to site MCP endpoint: JSON-RPC `initialize`, then authenticated `tools/list`, assert `stonewright-task-start` present, then call it with a read-only setup intent.
4. Return structured steps: `{ id, status: passed|failed, detail, fix?, retryable? }`.
5. Never green solely because "app passwords exist".
6. On failure, revoke test credential; on success, revoke too (one-shot).

- [ ] **Step 1: Failing unit tests** with HTTP client stub / BrainMonkey for steps order and failure paths.

- [ ] **Step 2: Implement McpLoopbackSelfTest::run()** returning the structured report.

- [ ] **Step 3: REST route** `POST /stonewright/v1/admin/connection-verify` permission-gated.

- [ ] **Step 4: UI** — button "Verify connection" next to preflight; render step list with red/green and exact fix text.

- [ ] **Step 5:** Tests green; commit `feat: add MCP loopback connection self-test`.

---

### Task 1.4: Companion `doctor` command

**Files:**
- Create: `companion/src/cli/doctor.ts`
- Modify: `companion/src/index.ts` (or bin entry) to dispatch `doctor`
- Test: `companion/tests/doctor.test.ts`
- Docs: companion README

**Checks:**
- Node + npx versions
- Credential reachability (env / private config, never print secrets)
- MCP initialize against plugin endpoint when URL+creds present
- Stale tool-cache detection with client-specific refresh instructions

- [ ] **Step 1: Failing tests** for pure check helpers.
- [ ] **Step 2: Implement doctor**, exit code 0 only if all critical checks pass.
- [ ] **Step 3:** `npm run typecheck && npm test && npm run lint && npm run build`
- [ ] **Step 4:** Commit `feat: add companion doctor for stdio connection health`.

---

### Task 1.5: Setup UI — one client picker + one method picker

**Files:**
- Modify: `ConfigurationPage.php`, `admin.js`, `setup.css` (or shell.css)
- Remove duplicated Step-3 tab systems; single selected client + method: `stdio` | `http` | `application-password` (credential step remains).

- [ ] **Step 1: Identify** both tab systems (cards vs advanced JSON tabs) and collapse to one source of truth driven by `ClientCatalog`.
- [ ] **Step 2: UI** — radio/select client list; method segmented control; snippet panel updates; copy buttons preserved.
- [ ] **Step 3: Persist** selected client + method in user meta.
- [ ] **Step 4: PHPUnit for render HTML contains single method picker markers; no duplicate "Connect" tab systems.
- [ ] **Step 5: Playwright** protocol §5 on Setup page (use e2e package or Playwright MCP); archive evidence under `docs/plans/evidence/phase-1/`.
- [ ] **Step 6:** Commit `feat: unify setup into single client and method pickers`.

---

### Task 1.6: Docs + verified client versions table

**Files:**
- Modify: `docs/admin/configuration.md`, `docs/admin/connect-clients.md`, `docs/installation.md` (connection section)
- Create: `docs/verified-client-versions.md`
- Run: `node scripts/check-docs-freshness.mjs`

- [ ] Document Connection Center flow: preflight vs verify, methods, secret storage policy.
- [ ] Table of clients with `verified_against_docs_on` and manual smoke status.
- [ ] Commit `docs: document Connection Center and verified client matrix`.

---

### Task 1.7: Phase 1 acceptance gate

```bash
cd plugin && composer test && composer phpstan && composer phpcs && composer contracts:compat
cd ../companion && npm run typecheck && npm run lint && npm test && npm run build
node scripts/check-docs-freshness.mjs && git diff --check
```

Acceptance criteria from parent plan:
- Clean Codex config reaches real `stonewright-task-start` via loopback test
- Broken credential → red + specific fix
- Parser tests for Codex/Claude Code/Cursor/VS Code/Gemini CLI
- Playwright evidence on Setup screens

---

## Out of scope (Phase 1B / later)

- OAuth 2.1 / PKCE
- Spawning client GUI binaries from WordPress
- Full admin shell redesign (Phase 9)
