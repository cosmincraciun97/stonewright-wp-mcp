# Stonewright self-improvement, audit, and critical-write repair plan

**Status:** Plan only. Do not implement changes during this planning task.

**Date:** 2026-07-23

**Repository:** `/Users/cosminiviteb/Personal/stonewright-wp-mcp`

**Baseline branch inspected:** `codex/audit-memory-browser-rules`

**Baseline commit inspected:** `8efdfc7`

**Live site inspected read-only:** `https://www.transavia.ro`

## 1. Objective

Repair Stonewright so one agent mistake cannot silently become a live-site
critical error, explicit user corrections cannot land in the wrong memory
store, recurring failures produce useful verified learning, and audit rows
describe the real effect of a mutation instead of only whether a PHP snippet
returned.

This is an incremental repair over the alpha.80 work already present in
`8efdfc7`. Do not reimplement the existing canonical rules, verified learning
receipt, central REST audit, responsive isolation, or exact pagination.

The repair must work in:

- Plugin mode.
- Direct mode.
- Plugin-backed sites accessed from a Direct-capable companion.
- Local Direct sites with no plugin.

## 2. Incident boundary

### 2.1 Confirmed critical failure

The Newsroom implementation appended invalid PHP to the active child theme
`functions.php`. The written file contained bare `obfuscated = ...` statements
without a `$` variable prefix. The `php-execute` snippet itself parsed and
returned successfully because its job was only to write a string. The new
`functions.php` failed later, during a separate WordPress bootstrap.

This distinction is the root bug:

- **Execution success:** the runtime snippet returned.
- **Mutation success:** bytes reached the file.
- **Semantic success:** the resulting PHP file was valid.
- **Site success:** a fresh WordPress request booted with the new file.

Current audit records only the first condition reliably for this path.

### 2.2 Live Chrome evidence captured on 2026-07-23

Authenticated Stonewright admin pages were inspected in Chrome.

| Surface | Observed state |
|---|---|
| Plugin version | `1.0.0-alpha.80` |
| Stonewright mode on live Transavia | `development` |
| Audit total | 269 rows, 6 pages |
| Error rows | 37 |
| Blocked rows | 1 |
| Recurring-error cards | 10 |
| Memory rows | 11 total |
| Memory types | Feedback 11, Project 0, User 0, Reference 0 |
| Custom Instructions | Disabled and empty |

Error inventory:

| Ability + code | Count |
|---|---:|
| `php-execute` + `stonewright_php_execute_failed` | 11 |
| `php-execute` + `stonewright_php_elementor_raw_write_blocked` | 9 error + 1 blocked |
| `elementor-v3-batch-mutate` + `stonewright_v3_architecture_mismatch` | 3 |
| `elementor-v3-update-element` + `stonewright_elementor_settings_invalid` | 3 |
| `elementor-v3-update-element` + `stonewright_write_failed` | 2 |
| `elementor-v3-remove-element` + `stonewright_elementor_settings_invalid` | 2 |
| `elementor-v3-add-widget` + `stonewright_write_failed` | 2 |
| `elementor-v3-add-widget` + `stonewright_elementor_settings_invalid` | 2 |
| `elementor-v3-add-widget` + `stonewright_known_widget_requires_dedicated_ability` | 1 |
| `elementor-v4-update-node` + `stonewright_element_not_found` | 1 |
| `php-execute` + `stonewright_php_parse_error` | 1 |

The 11 generic `stonewright_php_execute_failed` rows split into:

- 4 transport/backslash parse failures.
- 3 runtime Elementor raw-write blocks.
- 2 read-only option-write blocks.
- 1 missing `SettingsValidator` class.
- 1 invalid static `Container::instance()` call.

Incident-correlated rows:

| ID | Result | Decisive evidence |
|---|---|---|
| 264 | ERROR | Snippet parse failure, unexpected backslash |
| 267 | BLOCKED | Raw Elementor write correctly blocked |
| 269 | OK | `read_only:false`, redacted code length 8,162 bytes, 3 ms, result `ok` |

Row 269 strongly correlates with the toxic theme append, but the audit row does
not record the target path, patch type, lint result, backup, fresh-bootstrap
check, or rollback. That missing evidence is itself a P0 defect.

### 2.3 Connection evidence

