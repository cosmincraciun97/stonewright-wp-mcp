# Stonewright audit, memory, browser, responsive, and Figma implementation plan

**Status:** Plan only. Do not implement from this document in the planning task.

**Date:** 2026-07-22

**Target repository:** `/Users/cosminiviteb/Personal/stonewright-wp-mcp`

**Current branch observed during research:** `main`. The implementing agent must create a topic branch before changing code.

## 1. Objective

Make Stonewright reliable in five connected areas:

1. Audit every Stonewright mutation truthfully and make failures diagnosable.
2. Make explicit user learning persist in both Plugin and Direct modes, with a verifiable receipt.
3. Ship the requested operating rules as invisible product defaults, not editable Memory rows.
4. Route work through the fastest precise interface: typed APIs first, editor command bus second, browser UI last.
5. Protect Elementor breakpoints and make multi-section Figma implementation section-scoped and verifiable.

This plan deliberately separates two concepts:

- **Permanent product rules:** code-owned defaults, always injected, not shown in the Memory page.
- **Learned user/project facts:** stored by `stonewright-learning-record`, visible and manageable in Memory.

Do not fake “automatic memory.” The WordPress plugin cannot read chat text. It can persist a correction only after the client calls a Stonewright memory tool. The reliable contract is: detect explicit remember intent, call the tool, read it back, and return a receipt.

## 2. Research findings

### 2.1 Live Transavia checks in authenticated Chrome

#### Audit page

URL checked: `https://www.transavia.ro/wp-admin/admin.php?page=stonewright-audit-log`

The page renders, filters, paginates, expands payloads, and shows recurring error cards. The visible data was dominated by `stonewright/php-execute` calls and included recurring Elementor write-block, parse, architecture, and validation errors.

The broken part is the coverage promise. The page says every write ability and REST call is logged, but the implementation only records calls that explicitly pass through `AbilityKernel::audit()` or call `AuditLog::record()` themselves. Many mutable Stonewright REST routes do neither.

Root evidence:

- `plugin/includes/Security/AuditLog.php`: storage and query implementation.
- `plugin/includes/Abilities/AbilityKernel.php`: ability audit wrapper.
- `plugin/includes/Admin/AuditLogPage.php`: UI and overbroad coverage copy.
- `plugin/includes/Core/RestRoutes.php`: unaudited mutation handlers for settings, memory, instructions, skills, application-password actions, and other admin operations.

Additional defects:

- `AuditLog::record()` does not check the result of `$wpdb->insert()`, so storage failure can be silent.
- Pagination infers a next page from a full page of 50 rows instead of using an exact total.
- Status vocabulary is inconsistent with Direct mode, which can distinguish blocked operations.

#### Memory page

URL checked: `https://www.transavia.ro/wp-admin/admin.php?page=stonewright-memory`

Observed state:

- 11 learned entries.
- User: 0.
- Feedback: 11.
- Project: 0.
- Custom Instructions disabled and empty.

The listed items were automatic feedback/audit-derived rules, not the user’s explicit instruction. This confirms the requested conversation instruction did not complete the learning-record flow.

Likely causes found in code:

- Plugin mode and Direct mode expose the same MCP tool name with incompatible input schemas.
  - Plugin `LearningRecord.php`: `topic` + `correction`, optional `scope` and evidence.
  - Direct registry: `text`, optional `kind`, `tags`, and `draft_skill`.
- Instructions tell the client to call learning-record after a correction, but there is no receipt/readback requirement.
- Plugin task context reads a bounded recent set and selects a small scored subset. Audit-generated feedback can crowd contextual results.
- Custom Instructions are a separate feature. Their disabled state must not control permanent product defaults.

#### Elementor editor

The live editor for page 8104 exposes responsive tabs in the top toolbar with stable contracts:

- `data-testid="switch-device-to-desktop"`
- `data-testid="switch-device-to-laptop"`
- `data-testid="switch-device-to-tablet"`
- `data-testid="switch-device-to-mobile"`

They use `role="tab"` and `aria-selected`. The agent must use these in-editor device controls when UI interaction is truly required. It must not resize the entire Elementor editing window to simulate an editor breakpoint.

