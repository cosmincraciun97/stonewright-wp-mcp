# Stonewright Premium Enhancement — Optimized Execution Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Execution contract:** Phase 0 is fully specified below — execute it directly from this file. For every later phase, the FIRST task is always: write a detailed per-phase implementation plan with superpowers:writing-plans (bite-sized TDD tasks, complete code), save it to `docs/plans/phase-N-<name>.md`, then execute that plan. One phase = one PR (or a small stack of PRs). Never one giant PR.

**Goal:** Take Stonewright from a working alpha to a premium product: truthful setup, compact default MCP surface, precision native Elementor/Gutenberg/FSE engines, transactional writes with rollback, and a polished wp-admin — without breaking any existing ability.

**Architecture:** Keep the existing plugin + companion + visual package architecture. Add: contract snapshots with compatibility tests, a real loopback MCP self-test, profile-driven tool exposure, DesignSpec v2 with native policy, transaction envelopes with snapshot/rollback, and Playwright-verified admin UI.

**Tech Stack:** PHP 8.1–8.5 (WordPress plugin, PHPUnit/PHPStan/PHPCS), Node/TypeScript (companion + visual, Vitest/ESLint), Playwright (`@playwright/test` for the `e2e:admin-ui` CI gate; Playwright MCP tools for interactive verification during development), wp-env for WordPress integration fixtures.

**Status:** verified against repo `main` @ `2603cfe` (1.0.0-alpha.72) on 2026-07-16. All numeric claims from the source audit were independently re-verified (see §1).

**Source plan:** `docs/plans/stonewright-premium-enhancement-plan-2026-07-16.md` (kept as the audit record; this file supersedes it for execution).

---

## 1. Feasibility verdict and verified facts

The original plan is **feasible and factually accurate**. Every load-bearing claim was re-checked against the repository on 2026-07-16:

| Claim | Verified result |
|---|---|
| 308 registered abilities | ✅ 308 rows in `docs/ability-truth-matrix.md` |
| 98 Direct tools | ✅ `DIRECT_TOOL_NAMES` sums to 98 (12+31+40+7+3+5) in `companion/src/direct/registry.ts:168` |
| Essential budget 30 | ✅ `ESSENTIAL_MAX_TOOLS = 30` in `plugin/includes/Support/TokenSurfaceBudgets.php:19` |
| SetupDiagnostics hardcodes `<= 20` | ✅ `plugin/includes/Admin/SetupDiagnostics.php:44` |
| Companion ESLint: 88 errors | ✅ exactly 88 errors (12 auto-fixable), and CI does **not** run `npm run lint` for companion |
| 12 blueprints / 20 brand kits | ✅ `plugin/blueprints/` = 12, `plugin/brand-kits/` = 20 |
| All "files expected to change" exist | ✅ except one wrong path — see corrections |
| `docs/contracts/` absent | ✅ does not exist; Phase 0 creates it |
| CI matrix | ✅ PHP 8.1–8.5 exists; **no WordPress version matrix** exists yet |
| `wordpress/abilities-api` dependency | ✅ `"^0.1.0 || ^1.0"` in `plugin/composer.json:9` |
| `scripts/check-docs-freshness.mjs` | ✅ exists but **untracked**; docs pass is uncommitted working-tree state |

## 2. Corrections applied to the original plan

1. **Wrong path:** `plugin/includes/BrandKits/*` does not exist. Brand kit code lives in `plugin/includes/DesignTokens/` (e.g. `BrandKit.php`). All Phase 6 tasks use the real path.
2. **Infeasible as written — child-process client launch:** the original Wave 1 wanted the plugin to "start the selected stdio command in a safe child process". WordPress spawning arbitrary client binaries is unreliable/blocked on most hosting and a security liability. Replaced with: **plugin-side loopback MCP self-test** (HTTP: initialize → tools/list → `stonewright-task-start` using a short-lived test credential) + **companion-side `doctor` command** covering the stdio path. Together they prove the full chain without spawning client apps.
3. **OAuth moved off the critical path:** OAuth 2.1/PKCE for WordPress is a large research-plus-infra effort. It becomes Phase 1B (optional, additive, parallel) so Phase 1 acceptance never blocks on it. Application Passwords remain the supported default.
4. **Client smoke matrix scoped honestly:** CI runs parser/schema fixture tests for every generated client config (JSON/TOML/CLI tokenization). Proprietary GUI clients cannot run in CI; manual smoke evidence is recorded in a versioned "verified client versions" table instead of pretending CI coverage.
5. **UI verification = Playwright** (replaces any generic/agent-browser evidence): a new `e2e/admin-ui` package with `@playwright/test` becomes the `e2e:admin-ui` CI gate; during development, the executing agent verifies interactively with Playwright MCP tools. Protocol in §5.
6. **WordPress integration fixtures get an owner:** fresh-install/upgrade tests on WP 6.7/6.9/7.0 require infrastructure that does not exist. Phase 0 introduces `wp-env` scaffolding; Phase 12 consumes it. Until then, CI honestly reports unit-test-only coverage.
7. **"Wave" renamed to "Phase":** the codebase already uses `DIRECT_WAVE1..5` internally and past PRs used "waveN" branch names. Phases 0–12 avoid the collision.
8. **Quick wins pulled into Phase 0:** the SetupDiagnostics budget fix (one line + test), committing the untracked docs-freshness gate, repo-root screenshot cleanup, and companion lint. No reason to defer trivially reversible truth fixes.
9. **Baseline is dirty:** the working tree contains an uncommitted documentation pass, the untracked freshness gate script, and ~12 stray PNG screenshots at repo root. Phase 0 Task 1 lands the docs work and removes the stray artifacts before anything else, so every later diff is clean.