- Chrome was authenticated to live Transavia.
- Claudeus could address the `transavia` alias, but WordPress Site Health still
  reported the authorization-header warning as `recommended`.
- Stonewright companion was configured to
  `http://transavia-local.local/wp-json/mcp/stonewright`, not live Transavia.
- The local endpoint was disconnected.
- `startup_ready:false`.
- `stonewright-task-start`, `stonewright-context-bootstrap`, and
  `stonewright-skills-get` were missing.
- Bootstrap expected 22 client-visible tools, exceeding the strict 20-tool
  budget reported by the companion.

No implementation task may write to WordPress until target identity and the
canonical task-start surface are repaired.

## 3. Existing alpha.80 work to preserve

The following foundations already exist and must be extended, not replaced:

- Canonical permanent operating rules in Plugin and Direct modes.
- Verified `stonewright-learning-record` receipts after readback.
- Legacy learning input compatibility.
- Exact audit row counts and pagination.
- Audit insert-failure detection.
- Central audit for Stonewright REST mutations.
- `ok`, `error`, and `blocked` audit statuses.
- Request correlation and REST/ability deduplication.
- Explicit user/project learning priority over audit feedback.
- Native-first guidance.
- Deterministic method ordering:
  `typed_api → editor_command_bus → admin_form → browser_ui`.
- Elementor breakpoint isolation.
- Separate editor and frontend verification-tab guidance.

## 4. Gaps still open

### P0

1. `php-execute` can write active theme/plugin PHP through filesystem functions.
2. `theme-file-patch` writes PHP without validating the complete candidate file.
3. `theme-file-patch` does not perform a fresh WordPress bootstrap smoke check.
4. File writes are not atomic transactions with automatic rollback.
5. Audit can mark a dangerous mutation `OK` without verified effect.
6. Live production host is running Stonewright in `development` mode.
7. Missing `stonewright-task-start` does not technically prevent every possible
   write path.

### P1

1. Direct learning silently falls back to `_global` when site resolution throws.
2. Direct response scope can say `project` while physical storage is
   `_global.jsonl`.
3. Plugin and Direct memory stores have no explicit authoritative-store policy.
4. Direct local learning is invisible in the WordPress Memory UI, but the
   receipt does not make that limitation prominent enough.
5. Audit feedback stores the failure, not a verified repair.
6. Expected safety blocks can become noisy automatic “learning”.
7. `MethodRouter` exists, but it is not an enforcement boundary for write
   abilities.
8. Native-first and approval rules exist in instructions, but theme/runtime
   abilities do not enforce an operator approval grant.

### P2

1. Audit UI lacks effect verification, rollback, target, and incident fields.
2. Error signatures depend on message excerpts and can fragment equivalent
   causes.
3. Direct audit and Plugin audit do not expose one fully aligned event schema.
4. Existing automatic feedback rows are generic and can outlive the real fix.

## 5. Non-negotiable architecture decisions

### 5.1 `php-execute` never edits code files

Keep full PHP runtime access first-class for runtime inspection and WordPress
API work. Permanently block writes from `php-execute` to:

- Active or inactive theme files.
- Plugin files.
- Must-use plugin files.
- WordPress core files.
- Any PHP file under `ABSPATH`, `WP_CONTENT_DIR`, plugin roots, or theme roots.

The block applies in all Stonewright modes and cannot be bypassed by a
confirmation token or a custom-code approval grant.

Code-file writes must use a typed file ability with candidate validation,
backup, atomic replacement, fresh-bootstrap verification, readback, audit, and
rollback.

### 5.2 Custom code needs a real operator grant

Instruction text is not enforcement. Add a server-side, short-lived,
single-use custom-code grant issued from authenticated wp-admin.

The grant is bound to:

- User ID.
- Site identity.
- Task/change-set ID.
- File path or operation class.
- Candidate `after_sha256`.
- Allowed language: PHP, CSS, JS, or HTML.
- Maximum changed bytes.
- Expiry.

An MCP client may request the grant requirement and receive the approval URL,
but it must not mint the grant itself.

### 5.3 Direct mode never silently changes memory scope

If a caller supplies a site alias and resolution fails, return a structured
error. Do not fall back to `_global`.

Global memory requires an explicit `global:true`. A `scope:project` or
`scope:user` request cannot write `_global.jsonl`.

### 5.4 One authoritative memory store per task

At `stonewright-task-start`, select and report one memory backend:

- `plugin-site`: WordPress DB; visible in Stonewright Memory UI.
- `direct-site-local`: local companion JSONL scoped to a resolved site alias.
- `direct-global`: allowed only after explicit global intent.

If the Stonewright plugin memory API is reachable for the selected site, it is
the authoritative store. Direct local fallback is allowed only for a genuinely
pluginless target and must be reported as local-only.

Never dual-write automatically. Never claim a local record appears in the site
Memory UI.

### 5.5 Self-improvement learns verified repairs, not raw failures

A failure creates an unresolved incident/candidate. It does not immediately
become an active behavioral rule.

Promote a durable learned rule only after one of:

- The user explicitly states the correction.
- A later operation proves the repair with successful readback/verification.
- A maintainer approves a product-level rule.

Stonewright may improve future agent behavior through memory, skills, hard
stops, and reviewed product changes. It must never auto-edit its own production
source based only on an error log.

### 5.6 Audit success means verified effect

For mutation abilities, `ok` requires:

- Execution completed.
- Intended resource changed or correctly remained unchanged.
- Readback matched the candidate.
- Required semantic validator passed.
- Required runtime/frontend smoke passed.
- No rollback was required.

If execution succeeds but effect verification fails, audit status is `error`,
with rollback fields describing recovery.

## 6. Implementation phases

Execute strictly in order. Test first in every phase. Check boxes in this file
only during the later implementation task.

### Phase 0 — Freeze evidence and restore canonical target

Primary areas:

- Companion setup/profile state.
- Live Transavia Stonewright Setup.
- Redacted incident fixture.

Checklist:

- [ ] Create a new topic branch from commit `8efdfc7` or from its merged
  successor. Suggested name:
  `codex/self-improvement-audit-critical-repair`.
- [ ] Do not touch the untracked `docs/plans.bak-local/` directory.
- [ ] Save redacted regression fixtures for audit IDs 264, 267, and 269.
- [ ] Do not save raw PHP, credentials, cookies, authorization headers, cPanel
  URLs, or application passwords.
- [ ] Repair the selected Stonewright target through supported setup.
- [ ] Require explicit site selection when multiple aliases exist.
- [ ] Reload the MCP client.
- [ ] Verify `stonewright-task-start` is visible.
- [ ] Verify `connected:true`, `startup_ready:true`, and no missing startup
  tools.
- [ ] Call `stonewright-task-start` for the repair goal.
- [ ] Verify target URL, site alias, backend, WordPress environment type,
  Stonewright mode, and active tool profile through a harmless read.
- [ ] Reduce/restructure Bootstrap exposure so canonical startup stays inside
  the client tool cap.
- [ ] On live production, require `production-safe` before any implementation
  write. Changing the live mode remains an explicit operator action.

Acceptance:

- One unambiguous target.
- Canonical task start works.
- No implementation write can begin on stale local or unintended live state.

### Phase 1 — Add failing incident regression tests

Primary files:

- `plugin/tests/Unit/Runtime/PhpExecuteTest.php`
- `plugin/tests/Unit/Themes/ThemeFileAbilitiesTest.php`
- new `plugin/tests/Unit/Themes/ThemeWriteTransactionTest.php`
- new `plugin/tests/Unit/Security/CustomCodeGrantTest.php`
- `companion/tests/direct-tools-selfimprove.test.ts`
- `companion/tests/direct-memory-store.test.ts`
- `plugin/tests/Unit/Security/AuditLogCoverageTest.php`

Required failing tests:

- [ ] `php-execute` attempting `file_put_contents()` on active
  `functions.php` returns `stonewright_php_code_file_write_blocked`.
- [ ] Indirect variants are blocked: `fopen`/`fwrite`, `copy`, `rename`,
  `unlink`, `WP_Filesystem`, `$wp_filesystem`, callable indirection, and
  reflection.
- [ ] Invalid full-file PHP candidate is rejected before any target write.
- [ ] Candidate valid as a fragment but invalid in the complete
  `functions.php` is rejected.
- [ ] Candidate hash differs from approval-grant hash and is rejected.
- [ ] Loopback bootstrap failure rolls back the original file.
- [ ] Rollback readback must match the original hash.
- [ ] Unknown Direct site alias fails; `_global` remains untouched.
- [ ] Missing Direct site for project/user scope fails unless a single
  unambiguous default is bound by task-start.