The list is evidence from this site, not a universal hardcoded list. Discover the available toolbar tabs at runtime because user-configured breakpoints can differ.

### 2.2 Existing foundations worth keeping

- `plugin/includes/Core/McpUsePolicy.php` already contains permanent operating rules and is the correct Plugin-mode home for invisible defaults.
- `plugin/includes/Core/AgentInstructions.php` injects those rules into task context.
- `skills/agent-operating-rules/SKILL.md` mirrors the rules for agent-facing documentation.
- `companion/src/direct/permanent-rules.ts` is the Direct-mode equivalent but currently has fewer rules.
- `companion/src/direct/memory-store.ts` writes Direct memory as JSONL under `<state base>/memory/<scope>.jsonl`, normally beneath the Stonewright state directory, with private file permissions.
- `visual/src/elementor-v3/editor-adapter.ts` already uses the Elementor runtime/command bus for structure reads and mutations.
- `visual/src/page-tool-registry.ts` already supports batched calls and transaction/readback/rollback patterns.
- `visual/src/elementor-v3/settings-validator.ts` and `plugin/includes/Elementor/Schema/SettingsValidator.php` already reject some invalid responsive suffixes.
- `skills/design-to-wordpress/` already has evidence-led and per-section guidance, but full-page section splitting is not mandatory enough.

### 2.3 MCP startup blocker discovered during research

The configured companion target was the stale local endpoint `http://transavia-local.local/wp-json/mcp/stonewright`, not the authenticated live site. Status was disconnected, `startup_ready:false`, and the canonical `stonewright-task-start` tool was unavailable.

This plan starts with connection repair. Do not use direct REST, shell scripts, private client config parsing, or source-code workarounds to bypass a missing Stonewright MCP surface.

## 3. Architecture decisions

### 3.1 Permanent rules are code, not Memory rows

Add the requested defaults to the canonical product policy. Keep them invisible in the Memory admin list. Mirror exactly into Direct mode and agent skill text. Tests must fail if the copies drift.

### 3.2 Explicit learning requires a verified receipt

Adopt one canonical, mode-neutral schema for `stonewright-learning-record`, while accepting the old Plugin and Direct inputs for backward compatibility.

Canonical request:

```json
{
  "topic": "short stable topic",
  "correction": "the user-approved rule or fact",
  "scope": "user|project",
  "source": "explicit-user-request",
  "evidence": "optional concise context"
}
```

Canonical response:

```json
{
  "stored": true,
  "backend": "plugin|direct",
  "scope": "user|project",
  "memory_id": "stable identifier",
  "storage_ref": "non-secret logical or resolved reference",
  "verified": true
}
```

`verified:true` is allowed only after the stored entry is read back and its normalized content matches. Never return success from a blind write.

Legacy compatibility:

- Plugin continues accepting `topic` + `correction`.
- Direct continues accepting `text` and maps it deterministically.
- Both modes emit the same canonical response shape.
- Update tool schemas without renaming the public tool.

### 3.3 Audit only Stonewright-owned mutations, completely

Do not globally log every WordPress REST request. That is noisy and risks capturing unrelated plugin data. Audit all state-changing requests under the Stonewright namespace, plus Stonewright abilities and security events.

Centralize the REST mutation audit rather than adding dozens of ad-hoc calls. Prevent duplicate rows when a REST route delegates to an already-audited ability.

### 3.4 Method routing ladder

Use this deterministic order:

1. **Typed Stonewright ability or native WordPress/Elementor data API** for supported reads and writes.
2. **Elementor editor command bus** for editor-only state or operations unavailable through typed backend abilities. Batch commands; validate live schemas; read back; roll back on mismatch.
3. **Authenticated native admin form request** for a supported WordPress admin setting that has no API.
4. **Playwright locator interaction** only for genuinely UI-only workflows and visual verification.

Never use DOM mutation through browser evaluation as a shortcut. It changes pixels without reliably changing application state.