## 3. Non-negotiable compatibility contract (unchanged, enforced every phase)

- [ ] Existing `stonewright/*` ability names remain callable; hyphenated MCP names remain callable.
- [ ] Input/output schemas do not break without versioned adapters.
- [ ] `stonewright/php-execute` remains first-class, permission/mode/audit gated.
- [ ] Every write uses a real `Stonewright\WpMcp\Security\Permissions` helper — never `__return_true`.
- [ ] Elementor/template/global-style/theme.json writes call `Backup::snapshot_post()` first.
- [ ] Design specs pass `Validator::validate()` before render; invalid → `WP_Error` code `stonewright_spec_invalid`.
- [ ] Production-safe destructive ops verify `ConfirmationToken::verify()`.
- [ ] Modes stay `development` / `staging` / `production-safe`.
- [ ] Companion WP-CLI stays `execFile` + argv tokens; no shell, no eval/`--exec`/`--require`.
- [ ] No HTML-widget generation; `AddHtml` stays disabled-by-default compatibility surface.
- [ ] Native builder controls win over CSS/custom code.
- [ ] Existing UI routes keep working via redirects/compatibility views.
- [ ] Public commits/changelogs/docs/skills/PR text: no automated-authorship claims, no internal-tooling disclosure, no competitor product names (attribution only in `docs/upstream-code-reuse.md` + SPDX headers).
- [ ] Docs ship in the same PR as behavior; `node scripts/check-docs-freshness.mjs` and `git diff --check` must pass before any phase closes.

## 4. Phase dependency graph and parallel lanes

```text
Phase 0 (baseline truth)  ──► everything
Phase 1 (connection)      ──► Phase 9 (Connect UI parts), Phase 12
Phase 1B (OAuth, optional)     parallel to 2–9, lands whenever ready
Phase 2 (tool surface)    ──► Phase 7, Phase 8
Phase 3 (DesignSpec v2)   ──► Phase 4, Phase 5, Phase 6
Phase 4 (Elementor)  ┐
Phase 5 (Gutenberg)  ├──► Phase 10 (prompts reference real tool paths)
Phase 6 (blueprints) ┘
Phase 9 (admin UI)        independent lane after Phase 0 (needs Phase 1 only for Connect screens)
Phase 11 (docs truth)     continuous; final consolidation after 1–10
Phase 12 (release verify) last
```

Parallel lanes for multiple agents/worktrees: **Lane A** 1→2→7→8, **Lane B** 3→4→5→6, **Lane C** 9 (UI), **Lane D** 1B + 10 + 11. Phase 0 is a hard barrier before any lane starts.

## 5. UI verification protocol (Playwright — mandatory for every UI-touching phase)

Two layers, both required:

**Layer 1 — interactive (during development), Playwright MCP tools:**

1. `browser_navigate` to each affected wp-admin page (authenticated on the local site, e.g. `http://transavia-local.local/wp-admin/admin.php?page=stonewright`).
2. For each viewport 1440×900, 1024×768, 782×1024, 390×844, 320×568: `browser_resize`, then
   - `browser_snapshot` (accessibility tree) — verify roles, names, states (e.g. theme toggle `aria-label`/`aria-pressed` matches actual state; tabs expose roving tabindex);
   - `browser_take_screenshot` — archive under `docs/plans/evidence/phase-N/`;
   - `browser_evaluate` with `() => document.documentElement.scrollWidth - document.documentElement.clientWidth` — must be `<= 0` (no horizontal overflow);
   - `browser_console_messages` — zero errors.
3. Repeat in light and dark theme (toggle via the product control, not devtools emulation, so the persisted state is exercised).
4. Keyboard pass: `browser_press_key` Tab/Shift+Tab/Arrow/Escape through tabs, dialogs, drawers; verify focus visible and focus return.

**Layer 2 — CI gate (`e2e:admin-ui`), `@playwright/test`:**

- New package `e2e/` (scaffolded in Phase 0 Task 6) with specs asserting the same invariants: no horizontal overflow at the 5 breakpoints, no console errors, axe-style accessible-name checks for controls, keyboard operability of tabs/dialogs, light/dark rendering.
- Runs against a `wp-env` instance in CI; locally against the Local site.

Evidence (screenshots + short findings note) attaches to the phase PR.

## 6. Success metrics (unchanged targets, all measurable)