- [ ] Plugin-backed Direct learning writes to site memory, not local JSONL.
- [ ] Local-only Direct receipt explicitly says it is not visible in wp-admin.
- [ ] Audit `ok` is impossible when semantic verification is missing.
- [ ] Repeated safety blocks do not generate active project/user memory.

Acceptance:

- Tests reproduce the incident and wrong-store memory defect before product code
  changes.

### Phase 2 — Bind session, target, backend, and memory store

Primary files:

- `companion/src/direct/tools/self-improve.ts`
- `companion/src/direct/writes.ts`
- `companion/src/direct/sites-config.ts`
- `companion/src/direct/registry.ts`
- `companion/src/wordpress-mcp.ts`
- `plugin/includes/Abilities/System/TaskStart.php`
- `plugin/includes/Abilities/Memory/LearningRecord.php`
- `plugin/includes/Context/ContextBuilder.php`

Checklist:

- [ ] Introduce a target-context receipt from `stonewright-task-start`.
- [ ] Receipt fields: backend, site alias, normalized URL, site fingerprint,
  environment type, Stonewright mode, memory backend, memory visibility, tool
  profile, expiry, and context token.
- [ ] Require the bound target context for learning and every write.
- [ ] Reject writes after target, alias, backend, mode, or environment changes.
- [ ] Replace `resolveSelfImproveScope()` catch-all fallback with typed errors.
- [ ] Permit `_global` only with `global:true`.
- [ ] Make physical storage scope match response scope.
- [ ] If Plugin memory is available, route learning to the site store.
- [ ] If Plugin memory is unavailable because the site is pluginless, use a
  resolved per-site Direct store.
- [ ] If Plugin memory should be available but auth/connectivity fails, return
  an error; do not silently fall back local.
- [ ] Add receipt fields:
  `site_alias`, `site_fingerprint`, `memory_backend`, `visibility`,
  `memory_type`, `storage_ref`, `verified`.
- [ ] Make task-start read from the same authoritative store used by
  learning-record.
- [ ] Verify the saved correction appears in the next fresh task-start.

Acceptance:

- A project correction can never land in `_global` by accident.
- Receipt states exactly where the rule lives and where it is visible.

### Phase 3 — Enforce native-first and operator-approved custom code

Primary files:

- `plugin/includes/Core/MethodRouter.php`
- `plugin/includes/Core/McpUsePolicy.php`
- `plugin/includes/Core/AgentInstructions.php`
- new `plugin/includes/Security/CustomCodeGrant.php`
- new admin approval controller/UI
- custom-code-capable abilities
- `companion/src/direct/permanent-rules.ts`
- `skills/agent-operating-rules/SKILL.md`

Checklist:

- [ ] Add canonical rule:
  custom PHP/CSS/JS/HTML requires explicit operator approval after a proven
  native gap.
- [ ] Add this rule to Plugin/Direct/skill parity tests.
- [ ] Integrate `MethodRouter` into real task/preflight output; remove its
  current status as an isolated helper used only by tests.
- [ ] Classify operations as native, custom code, security-sensitive code, or
  render override.
- [ ] Complete the native Elementor/WordPress phase before exposing a
  custom-code write path.
- [ ] Require structured `native_gap` evidence for CSS/JS/HTML proposals.
- [ ] Require a custom-code grant for PHP/CSS/JS/HTML writes.
- [ ] Require a narrower high-risk grant for
  `elementor/widget/render_content`, global render filters, bootstrap hooks, and
  active-theme PHP.
- [ ] Return an unapplied diff, risk, test plan, and rollback plan when approval
  is missing.
- [ ] Ensure typed Elementor controls, editor command bus, and WordPress APIs
  remain usable without a custom-code grant.
- [ ] Prevent generic CSS targeting `.e-con`, `.elementor-*`, or other global
  layout wrappers unless explicitly approved as a reviewed exception.

Acceptance:

- The workaround used in the incident cannot execute without a proven native
  gap and a real operator grant.
- Native Elementor layout remains the default path.

### Phase 4 — Permanently block code-file writes through `php-execute`

Primary files:

- `plugin/includes/Abilities/Runtime/PhpExecute.php`
- new `plugin/includes/Security/ProtectedFilesystemWriteGuard.php`
- existing `plugin/includes/Security/ProtectedElementorWriteGuard.php`
- `plugin/includes/Security/RemediationHints.php`