Playwright’s direct request context is valid for E2E setup and assertions. It is not a runtime bypass around Stonewright permission, backup, validation, confirmation-token, or audit gates.

### 3.5 Breakpoint writes are scoped transactions

Every design-derived Elementor mutation must declare an allowed responsive scope. A mobile-only input allows mobile-specific keys only. Before and after hashes must prove all other breakpoint values remain unchanged.

If a target control is not responsive in the live widget schema:

- perform no write;
- return `unsupported_responsive_control`;
- name the widget, control, and requested breakpoint;
- tell the user that native breakpoint isolation is unavailable.

Do not silently fall back to a base value or Custom CSS.

## 4. Canonical permanent rules to ship

Use one source of truth or a generated parity check. The final user-facing meaning must be:

1. **Elementor responsive preview:** When editing responsive Elementor settings through the UI, switch the responsive device using the editor’s top-toolbar device tabs. Never resize the whole editor browser window to select an Elementor breakpoint. Discover the site’s active breakpoints and verify the selected tab.
2. **Separate verification tab:** Keep the Elementor editor tab dedicated to editing. Open or reuse a separate frontend tab for rendered verification. A verification viewport may be resized; the editor window may not.
3. **Figma section isolation:** Treat any Figma page/node containing multiple sections as an ordered section manifest. Capture a screenshot and extract layout, typography, assets, images, colors, and spacing for every section. Implement and verify one section at a time, then run a full-page regression.
4. **Breakpoint isolation:** A design supplied for one breakpoint authorizes changes only to that breakpoint. Preserve every other breakpoint exactly. If the native control is not responsive, make no change and notify the user.
5. **Native-first styling:** Use native Elementor, Gutenberg, or FSE controls before Custom CSS or code. If native implementation is impossible, stop and explain the proven native gap before adding Custom CSS or code.
6. **Fastest safe interface:** Prefer typed Stonewright/native APIs for precise supported work, the editor command bus for editor-only work, and browser UI only when no safe programmatic interface exists. Never replace permission, backup, validation, confirmation, audit, or readback gates for speed.
7. **Verified learning:** When the user explicitly asks Stonewright to remember a correction or stable preference, call `stonewright-learning-record` in the active mode, read it back, and report its identifier and scope. Never claim it was remembered without verification.

## 5. Implementation phases

Each phase is test-first. Commit separately. Do not combine audit, memory, and visual mutations into one giant commit.

### Phase 0 — Restore the canonical live MCP surface

Goal: make live verification possible before modifying behavior.

Checklist:

- [ ] Create a topic branch from a freshly fetched release-ready base. Suggested name: `codex/audit-memory-browser-rules`.
- [ ] Repair the configured Transavia target through supported Stonewright connection settings. Do not print or commit credentials.
- [ ] Reload/restart the MCP client after changing the target.
- [ ] Confirm `connected:true`, `startup_ready:true`, correct live URL, and zero missing startup tools.
- [ ] Call `stonewright-task-start` once for this goal.
- [ ] Perform a harmless authenticated read to prove the target, identity, mode, and tool profile.
- [ ] Record only redacted connection evidence in the PR.

Acceptance:

- The live target is unambiguous.
- Canonical task start works.
- No workaround runner or direct private REST call exists.

### Phase 1 — Canonical invisible operating rules with Plugin/Direct parity

Primary files:

- `plugin/includes/Core/McpUsePolicy.php`
- `plugin/includes/Core/AgentInstructions.php`
- `companion/src/direct/permanent-rules.ts`
- `skills/agent-operating-rules/SKILL.md`
- `plugin/tests/Unit/Core/AgentInstructionsTest.php`
- relevant companion instruction/parity tests

Checklist:

- [ ] Write failing tests for all seven rules above in Plugin instructions and Direct task start.
- [ ] Add the rules to the canonical Plugin policy.
- [ ] Mirror them into Direct mode.
- [ ] Remove or consolidate contradictory duplicated wording in `AgentInstructions.php`.
- [ ] Add a parity fixture/hash test so Plugin, Direct, and skill copies cannot silently drift.
- [ ] Prove these rules are injected when Custom Instructions are disabled.
- [ ] Prove none is inserted as a row into the Memory store or shown in Memory counts.