- Setup: fresh user reaches successful `stonewright-task-start` in < 5 min; connection test proves initialize + tools/list + authenticated task-start; no secrets in project-tracked files by default.
- Surface: bootstrap ≤ 8 tools & < 2,500 est. tokens (feasible: essential is 7,424 tokens / 29 tools ≈ 256/tool → 8 ≈ 2,050); strict ≤ 12; task ≤ 20; essential ≤ 30; full stays opt-in; `task-start` ≤ 700/1,200 tokens non-visual/visual.
- Precision: 100% of supported writes produce structured readback; failed validation/readback auto-rolls-back; no silent engine fallback; no invented business facts; unsupported controls → loss report.
- UI: WCAG 2.2 AA on product surfaces; 40 px desktop / 44 px touch targets; keyboard-complete; zero horizontal overflow at 1440/1024/782/390/320; light/dark/system with truthful accessible labels.
- Release: all PHP + companion + visual + docs + e2e gates green; packaged ZIP/TGZ smoke-install clean.

---

## Phase 0 — Freeze truth and baseline (execute directly from this file)

Goal: trustworthy, committed, lint-clean baseline with contract snapshots and e2e scaffolding. Everything here is low-risk and reversible.

### Task 0.1: Land the pending docs-freshness work and clean the repo root

**Files:**
- Commit: all currently modified docs/workflow files + untracked `scripts/check-docs-freshness.mjs`, `docs/documentation-maintenance.md`
- Delete: stray root artifacts `*.png`, `.tmp-gcb-custom.css` (session screenshots, not product assets)
- Modify: `.gitignore` (root-level `*.png` and `.tmp-*` patterns)

- [ ] **Step 1:** `git status --porcelain` — confirm the modified set matches the 2026-07-16 documentation pass (docs, workflows, skills, ContextBootstrap + its test).
- [ ] **Step 2:** Run the gates: `node scripts/check-docs-freshness.mjs && git diff --check` — expect both pass; if the freshness script fails, fix the doc it names before committing.
- [ ] **Step 3:** `cd plugin && composer test` (the pass touched `ContextBootstrap.php` + `ContextBootstrapTest.php`) — expect green.
- [ ] **Step 4:** Delete stray artifacts: `rm -f *.png .tmp-gcb-custom.css` at repo root; add to `.gitignore`:
  ```gitignore
  /*.png
  /.tmp-*
  ```
- [ ] **Step 5:** Branch `fix/alpha72-baseline-truth`, commit as `docs: land documentation freshness gate and 2026-07-16 doc-truth pass` (+ separate `chore: remove stray session artifacts from repo root`).

### Task 0.2: Fix all 88 companion lint errors and gate lint in CI

**Files:**
- Modify: companion sources/tests flagged by ESLint (88 errors, 12 auto-fixable; dominant rule: `@typescript-eslint/require-await` in tests)
- Modify: `.github/workflows/ci.yml` (companion job — add `npm run lint` after `npm run typecheck`)

- [ ] **Step 1:** `cd companion && npm run lint -- --fix` — clears the 12 auto-fixable.
- [ ] **Step 2:** Fix the rest by hand. For `require-await` in tests: remove `async` when the body has no `await` — do NOT weaken the ESLint config or add blanket disables. Rule-level exceptions require a written justification in the PR.
- [ ] **Step 3:** `npm run lint` → 0 problems; `npm run typecheck && npm test && npm run build` → all green (no behavior change allowed).
- [ ] **Step 4:** Add `- run: npm run lint` to the companion CI job in `.github/workflows/ci.yml`.
- [ ] **Step 5:** Commit `fix: clear companion lint debt and enforce lint in CI`.

### Task 0.3: SetupDiagnostics uses the real budget, not a hardcoded 20

**Files:**
- Modify: `plugin/includes/Admin/SetupDiagnostics.php:44`
- Test: `plugin/tests/Unit/Admin/SetupDiagnosticsTest.php`

- [ ] **Step 1: Write the failing test** — in `SetupDiagnosticsTest.php`, add a case where the enabled-ability count is between 21 and 30 (e.g. stub registry to 29) and assert the `tool_budget` check reports ok:

```php
public function test_tool_budget_passes_at_essential_maximum(): void {
    // Arrange the ability registry stub to expose 29 enabled abilities
    // (follow the existing stubbing pattern in this test file).
    $report = SetupDiagnostics::report();
    $budget = $this->find_check( $report['checks'], 'tool_budget' );
    self::assertSame( 'ok', $budget['status'], '29 tools is within ESSENTIAL_MAX_TOOLS and must pass.' );
}

public function test_tool_budget_fails_above_essential_maximum(): void {
    // Arrange 31 enabled abilities.
    $report = SetupDiagnostics::report();
    $budget = $this->find_check( $report['checks'], 'tool_budget' );
    self::assertNotSame( 'ok', $budget['status'] );
}
```

- [ ] **Step 2:** `cd plugin && composer test -- --filter SetupDiagnosticsTest` — expect the 29-tool case to FAIL against the current `<= 20`.
- [ ] **Step 3: Implement** — in `SetupDiagnostics.php` add `use Stonewright\WpMcp\Support\TokenSurfaceBudgets;` and change line 44:

```php
self::check( 'tool_budget', $tool_count <= TokenSurfaceBudgets::ESSENTIAL_MAX_TOOLS, __( 'Compact tool surface', 'stonewright' ), sprintf( __( '%d tools exposed in the current profile.', 'stonewright' ), $tool_count ) ),
```