Checklist:

- [ ] Separate Elementor-document protection from filesystem protection.
- [ ] Detect direct and indirect file mutation APIs before eval.
- [ ] Resolve literal and computed paths when possible.
- [ ] Install runtime protection for WordPress filesystem APIs.
- [ ] Block theme/plugin/core/PHP code targets even when the path is assembled
  dynamically.
- [ ] Fail closed when a write path under WordPress roots cannot be classified.
- [ ] Preserve legitimate runtime reads and non-file WordPress API work.
- [ ] Return `retryable:false`.
- [ ] Return the exact typed replacement:
  `stonewright-theme-file-patch`, sandbox draft flow, or unavailable.
- [ ] Audit the blocked target class and attempted operation without storing raw
  source or absolute sensitive paths.
- [ ] Add a permanent remediation hint: never retry the same file write through
  `php-execute`.

Acceptance:

- Incident path produces `BLOCKED`, never `OK`.
- Full runtime PHP remains available for supported runtime work.

### Phase 5 — Make theme-file writes transactional

Primary files:

- `plugin/includes/Abilities/Themes/ThemeFilePatch.php`
- `plugin/includes/Abilities/Themes/ThemeFilePaths.php`
- new `plugin/includes/Security/PhpSyntaxValidator.php`
- new `plugin/includes/Security/ThemeWriteTransaction.php`
- new rollback/readback helpers
- packaging rules for the chosen PHP parser

Checklist:

- [ ] Require `dry_run:true` first for PHP, CSS, and JS changes.
- [ ] Dry run returns bounded unified diff, changed-line count, changed bytes,
  before hash, candidate hash, validator results, risk class, and approval
  requirement.
- [ ] Bind the custom-code grant to the candidate hash.
- [ ] Validate the complete candidate file, not only inserted content.
- [ ] Package a production-safe PHP parser with the plugin or implement an
  equally deterministic in-process validator. Do not depend on shell `php -l`
  being available.
- [ ] Validate PHP version compatibility against the site runtime.
- [ ] Add CSS and JS syntax checks appropriate to packaged runtime limits.
- [ ] Backup before write.
- [ ] Write a temp file in the same directory.
- [ ] Apply safe permissions.
- [ ] Atomically replace the target.
- [ ] Read back and verify the exact candidate hash.
- [ ] Perform a fresh loopback WordPress bootstrap against a minimal health URL.
- [ ] For frontend/theme changes, also smoke the affected public URL when
  supplied.
- [ ] If any check fails, restore the original bytes atomically.
- [ ] Verify rollback hash and a second fresh bootstrap.
- [ ] If rollback fails, emit P0 incident data and the exact backup recovery
  reference.
- [ ] Never return success before readback and smoke checks pass.
- [ ] Add change-size budgets and reject giant append blobs by default.
- [ ] Prefer marker-bounded replacements over unrestricted append.
- [ ] Add an explicit restore ability for Stonewright-owned backups with the
  normal production-safe confirmation gate.

Acceptance:

- Invalid candidate never reaches `functions.php`.
- Fresh-bootstrap failure self-recovers within the original request.

### Phase 6 — Upgrade audit from call log to effect log

Primary files:

- `plugin/includes/Security/AuditLog.php`
- `plugin/includes/Abilities/AbilityKernel.php`
- `plugin/includes/Core/RestRoutes.php`
- `plugin/includes/Admin/AuditLogPage.php`
- Direct audit schema and UI/docs

Schema additions:

- `event_type`
- `operation_class`
- `resource_type`
- redacted/logical `resource_ref`
- `change_set_id`
- `request_id`
- `parent_request_id`
- `execution_status`
- `verification_status`
- `rollback_status`
- `before_sha256`
- `after_sha256`
- `changed_bytes`
- `validator_summary`
- `smoke_summary`
- `error_code`
- `cause_key`
- `duration_ms`
- `backend`
- `site_fingerprint`
- `mode`

Checklist:

- [ ] Add a backward-compatible audit schema migration.
- [ ] Preserve all existing rows.
- [ ] Require mutating abilities to report effect verification metadata.
- [ ] Keep `ok`, `error`, and `blocked` as top-level compatibility statuses.
- [ ] Represent rollback separately:
  `not_needed`, `succeeded`, or `failed`.