Acceptance:

- Plugin and Direct receive equivalent defaults.
- Memory UI remains reserved for learned data.
- Disabling Custom Instructions does not disable product defaults.

### Phase 2 — Reliable self-improvement and schema compatibility

Primary files:

- `plugin/includes/Abilities/Memory/LearningRecord.php`
- `plugin/includes/Context/ContextBuilder.php`
- `companion/src/direct/registry.ts`
- `companion/src/direct/tools/self-improve.ts`
- `companion/src/direct/memory-store.ts`
- `plugin/tests/Unit/Memory/LearningRecordTest.php`
- `plugin/tests/Unit/Memory/MemorySchemaTest.php`
- `companion/tests/direct-memory-store.test.ts`
- `companion/tests/direct-tools-selfimprove.test.ts`
- `companion/tests/direct-selfimprove-e2e.test.ts`
- `companion/tests/direct-tools-contract.test.ts`

Checklist:

- [ ] Freeze the canonical request and response contract described above.
- [ ] Add compatibility parsing for both historical schemas in both modes.
- [ ] Normalize type/scope semantics. Explicit user requests should be identifiable as user-authored learning, not indistinguishable from audit-generated feedback.
- [ ] Add write-then-readback verification before returning success.
- [ ] Return a non-secret storage reference. In Direct mode, document the resolved memory directory without exposing credentials or unrelated home paths.
- [ ] Update task-start instructions: explicit “remember” intent is incomplete until the tool returns `verified:true`.
- [ ] Ensure task-start can retrieve the saved correction on the next task in both modes.
- [ ] Prevent recurring audit-error rules from crowding explicit user/project learning. Reserve contextual capacity for user-authored entries before scoring feedback.
- [ ] Add idempotency: repeating the same normalized correction updates/deduplicates instead of producing spam.
- [ ] Add structured errors for validation failure, write failure, readback mismatch, and unavailable backend.
- [ ] Add live E2E: save a harmless temporary project-scoped rule, verify it appears in Memory with the expected type/scope, retrieve it through task start, then delete only that test record with the required confirmation path.

Acceptance:

- One public schema works in Plugin and Direct modes.
- Success always includes a verified receipt.
- A saved user rule survives a new task and is visible in the correct Memory filter.
- Automatic audit feedback remains distinct from explicit user learning.

### Phase 3 — Truthful, complete Stonewright audit coverage

Primary files:

- `plugin/includes/Security/AuditLog.php`
- `plugin/includes/Abilities/AbilityKernel.php`
- `plugin/includes/Core/RestRoutes.php`
- `plugin/includes/Admin/AuditLogPage.php`
- `plugin/tests/Unit/AbilityKernelAuditTest.php`
- `plugin/tests/Unit/Admin/AuditLogPageTest.php`
- new focused REST audit tests
- Direct audit parity tests where response/status vocabulary changes

Checklist:

- [ ] Inventory every Stonewright REST route by method and mutation status.
- [ ] Add a namespace-scoped audit middleware/wrapper around every POST, PUT, PATCH, and DELETE Stonewright route.
- [ ] Deduplicate rows when a route delegates to an audited ability. Use a request correlation ID or explicit “already audited” context.
- [ ] Record: source, route or ability, method, action, resource identifier, actor, mode, status (`ok`, `error`, `blocked`), error code, timestamp, duration, and redacted payload summary.
- [ ] Preserve append-only behavior and current permission checks.
- [ ] Redact passwords, tokens, cookies, authorization headers, application-password values, and sensitive nested fields before persistence.
- [ ] Check `$wpdb->insert()` results. Surface storage failure through error logging/site-health diagnostics without recursively trying to audit the audit failure.
- [ ] Add exact row counts and deterministic pagination. “Older” must not lead to an empty page merely because the previous page had 50 items.
- [ ] Either make blocked/denied status filterable or normalize it consistently with Direct mode.
- [ ] Change UI copy to the exact supported contract: all Stonewright mutations and audited abilities, not arbitrary WordPress REST traffic.
- [ ] Preserve filters and payload expansion.
- [ ] Add live E2E for one successful mutation, one rejected mutation, one settings mutation, and one memory mutation; prove exactly one row per event.