- [ ] **Step 4:** `composer test -- --filter SetupDiagnosticsTest` → PASS; then full `composer test && composer phpstan && composer phpcs`.
- [ ] **Step 5:** Commit `fix: align setup tool-budget check with TokenSurfaceBudgets essential maximum`.

### Task 0.4: Rename the setup connection test to "Run preflight"

Until a real MCP self-test exists (Phase 1), the current check must not present itself as a connection proof.

**Files:**
- Modify: `plugin/includes/Admin/ConfigurationPage.php` (button/label copy), `plugin/assets/admin/admin.js` (any UI strings), related i18n strings
- Test: extend the ConfigurationPage/Setup test that asserts the copy, if present; otherwise assert label in `SetupDiagnosticsTest`-adjacent view test

- [ ] **Step 1:** Locate current label: `grep -rn "Verify connection\|verify_connection" plugin/includes plugin/assets`.
- [ ] **Step 2:** Rename UI copy to "Run preflight" and adjust result phrasing ("Preflight passed — run a real connection test from your MCP client" instead of any green "connected" claim).
- [ ] **Step 3:** `composer test && composer phpcs`; update any doc that calls it a connection test (`docs/admin/configuration.md` etc.), rerun `node scripts/check-docs-freshness.mjs`.
- [ ] **Step 4:** Commit `fix: present setup check as preflight until real MCP verification exists`.

### Task 0.5: Public contract snapshots + compatibility tests

**Files:**
- Create: `plugin/bin/generate-contracts.php`, `docs/contracts/public-api-v1.json`, `docs/contracts/direct-tools-v1.json`
- Create: `plugin/tests/Unit/Contracts/PublicApiContractTest.php`, `companion/tests/direct-tools-contract.test.ts`
- Modify: `plugin/composer.json` (script `contracts:generate` + `contracts:compat`), `companion/package.json` (script `contracts:compat`), `.github/workflows/ci.yml`

- [ ] **Step 1:** Write `plugin/bin/generate-contracts.php` following the existing pattern of `plugin/bin/generate-ability-matrix.php`: iterate the ability registry; emit per ability `{ ability_name, mcp_name, kind, input_schema_hash, output_schema_hash, permission_class, gates: {backup, token, validator, audit} }`, sorted, stable-serialized.
- [ ] **Step 2:** Generate `docs/contracts/public-api-v1.json` (expect 308 entries) and `docs/contracts/direct-tools-v1.json` from `DIRECT_TOOL_NAMES` (expect 98 entries, exported via a small companion script reusing `companion/src/direct/registry.ts`).
- [ ] **Step 3: Failing tests first** — contract tests load the JSON and diff against live registry: removal/rename or schema-hash change of an existing entry FAILS; additions pass. Include an `allowlist` block in the JSON for intentional migrations (empty initially).
- [ ] **Step 4:** Wire `composer contracts:compat` + companion `npm run contracts:compat` into CI. Full suites green.
- [ ] **Step 5:** Commit `feat: freeze public ability and direct-tool contracts with compatibility tests`.

### Task 0.6: Playwright e2e scaffold + wp-env fixture + baseline evidence

**Files:**
- Create: `e2e/package.json`, `e2e/playwright.config.ts`, `e2e/tests/admin-ui.spec.ts`, `e2e/.wp-env.json`
- Modify: `.github/workflows/ci.yml` (new `e2e-admin-ui` job), root docs mentioning gates

- [ ] **Step 1:** Scaffold `e2e/` with `@playwright/test`. `playwright.config.ts` defines 5 projects (viewports 1440×900, 1024×768, 782×1024, 390×844, 320×568) × `colorScheme` light/dark; `baseURL` from `WP_BASE_URL` env (default the wp-env URL).
- [ ] **Step 2:** `e2e/.wp-env.json` boots WordPress 7.0 with the plugin mounted; document `npx wp-env start` in `e2e/README.md`. (WP 6.7/6.9 variants land in Phase 12.)
- [ ] **Step 3:** First spec `admin-ui.spec.ts`: log into wp-admin, open each Stonewright page (Dashboard, Setup, AI Abilities, Blueprints, Sandbox, Skills, Memory, Audit Log), assert: HTTP 200, zero console errors, `document.documentElement.scrollWidth <= clientWidth`, and screenshot to `e2e/artifacts/`.
- [ ] **Step 4:** Run locally against the Local site (`WP_BASE_URL=http://transavia-local.local`); commit the passing spec, NOT the screenshots (gitignore `e2e/artifacts/`). Archive one baseline set under `docs/plans/evidence/phase-0/` as the regression reference.
- [ ] **Step 5:** Add the CI job (wp-env service + `npx playwright test`). Commit `test: add Playwright admin-ui e2e gate with wp-env fixture`.

### Task 0.7: Abilities-API support policy decision (doc + test, no code churn)

- [ ] **Step 1:** Write `docs/adr/0001-abilities-api-support-policy.md`: keep `wordpress/abilities-api` while WP 6.7–6.8 are supported; removal trigger = minimum supported WP ≥ 6.9; note the package's abandoned status upstream and that Stonewright pins it deliberately.
- [ ] **Step 2:** Add a test asserting the compat package is only loaded when the native Abilities API is absent (feature detection, not version sniffing).
- [ ] **Step 3:** `composer test` green; freshness gate green. Commit `docs: record abilities-api support policy as ADR-0001`.