- [ ] An execution success followed by verification failure records `error`.
- [ ] Add filters for backend, operation class, verification, rollback, severity,
  and change-set ID.
- [ ] Add an “Incidents” view for verification failures and rollbacks.
- [ ] Show target/resource information only in redacted logical form.
- [ ] Ensure one event per mutation; linked sub-events use parent request IDs
  instead of duplicate top-level rows.
- [ ] Align Direct audit JSONL fields with Plugin audit fields.
- [ ] Keep secrets and raw code redacted.
- [ ] Add site-health/admin diagnostics when audit persistence fails.
- [ ] Add a visible warning when audit coverage is degraded.

Acceptance:

- An operator can answer: what changed, where, whether it was verified, and
  whether it rolled back.

### Phase 7 — Replace error accumulation with a repair-learning state machine

Primary files:

- `plugin/includes/Security/ErrorPatterns.php`
- `plugin/includes/Security/RemediationHints.php`
- new incident/learning resolution service
- `plugin/includes/Context/ContextBuilder.php`
- `plugin/includes/Abilities/Memory/LearningRecord.php`
- `companion/src/direct/audit.ts`
- `companion/src/direct/tools/self-improve.ts`

States:

1. `observed`
2. `repeated`
3. `blocked_pending_repair`
4. `repair_attempted`
5. `verified_resolved`
6. `promoted_learning`
7. `product_fix_required`
8. `dismissed`

Checklist:

- [ ] Replace message-excerpt signatures with structured `cause_key`.
- [ ] Include ability, stable error code, architecture, schema path, and
  operation class; exclude volatile IDs and timestamps.
- [ ] Classify expected safety blocks separately from agent-caused errors.
- [ ] Do not create active learning for expected blocks.
- [ ] On second identical failure, return hard stop and forbid identical-args
  retry.
- [ ] Store the last safe remediation hint and required next tool.
- [ ] Link the later successful verified operation to the unresolved cause.
- [ ] Promote the resolved recipe only after verification.
- [ ] Explicit user corrections promote immediately after write/readback.
- [ ] Product-level bugs create a maintainer candidate, not a self-edit.
- [ ] Keep audit feedback distinct from user and project learning.
- [ ] Reserve task-start capacity for explicit user/project rules before
  automatic incident warnings.
- [ ] Add expiry/staleness when plugin, WordPress, Elementor, or schema
  fingerprints change.
- [ ] Add conflict resolution when a new verified rule contradicts an older
  rule.

Acceptance:

- Stonewright learns “what fixed it,” not merely “it failed twice.”

### Phase 8 — Repair Memory UI and current automatic feedback

Primary files:

- `plugin/includes/Admin/MemoryInstructionsPage.php`
- Memory table/repository code
- migration tests

Checklist:

- [ ] Display backend/origin, site scope, visibility, source, verification
  state, and last successful retrieval.
- [ ] Separate tabs:
  User, Project, Verified Repairs, Unresolved Incidents, Audit Feedback,
  Reference.
- [ ] Keep permanent product rules out of editable Memory rows.
- [ ] Keep Custom Instructions separate from learned memory.
- [ ] Keep draft skills visible as pending review, disabled by default.
- [ ] Migrate generic alpha.80 audit feedback into unresolved incidents or
  verified repairs without deleting audit history.
- [ ] Replace generic “check inputs and retry” text with exact remediation.
- [ ] Deduplicate equivalent rows.
- [ ] Preserve `Post-deploy smoke` as historical feedback unless an operator
  chooses to remove it.
- [ ] Add export before any destructive memory migration.
- [ ] Add a receipt lookup so an operator can paste a memory ID and see its
  authoritative store.
- [ ] For Direct-local receipts, explain that wp-admin cannot read a local
  companion file; offer explicit export/import, never fake synchronization.

Acceptance:

- A Plugin project correction appears under Project.
- A Direct-local correction is clearly labeled local-only.

### Phase 9 — Harden tool surface and mode safety

Primary files:

- `companion/src/wordpress-mcp.ts`
- `companion/src/setup-profile.ts`
- `companion/src/client-surface-check.ts`
- Plugin task-start/tool-profile code
- Setup diagnostics/admin UI

Checklist:

- [ ] Guarantee task-start and compatibility bootstrap remain gateway tools
  inside strict tool caps.