Acceptance:

- Every Stonewright-owned mutation produces exactly one redacted row.
- Read-only requests do not flood the log.
- Audit storage failure is observable.
- UI copy matches real coverage.

### Phase 4 — Fast execution router and command-bus-first browser fallback

Primary files:

- `plugin/includes/Core/McpUsePolicy.php`
- task-start/tool-profile guidance
- `visual/src/page-tool-registry.ts`
- `visual/src/elementor-v3/editor-adapter.ts`
- V4 adapter equivalents
- visual and companion tool-contract tests

Checklist:

- [ ] Add a deterministic method-selection contract returning chosen method and reason: `typed_api`, `editor_command_bus`, `admin_form`, or `browser_ui`.
- [ ] Maintain an explicit capability matrix rather than guessing from tool names.
- [ ] Keep typed Stonewright abilities as the default for WordPress and Elementor data mutations.
- [ ] For editor-only work, execute through the loaded Elementor command bus/runtime adapter, not through visible clicks.
- [ ] Batch independent editor reads and compatible mutations using the existing registry limit and transaction controls.
- [ ] Require live schema validation, backup where required, readback, and rollback on mismatch.
- [ ] Use browser locators only when the action genuinely requires UI state.
- [ ] For UI fallbacks, prefer accessible role/name or stable test IDs; avoid coordinates, brittle CSS chains, XPath, and fixed sleeps.
- [ ] Never inject a DOM mutation through `evaluate()` as implementation.
- [ ] Add telemetry to the result showing the selected path and fallback reason. Do not include secrets.
- [ ] Add performance-oriented tests proving batched command-bus work does not issue one UI click per control.

Acceptance:

- Supported mutations run without click automation.
- UI fallback is explicit and rare.
- No safety gate is bypassed for speed.

Research basis:

- [Playwright best practices](https://playwright.dev/docs/best-practices)
- [Playwright locators](https://playwright.dev/docs/locators)
- [Playwright API testing](https://playwright.dev/docs/api-testing)
- [WordPress REST API reference](https://developer.wordpress.org/rest-api/reference/)

### Phase 5 — Elementor breakpoint isolation and editor device-tab contract

Primary files:

- `visual/src/elementor-v3/settings-validator.ts`
- `plugin/includes/Elementor/Schema/SettingsValidator.php`
- V4 responsive equivalents
- Elementor typed mutation inputs and schemas
- `visual/tests/elementor-v3-editor-adapter.test.ts`
- `visual/tests/elementor-v4-editor-adapter.test.ts`
- corresponding plugin PHPUnit tests/fixtures

Checklist:

- [ ] Discover active site breakpoints and handles from the live Elementor kit/editor; do not assume four fixed devices.
- [ ] Require `responsive_scope` or `allowed_breakpoints` for breakpoint-specific, design-derived mutations.
- [ ] Validate every mutated setting key against the allowed scope and live responsive schema.
- [ ] For a mobile-only task, reject base, desktop, laptop, tablet, and other breakpoint keys.
- [ ] If the control is not responsive, return a structured no-op and user notice.
- [ ] Hash all non-target breakpoint values before the mutation and assert they are unchanged after readback.
- [ ] Preserve unknown settings and exact widget types. Never full-tree rewrite for one responsive control.
- [ ] Keep V3 and V4 handling separate. Detect architecture before selecting validation semantics.
- [ ] When UI selection is required, discover `role=tab` device controls, select the intended one using its stable contract, and verify `aria-selected=true`.
- [ ] Assert that selecting an Elementor device does not resize the browser window.
- [ ] Add fixtures for custom active breakpoints such as laptop and extra mobile/tablet variants.

Acceptance:

- Mobile-only evidence can alter only the intended mobile keys.
- Wider breakpoint hashes remain identical.
- Non-responsive controls produce no write.
- User-configured breakpoint sets are supported.

Research basis:

- [Elementor responsive editing](https://elementor.com/help/mobile-editing/)
- [Elementor Editor V3 and V4 differences](https://elementor.com/help/what-are-the-differences-between-the-elementor-editor-3-x-and-v4/)

### Phase 6 — Mandatory per-section Figma extraction and implementation

Primary files:

- `skills/design-to-wordpress/SKILL.md`
- `skills/design-to-wordpress/references/figma-mcp-extraction.md`
- `skills/design-to-wordpress/references/pipeline-examples.md`
- design evidence schema/planner files
- relevant skill and planner tests

Checklist:

- [ ] Make a shallow metadata pass mandatory for every supplied Figma node.
- [ ] Detect multiple top-level visual sections and create an ordered section manifest with node ID, name, bounds, and target breakpoints.
- [ ] Capture one screenshot per section, even when the supplied link points to a complete page node.
- [ ] For each section, extract layout model, dimensions, grid/flex behavior, typography, colors, borders, radii, shadows, spacing, assets, image crop/fit, and breakpoint evidence.
- [ ] Store asset provenance and stable identifiers/hashes. Do not infer missing assets from screenshots when source assets are available.
- [ ] Map each section to native Elementor/Gutenberg/FSE structures before considering code.
- [ ] Implement one section per guarded transaction. Verify that section before starting the next.
- [ ] If only a mobile Figma node is supplied, set the allowed mutation scope to mobile. Do not derive or overwrite other breakpoints.
- [ ] If evidence is missing or a native control is unavailable, mark it unavailable and ask instead of inventing values or applying global CSS.
- [ ] Run full-page desktop and relevant responsive regression only after all section checks pass.
- [ ] Add tests that reject a multi-section page plan containing only one combined screenshot/evidence block.

Acceptance:

- Every page section has its own evidence, implementation transaction, and verification result.
- Mobile-only source evidence cannot mutate wider breakpoints.
- Custom CSS appears only behind a documented, user-approved native-gap decision.

### Phase 7 — Separate editor and verification tabs

Primary files:

- browser/Playwright workflow guidance and tests
- Elementor editing skill/reference files
- any reusable visual verification orchestration module

Checklist:

- [ ] Introduce explicit tab roles: `editor_page` and `verification_page`.
- [ ] Keep the authenticated Elementor edit session in `editor_page`.
- [ ] Open/reuse a separate frontend URL in `verification_page` after save/cache invalidation.
- [ ] Resize only `verification_page` for rendered desktop/tablet/mobile checks.
- [ ] Re-check the editor tab URL, unsaved state, selected device tab, and document ID after verification.
- [ ] Prevent a page handle from serving both roles.
- [ ] Replace fixed waits with readiness signals: editor loaded, save complete, network response, rendered selector, or bounded retry.
- [ ] Add an E2E test where mobile verification changes the frontend viewport while the Elementor editor viewport and selected responsive mode stay unchanged.

Acceptance:

- Verification cannot navigate away from or resize the editor tab.
- The editor remains usable and on the intended document after every visual check.

### Phase 8 — Documentation, compatibility, and release gates

Review and update in the same PR when affected:

- root, plugin, and companion READMEs
- plugin and companion changelogs
- architecture and security/audit documentation
- memory/self-improvement and Direct-mode guides
- installation/client guidance and `docs/install-prompts.md`
- design-to-WordPress skill/reference docs
- capability/tool contract documentation
- roadmap and release notes if the change is assigned to a release

Checklist:

- [ ] Document the exact distinction between permanent rules, Custom Instructions, and learned Memory.
- [ ] Document Direct memory’s resolved storage behavior and permissions without exposing secrets.
- [ ] Document audit coverage precisely.
- [ ] Document the routing ladder and breakpoint no-op behavior.
- [ ] Preserve backward compatibility for both learning-record input schemas.
- [ ] Regenerate generated documentation instead of editing it manually. If ability metadata changes, run `composer docs:matrix`.
- [ ] Run Plugin test, static analysis, coding standards, and security/dependency audit commands from `AGENTS.md`.
- [ ] Run Companion install, typecheck, tests, and build.
- [ ] Run Visual package tests/build commands defined by that package.
- [ ] Run `node scripts/check-docs-freshness.mjs`.
- [ ] Run `git diff --check`.
- [ ] Review the final diff for secrets, unsupported product claims, generated files edited by hand, and unrelated user changes.

## 6. Required test matrix

| Area | Required scenario | Expected result |
|---|---|---|
| Permanent rules | Custom Instructions disabled | Product defaults still present; Memory rows unchanged |
| Mode parity | Same task start in Plugin and Direct | Equivalent operating rules |
| Learning schema | Canonical request in both modes | Same response shape, `verified:true` after readback |
| Legacy schema | Historical Plugin and Direct inputs | Accepted and normalized without breaking clients |
| Memory retrieval | New task after learning | Explicit rule returned with correct scope/type |
| Deduplication | Same correction submitted twice | One stable record or deterministic update |
| Audit REST | Each Stonewright mutation route | Exactly one redacted row |
| Audit ability | Success, error, blocked | Correct status/code, no duplicate |
| Audit storage | Forced DB insert failure | Observable diagnostic; no false success |
| Audit pagination | 50, 51, and 100+ rows | Exact navigation and counts |
| Method router | Supported typed mutation | No browser UI action |
| Command bus | Batch editor-only mutations | One guarded batch, readback, rollback support |
| UI fallback | Truly UI-only action | Stable locator, no coordinate/fixed wait |
| Responsive mobile | Mobile-only source | Only mobile keys change |
| Responsive unsupported | Non-responsive native control | No-op plus structured user notice |
| Responsive regression | Other breakpoint data | Byte/value hash unchanged |
| Elementor toolbar | Custom device set | Runtime-discovered tab selected via `aria-selected` |
| Figma page | Multi-section node | One screenshot/evidence/transaction per section |
| Browser tabs | Edit then verify mobile | Separate page; editor window never resized/navigated |

## 7. Suggested commit sequence

1. `test: lock operating-rule parity`
2. `feat: add canonical permanent operating rules`
3. `test: define cross-mode learning receipts`
4. `fix: verify and normalize learning records`
5. `test: expose missing audit coverage`
6. `fix: audit Stonewright mutations centrally`
7. `test: lock method routing and breakpoint isolation`
8. `feat: add fast routing and responsive safeguards`
9. `docs: require section-scoped Figma and separate verification tabs`
10. `docs: refresh memory audit and client guidance`

Split further if a commit becomes hard to review. Never weaken a permission, backup, validator, confirmation-token, audit, or readback gate to make a test pass.

## 8. Pull request evidence required

The PR description must include:

- changed abilities and REST routes;
- whether permission, backup, token, validation, and audit gates changed;
- Plugin/Direct schema compatibility statement;
- proof of invisible permanent-rule injection with Custom Instructions disabled;
- proof of verified memory persistence in both modes;
- before/after audit coverage inventory;
- proof that a mobile-only write leaves other breakpoint hashes unchanged;
- proof that Elementor device switching uses the toolbar without resizing the editor window;
- proof that verification uses a separate tab;
- public documentation changed, or a precise reason none was needed;
- complete validation command results.

Do not include credentials, cookies, authorization headers, private client configuration, or raw sensitive audit payloads.

## 9. Definition of done

This work is complete only when all of the following are true:

- The live MCP target passes canonical startup and a harmless read.
- Permanent rules are always injected in Plugin and Direct modes and remain absent from Memory UI.
- An explicit remember request produces a verified, retrievable receipt in either mode.
- Every Stonewright-owned mutation produces exactly one truthful, redacted audit record.
- Supported WordPress/Elementor work uses typed APIs or the editor command bus without click-by-click automation.
- Elementor UI fallback uses the editor’s own responsive tabs, discovered at runtime.
- Mobile-only work cannot mutate other breakpoints, including when a control lacks responsive support.
- Multi-section Figma nodes are extracted, implemented, and verified section by section.
- Rendered verification occurs in a separate browser tab.
- All required test, build, security, documentation-freshness, and diff checks pass.