**Phase 0 acceptance:** clean `git status`; companion lint green and CI-gated; SetupDiagnostics budget correct; contracts generated + tested (308/98); e2e gate runs; preflight renamed; baseline evidence archived. PR: `fix/alpha72-baseline-lint-doc-truth`.

---

## Phase 1 — Connection Center that proves connection

> First task: write `docs/plans/phase-1-connection-center.md` via superpowers:writing-plans, then execute it.

Scope (corrected from original):

- [ ] One client picker + one method picker (replace the duplicated Step-3 tab systems). Methods: Local stdio companion, Remote Streamable HTTP, Application Password. (OAuth = Phase 1B.)
- [ ] Client definitions move to versioned data files (JSON) consumed by generators for: Claude Code, Claude Desktop, Codex, Cursor, VS Code, Gemini CLI, Windsurf, Zed, OpenCode, generic MCP. Parser-validate every JSON/TOML output; tokenization-validate every CLI command (no shell strings). OS fixtures: macOS, Linux, native Windows (`cmd /c npx` where required), WSL. Prefer official CLI adds (`codex mcp add`, `claude mcp add`, `gemini mcp add`); raw config stays as advanced fallback. Per-client "Verified against docs on YYYY-MM-DD" marker.
- [ ] **Real verification, split by side:**
  - Plugin: loopback HTTP self-test — mint a short-lived single-purpose test credential, then initialize → authenticated `tools/list` → assert `stonewright-task-start` present → call it read-only with a setup intent → verify versions/profile/endpoint identity. Structured step results: passed/failed/exact fix/retry. Never green on "app passwords exist".
  - Companion: `stonewright doctor` covering stdio: Node/npx versions, credential reachability, MCP initialize against the plugin, stale tool-cache detection with client-specific refresh instructions.
- [ ] Default credential storage: user-level/private config; never recommend project files with literal secrets.
- [ ] Files: `plugin/includes/Admin/ConfigurationPage.php`, `ConnectClientConfig.php`, `SetupDiagnostics.php`, `RestApi.php`, `plugin/assets/admin/setup.css`, `admin.js`, `companion/src/cli/init.ts`, `companion/src/setup-profile.ts`, new `plugin/data/clients/*.json` + generators + fixtures.

Acceptance: clean Codex config reaches a real `stonewright-task-start`; broken credential → red + specific fix; Codex/Claude Code/Cursor/VS Code/Gemini CLI snippets have parser tests + documented manual smoke evidence; Playwright protocol (§5) run on the redesigned Setup screens.

## Phase 1B — OAuth (optional lane, additive)

- [ ] Research spike first (timeboxed, output = ADR): WordPress MCP-adapter OAuth 2.1/PKCE options; pick maintained integration or defer with reasons.
- [ ] If adopted: short-lived access tokens, rotating refresh, revocation, least-privilege scopes; Connected Clients UI (name, scopes/profile, created, last used, expires, revoke); Application Passwords untouched; secrets never embedded in `.mcpb`/snippets/prompts/logs/screenshots.

## Phase 2 — Fast surface and progressive discovery

> First task: write `docs/plans/phase-2-tool-surface.md`, then execute.

- [ ] Bootstrap profile: `task-start`, `setup-profile`, `connection-status`, `tool-profile`, `php-execute`, `wp-cli-status`, ≤ 2 recovery tools (≤ 8 total, < 2,500 est. tokens).
- [ ] Bootstrap/essential default for NEW installs; full mode behind explicit advanced choice; existing installs keep current behavior.
- [ ] `task-start` returns exact ordered tool list per intent/surface; skills referenced, not inlined.
- [ ] `tools/list_changed` only when client supports it; for static clients: dispatcher/composite tools or explicit restart requirement — documented per client.
- [ ] Single source of truth for counts: companion measurement reads runtime `DIRECT_TOOL_NAMES` (98) — kill the 83-name static scan; add an explicit Direct essential export.
- [ ] Budget regression tests per profile (plugin, companion proxy, Direct, client caps) extending `TokenSurfaceBudgets` + `tokens:measure`.
- [ ] Files: `plugin/includes/Core/ServerRegistration.php`, `AbilityRegistry.php`, `plugin/includes/Abilities/System/TaskStart.php`, `ToolProfile.php`, `plugin/includes/Support/TokenSurfaceBudgets.php`, `companion/src/mcp-server.ts`, `wordpress-mcp.ts`, `companion/src/direct/registry.ts`.

Acceptance: new setup never exposes 308 tools by default; bootstrap ≤ 8 tools / < 2,500 tokens measured; existing full-profile clients unaffected (contract tests from Phase 0 prove it); Setup labels full mode as slow/high-context.

## Phase 3 — DesignSpec v2 and native policy

> First task: write `docs/plans/phase-3-designspec-v2.md`, then execute.