- [ ] Keep total initial client-visible surface within the declared budget.
- [ ] Do not advertise unavailable Elementor write tools.
- [ ] Require a successful task-start context token for every mutation and
  learning call.
- [ ] Refuse writes when startup tools are missing.
- [ ] Detect stale local-versus-live target mismatch before writes.
- [ ] Show a P0 admin warning when `wp_get_environment_type()` is `production`
  but Stonewright mode is `development`.
- [ ] Default new production installs to a safe onboarding decision instead of
  silently persisting `development`.
- [ ] Keep all three supported modes:
  `development`, `staging`, `production-safe`.
- [ ] Add harmless functional auth verification; do not treat the Site Health
  authorization-header warning alone as proof that authenticated REST is
  unusable.
- [ ] Make `client-surface-check` return one concrete supported recovery path.

Acceptance:

- Missing startup surface creates a hard write stop.
- Live production cannot look like ordinary development without a prominent
  explicit override.

### Phase 10 — End-to-end verification

Run on local/staging first. Live verification remains read-only until the user
approves a bounded canary.

Automated scenarios:

- [ ] Invalid PHP candidate rejected before write.
- [ ] Valid PHP patch passes full-file parse, atomic write, readback, loopback,
  and audit.
- [ ] Simulated fresh-bootstrap 500 triggers rollback.
- [ ] Simulated rollback failure creates a P0 incident.
- [ ] `php-execute` code-file write blocked in all modes.
- [ ] Custom CSS/PHP/JS rejected without operator grant.
- [ ] Grant cannot be reused, broadened, or applied to a different hash/path.
- [ ] Native Elementor mutation works without custom-code grant.
- [ ] V3/V4 architecture mismatch returns the correct typed next step.
- [ ] Explicit Plugin project learning appears in Memory UI and next task-start.
- [ ] Direct unknown-site learning fails without touching `_global`.
- [ ] Pluginless Direct learning returns local-only visibility.
- [ ] Repeated failure creates unresolved incident, not active project memory.
- [ ] Verified repair promotes one deduplicated rule.
- [ ] Audit successful mutation produces exactly one top-level event.
- [ ] Audit verification failure records error + rollback outcome.
- [ ] Audit storage failure is visible.
- [ ] Bootstrap surface stays under tool cap.

Authenticated Chrome checks:

- [ ] Audit filters, exact count, incident view, payload expansion, and
  pagination.
- [ ] Memory tabs and authoritative-store labels.
- [ ] Custom-code approval screen and one-time grant behavior.
- [ ] Production-mode warning.
- [ ] No sensitive path, code, token, cookie, or credential appears in UI.

### Phase 11 — Documentation, release, and handoff

Review/update when affected:

- Root, Plugin, Companion, and Visual READMEs.
- Plugin and Companion changelogs.
- Architecture.
- Security guarantees.
- Audit documentation.
- Memory/self-improvement documentation.
- Direct-mode guide.
- Installation/client guides.
- `docs/install-prompts.md`.
- Tool-surface recovery runbook.
- Skills and examples.
- Roadmap.
- Versioned release notes.

Checklist:

- [ ] Document execution success versus verified mutation success.
- [ ] Document authoritative memory store selection.
- [ ] Document Direct-local visibility limitation.
- [ ] Document code-file block in `php-execute`.
- [ ] Document custom-code approval grant.
- [ ] Document transaction/rollback guarantees and limits.
- [ ] Document that remote break-glass recovery still requires hosting-level
  access if both WordPress and the original transaction are gone.
- [ ] Do not promise SFTP/cPanel recovery without configured hosting authority.
- [ ] Regenerate `docs/ability-truth-matrix.md` with `composer docs:matrix`.
- [ ] Update public API and Direct tool contracts through generators.
- [ ] Run Plugin tests, PHPStan, PHPCS, security audit, and dependency audit.
- [ ] Run Companion install, typecheck, tests, and build.
- [ ] Run Visual tests and build.
- [ ] Run `node scripts/check-docs-freshness.mjs`.
- [ ] Run `git diff --check`.
- [ ] Verify packaged ZIP/TGZ contents.
- [ ] Review diff for secrets, raw incident code, unsupported claims, and
  unrelated user changes.
- [ ] Do not publish, merge, upload, or deploy without explicit approval.

## 7. Required test matrix