- [ ] v1 stays supported; v1→v2 migrator; v1 fixtures render identically through the adapter (regression fixtures required).
- [ ] v2 model: `ContentFacts`, `DesignSystem`, `PageIntent`, `SectionIntent`, `ComponentIntent`, `ResponsiveIntent`, `InteractionIntent`, `NativePolicy`, `VerificationPolicy`. Semantic tokens (fg/bg pairs, surfaces, borders, states, spacing scale, container widths, grid, type scale, radii, shadows, motion, form controls, buttons, links). Content provenance + confidence; placeholders only for unresolved facts — never invent; `required_facts` + `blocking_questions` in planner output; renderer capability negotiation + loss report; stable node IDs + source-to-target mapping.
- [ ] Native policy enforcement in `Validator`: global styles > repeated element styles; native widgets/blocks > HTML/shortcodes/custom CSS/JS; custom CSS only with structured `native_gap` reason + scoped selector plan; HTML widgets/Custom HTML blocked in blueprint renderers; validate heading hierarchy, landmarks, semantics, contrast, alt intent, responsive ordering.
- [ ] Files: `plugin/schemas/stonewright.schema.json`, `plugin/includes/DesignSpec/*`, `plugin/includes/Design/*`, `plugin/includes/Renderers/*`, `plugin/includes/Abilities/Design/*`.

Acceptance: same v2 spec compiles to Elementor AND Gutenberg/FSE with explicit loss reports; v1 fixture parity proven by tests.

## Phase 4 — Elementor precision engine

> First task: write `docs/plans/phase-4-elementor-precision.md`, then execute.

- [ ] Inspect: one-call document snapshot (type, kit, breakpoints, settings, tree digest, tokens, classes/variables, dynamic tags, conditions, widget inventory, schema hashes); detect V3/containers/nested/V4 Atomic/mixed/experimental; exact target selection required for ambiguous docs; schema cache keyed by Elementor version + plugin set; widget/control search by intent.
- [ ] Write: transaction envelope (precondition hash, snapshot ID, operations, stop-on-error, rollback-on-error, expected readback); aliases for created IDs; deterministic IDs + editor labels; containers/grid/flex native controls; kit globals + Atomic variables/classes; responsive via live controls; template/theme-builder conditions + dynamic tags via typed APIs; CSS/cache flush only when required. `Backup::snapshot_post()` before every write — enforced by test.
- [ ] Verify: structural readback with normalized equality; frontend render check; Playwright desktop/tablet/mobile screenshots for visual tasks (§5 tooling); overflow/assets/console/links/headings/contrast checks; automatic rollback on failed structural readback; suggested repair patch on visual mismatch — never blind loops.
- [ ] Files: `plugin/includes/Abilities/ElementorV3/*`, `ElementorV4/*`, `plugin/includes/Elementor/*`, `visual/src/elementor-v3/*`, `visual/src/elementor-v4/*`.

Acceptance: fixtures pass across supported Elementor matrix; no silent no-op controls; no HTML widgets; failed page build restores snapshot; responsive readback verified at 3 breakpoints.

## Phase 5 — Gutenberg and FSE precision engine

> First task: write `docs/plans/phase-5-gutenberg-fse.md`, then execute.

- [ ] One-call editor snapshot (theme type, templates, parts, patterns, navigation, global styles, variations, registered blocks, schemas, bindings sources).
- [ ] Canonical block parser/serializer with round-trip fixtures; schema validation from live `block.json`; nested/locked/content-only/synced patterns + overrides/navigation/query loops/bindings; theme.json v3 + style/block variations + custom templates/parts + typography/font library + spacing/layout presets; WP 7.0 additions via feature detection.
- [ ] Editor/browser finalizer (Playwright-driven where needed) ONLY for blocks unsafe to serialize server-side; never claim browser finalization when browser tools absent.
- [ ] Precondition hashes + revisions for multi-target FSE changes; transaction queue for template+part+global-style updates; `parse_blocks` validation post-write; editor-vs-frontend render comparison.
- [ ] Files: `plugin/includes/Abilities/Gutenberg/*`, `FSE/*`, `visual/src/gutenberg/*`.

Acceptance: core block fixtures reopen without "invalid block"; template/global-style writes snapshot + restore cleanly; FSE pages stay Site-Editor-editable; native controls before CSS.

## Phase 6 — Blueprints and brand kits v2

> First task: write `docs/plans/phase-6-blueprints-brandkits.md`, then execute.
> **Corrected paths:** brand kit code = `plugin/includes/DesignTokens/` (NOT `BrandKits/`); data = `plugin/blueprints/`, `plugin/brand-kits/`; logic = `plugin/includes/Blueprints/`.

- [ ] Blueprint schema v2: industry facts separated from layout intent; page types (landing/home/service/about/contact/archive/single/shop/product/campaign); ≥ 3 materially different composition families per industry; required content facts + explicit placeholder status; engine compatibility + loss score; accessibility + conversion intent; responsive composition rules; asset slots with source/license/alt; version/changelog/schema hash/migration.
- [ ] Brand kit schema v2: semantic color roles with verified contrast pairs; full type scale; container/grid/spacing/radii/shadows/motion/borders; component tokens (buttons, links, inputs, cards, nav, badges, tables, states); target mappings (Elementor kit globals/Atomic variables ↔ theme.json presets); selective apply + diff preview + impact report + snapshot + selective restore; external-font detection with local/system alternatives.
- [ ] Admin UX: real generated previews (not gradients); filters (engine/page type/industry/style/a11y/version); structure preview before write; "Apply to draft" as primary safe action; show exact tools/steps the copied prompt will use.
- [ ] Files: `plugin/blueprints/*`, `plugin/brand-kits/*`, `plugin/includes/Blueprints/*`, `plugin/includes/DesignTokens/*`, `plugin/includes/Admin/Pages/BlueprintsPage.php`, `plugin/assets/admin/blueprints.css`, `companion/src/direct/tools/blueprints.ts`.

Acceptance: every bundled blueprint passes content-truth/native-policy/a11y/responsive/renderer tests; brand kit apply produces diff + restore point; Playwright §5 on the Blueprints page.

## Phase 7 — WordPress generalist workflows

> First task: write `docs/plans/phase-7-wp-workflows.md`, then execute.

- [ ] `site-snapshot` (compact by default), `content-inventory`, transactional bulk content (aliases + rollback), media workflow (search/import/metadata/alt/focal/size/format/usage/readback), menu/Navigation-block workflow, CPT+taxonomy+ACF+template planner, WooCommerce catalog (checkout/payment/account approval-gated), plugin/theme lifecycle preflight, cache/rewrite/schema refresh via typed tools or tokenized WP-CLI.
- [ ] Structured errors: stable codes, JSON paths, likely cause, safe repair, retryability; recurring-error remediation surfaced by `task-start` without bloating compact response.

Acceptance: common workflows measurably fewer calls than baseline (record before/after counts); every write reports changed IDs, before/after digest, verification, rollback handle; zero shell workarounds.

## Phase 8 — Direct/pluginless mode productization

> First task: write `docs/plans/phase-8-direct-mode.md`, then execute.

- [ ] Four honest capability tiers: Remote REST / Local REST+WP-CLI / Plugin / Plugin+browser-QA; unavailable features show reason + upgrade path; stop implying remote pluginless Elementor parity; local Elementor meta editing stays advanced + backup/readback guarded.
- [ ] One-command init (private user config or client-specific commands); site aliases without hand-edited JSON; connection doctor (Node, npx, credentials, REST index, namespaces, WP-CLI, fs root, write policy); Direct essential profile as a real registration-time export (fixes the missing-essential-export finding); builtin skills/memory/AGENTS packaging with version migration.
- [ ] Clean-machine E2E: macOS, Linux, Windows, Local-by-Flywheel, remote HTTPS.

Acceptance: remote pluginless setup never promises plugin capabilities; Direct default surface respects client tool caps (budget tests); backup/readback proven for local Elementor data tools.

## Phase 9 — Premium wp-admin redesign (independent UI lane)

> First task: write `docs/plans/phase-9-admin-shell.md`, then execute. Every sub-task ships with §5 Playwright evidence.

- [ ] IA: 8 links → 6 groups (Overview, Connect, Capabilities, Workflows, Design Library, Safety & Diagnostics); old slugs redirect; global command/search palette; action-oriented Dashboard (connection health, blockers, mode, activity, restore points, next action); raw catalog under Capabilities.
- [ ] Design system: token-first (color/type/spacing/radius/elevation/motion/focus/density); 8 px rhythm with 4 px micro-step; ≥ 40 px desktop / 44 px touch controls; system font stack; quality empty/loading/success/warning/error/offline states; no decorative gradients.
- [ ] A11y/state: theme toggle accessible name + `aria-pressed` truthful; roving tabindex + arrow keys on tabs; dialogs with focus trap/return/Escape/inert background; `aria-live` for async ops; URL-synced filters/tabs/panels; Cmd/Ctrl-click preserved; `:focus-visible` everywhere; reduced motion respected; dark-mode native inputs + `color-scheme`; foreign WP notices isolated, not blindly recolored.
- [ ] Performance: no eager 308-row render (paginate/virtualize/`content-visibility`); debounced search; CSS over JS measurement; page-scoped assets; admin performance budget (bytes, DOM nodes, interaction latency, layout shift); WP 7 DataViews via progressive enhancement with stable fallback.
- [ ] Files: `plugin/includes/Admin/AdminShell.php`, `AdminBootstrap.php`, `plugin/includes/Admin/Pages/*`, `plugin/assets/admin/shell.css`, `shell.js`, page-scoped CSS/JS.

Acceptance: `e2e:admin-ui` suite green across 5 viewports × light/dark; keyboard-only setup + connection flow recorded; zero P0/P1 a11y findings; foreign notices readable.

## Phase 10 — Prompts, skills, and examples

> First task: write `docs/plans/phase-10-prompt-library.md`, then execute.

- [ ] Searchable Prompt Library replacing the disclosure link; organized by outcome (inspect, repair, Elementor page, Gutenberg page, FSE template, brand kit, blueprint, content model, Woo catalog, performance, recovery); each prompt: prerequisites, target surface, expected tool path, safety + native policy, verification, truthful missing-data behavior; short + strict-production variants; RO + EN where maintainable; versioned + schema/tool-compat tied; ≥ 20 prompts evaluated against fixtures (evals reject HTML shortcuts + invented facts); `task-start` returns skill references not bodies; prompt outcome report (tools used, changes, QA, rollback, unresolved facts).

## Phase 11 — Documentation and release truth (continuous + final pass)