| Area | Scenario | Expected result |
|---|---|---|
| Runtime PHP | Write theme PHP through `php-execute` | Permanent block |
| Runtime PHP | WordPress API runtime inspection | Allowed |
| Theme patch | Invalid complete PHP candidate | No target write |
| Theme patch | Valid candidate | Atomic write + verified effect |
| Theme patch | Fresh bootstrap fails | Automatic rollback |
| Theme patch | Rollback fails | P0 incident + recovery reference |
| Approval | Missing custom-code grant | Unapplied proposal |
| Approval | Grant hash/path mismatch | Blocked |
| Approval | Reused grant | Blocked |
| Native first | Native Elementor control exists | No custom code path |
| Target | Task-start live, write local | Blocked |
| Target | Task-start local, write live | Blocked |
| Direct memory | Unknown site | Error; no `_global` write |
| Direct memory | Pluginless resolved site | Per-site local receipt |
| Plugin memory | Project correction | Project row + task-start retrieval |
| Store visibility | Direct local receipt | Clearly local-only |
| Self-improvement | Failure repeats | Hard stop + unresolved incident |
| Self-improvement | Verified repair follows | One promoted repair rule |
| Safety block | Raw Elementor write blocked | No active learning spam |
| Audit | Verified success | One `ok` event |
| Audit | Execution OK, verification fails | `error`, rollback recorded |
| Audit | Audit insert fails | Degraded coverage warning |
| Surface | Startup tool absent | All writes blocked |
| Production | Environment production + mode development | P0 warning |

## 8. Suggested commit sequence

1. `test: reproduce critical theme write and wrong memory scope`
2. `fix: bind task sessions to one target and memory backend`
3. `test: define custom code operator grant contract`
4. `feat: enforce native first and approval gated custom code`
5. `fix: block code file writes in php execute`
6. `test: define transactional theme patch guarantees`
7. `feat: validate atomically write smoke and rollback theme files`
8. `test: define effect verified audit events`
9. `feat: record verification rollback and incident metadata`
10. `test: define verified repair learning lifecycle`
11. `feat: promote resolved repairs without feedback spam`
12. `feat: expose authoritative memory and incident state`
13. `fix: keep canonical startup surface target safe`
14. `docs: refresh safety audit memory and recovery contracts`

Split commits further when review becomes difficult. Never weaken permissions,
backup, validation, confirmation, audit, approval, readback, smoke, or rollback
gates to make a test pass.

## 9. Pull request evidence required

The PR description must include:

- Incident regression IDs and redacted hashes.
- Abilities and REST routes changed.
- Permission, backup, confirmation-token, custom-code grant, validation, audit,
  smoke, and rollback changes.
- Proof that `php-execute` cannot write code files.
- Proof that invalid PHP never reaches the target file.
- Proof of automatic rollback after a simulated fresh-bootstrap failure.
- Proof of one authoritative memory backend per task.
- Proof that unknown Direct site does not write `_global`.
- Proof that Plugin project learning appears in Memory UI and next task-start.
- Proof that automatic errors remain separate from explicit user learning.
- Proof that a verified repair, not a raw failure, is promoted.
- Before/after audit schema and UI evidence.
- Bootstrap tool-count evidence.
- Production-mode warning evidence.
- Full validation command results.
- Public docs changed, or precise reason none changed.

Do not include credentials, cookies, authorization headers, private client
configuration, cPanel session URLs, raw PHP payloads, or unredacted paths.

## 10. Definition of done

Complete only when:

- Canonical task-start works on the intended target.
- Every write is bound to that target and backend.
- Live production runs behind an explicit safe-mode decision.
- `php-execute` cannot mutate WordPress code files.
- Custom code cannot be applied without proven native gap and operator grant.
- Theme-file patches validate the complete candidate before writing.
- Writes are atomic, backed up, read back, fresh-boot tested, and automatically
  rolled back on failure.
- Audit reports verified effect and rollback, not only snippet return status.
- Explicit corrections persist in the intended authoritative memory store.
- Direct mode never silently falls back to global memory.
- Task-start retrieves the correction in a new task.
- Repeated failures create unresolved incidents.
- Only explicit or verified repairs become active learned rules.
- Plugin and Direct behavior passes the full test matrix.
- Documentation, contracts, packages, and release gates pass.
- No live mutation, merge, release, upload, or deployment occurs without
  explicit approval.