> Already partially done (see `[x]` in the source plan). Remaining:

- [ ] Generate from single sources: current version, plugin ability count, Direct tool count, MCP endpoints, client snippets, requirements — consumed by README/docs.
- [ ] Rewrite `docs/installation.md` around Connection Center + capability tiers; update `README.md`, `docs/onboarding.md`, `docs/install-prompts.md`, `docs/admin/*`, `docs/getting-started/*`, companion README (generated counts).
- [ ] New docs: Elementor native policy, Gutenberg/FSE native policy, blueprint/brand-kit v2, transactions, visual QA (Playwright), recovery.
- [ ] Extend `scripts/check-docs-freshness.mjs`: generated endpoint checks, config example parsing (JSON/TOML/bash), forbidden competitor-name enforcement outside provenance docs, verified-client-versions table check.

Acceptance: README, plugin metadata, companion package, tag, changelogs, release note, ZIP, TGZ all agree; every copied config parses; freshness gate covers the new checks.

## Phase 12 — Release verification and benchmarking (last)

> First task: write `docs/plans/phase-12-release-verification.md`, then execute.

- [ ] Clean plugin ZIP (production deps only) + companion TGZ + visual TGZ; checksums + archive content verification.
- [ ] wp-env fixtures for WP 6.7 / 6.9 / 7.0 (extends Phase 0 Task 6 scaffold): fresh install each; upgrade from alpha.71 and alpha.72 without data loss.
- [ ] Client connection smoke matrix (manual evidence table for GUI clients; CLI clients scripted where possible).
- [ ] Elementor V3/V4 fixtures, Gutenberg/FSE fixtures, full `e2e:admin-ui` Playwright matrix, security/dependency/provenance/packaging reviews.
- [ ] Benchmarks: startup latency, tools/list tokens, task-start tokens, calls per workflow, write/readback success, rollback success, visual defects — recorded against the Phase 0 baseline artifacts. External comparisons only with reproducible public scenarios; everything else labeled vendor claims. Publish limitations + experimental surfaces.

---

## 7. Verification commands (every phase gate)

```bash
cd /Users/cosminiviteb/Personal/stonewright-wp-mcp/plugin
composer install && composer docs:matrix && composer test && composer phpstan \
  && composer phpcs && composer security:audit && composer dependencies:audit \
  && composer provenance:lint && composer tokens:measure

cd ../companion
npm install && npm run typecheck && npm run lint && npm test \
  && npm run tokens:measure && npm run build

cd ../visual
npm install && npm run typecheck && npm test && npm run build

cd ..
node scripts/check-docs-freshness.mjs
git diff --check
```

Gates added by this plan (initially missing, created in the phase noted):

| Gate | Created in | Command |
|---|---|---|
| `contracts:compat` | Phase 0 | `composer contracts:compat` + companion `npm run contracts:compat` |
| `e2e:admin-ui` | Phase 0 (scaffold) → Phase 9 (full) | `cd e2e && npx playwright test` |
| companion lint in CI | Phase 0 | `npm run lint` |
| `docs:generate` / extended drift checks | Phase 11 | freshness script extensions |
| `clients:validate` | Phase 1 | config parser/tokenization fixtures |
| `e2e:connection` | Phase 1 | loopback self-test + doctor tests |
| `e2e:direct` | Phase 8 | clean-machine Direct suite |
| `e2e:elementor` / `e2e:gutenberg-fse` | Phases 4/5 | renderer fixture suites |
| `package:verify` | Phase 12 | ZIP/TGZ smoke install |

## 8. PR sequence

1. `fix/alpha72-baseline-lint-doc-truth` (Phase 0)
2. `feat/connection-center-e2e` (Phase 1) — `feat/oauth-connected-clients` (1B) whenever ready
3. `feat/bootstrap-tool-surface` (Phase 2)
4. `feat/designspec-v2-native-policy` (Phase 3)
5. `feat/elementor-precision-transactions` (Phase 4)
6. `feat/gutenberg-fse-precision` (Phase 5)
7. `feat/blueprints-brand-kits-v2` (Phase 6)
8. `feat/wordpress-workflow-composites` (Phase 7)
9. `feat/direct-mode-productization` (Phase 8)
10. `feat/admin-premium-shell` (Phase 9 — may ship as a short stack of PRs per IA/tokens/a11y/perf)
11. `feat/prompt-library-evals` (Phase 10)
12. `docs/release-truth-generation` (Phase 11)
13. release PR (Phase 12)

Every PR: starts from current `main`; lists changed abilities; states permission/backup/token/validator/audit gate changes; red tests first; contracts preserved (Phase 0 tests enforce this mechanically); docs in the same PR; Playwright evidence for UI changes; states which public docs changed or why none needed to; stops if any release gate fails.

## 9. Definition of done

Stonewright is "premium" only when all hold: setup proves a real authenticated MCP task start; default surface is compact; full existing functionality remains; Elementor + Gutenberg/FSE use native capabilities first; high-level writes are transactional and recoverable; blueprints/brand kits produce meaningful native systems; UI is keyboard-complete, responsive, polished in light/dark/system and Playwright-verified; prompts are evaluated, not just well-written; docs are generated from truth and cannot drift; every release artifact passes clean-install and upgrade tests.
