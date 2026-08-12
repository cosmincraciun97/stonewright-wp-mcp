# Verified Learning Closure Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Plugin and Direct mode turn recurring failures into actionable incidents, close only strictly correlated repairs, and promote only verified, scrubbed, reusable lessons.

**Architecture:** `IncidentStore` is the sole Plugin lifecycle authority. A normalized verified-repair receipt validates persisted audit evidence before resolution or learning. `ErrorPatterns` remains a bounded recurrence index. Direct mode mirrors the lifecycle in the private per-site state directory and fails closed when strong correlation or verification is unavailable.

**Tech Stack:** PHP 8.1, WordPress Abilities API, PHPUnit, PHPStan, PHPCS, TypeScript, Node test runner, JSONL/private filesystem state.

## Global Constraints

- Preserve permission, context-token, confirmation, audit, backup, validation, readback, approval, and rollback gates.
- Never infer repair proof from ability name, a generic success, or agent-provided booleans.
- Never persist raw payloads, URLs, usernames, numeric resource IDs, filesystem paths, credentials, tokens, or customer text as learning.
- Never promote authentication failures or expected security blocks.
- Keep Plugin and Direct stores isolated. A site alias may access only its own state.
- Add a test before each behavior change. Confirm the focused test fails for the expected reason before implementation.
- Do not change public ability counts by hand. Regenerate the matrix after registration changes.
- Do not bump a release version or publish from this plan.

---

## Task 1: Freeze the verified-repair contract with failing tests

**Files:**

- Create: `plugin/tests/Unit/Security/VerifiedRepairReceiptTest.php`
- Create: `plugin/includes/Security/VerifiedRepairReceipt.php`
- Reference: `plugin/includes/Security/AuditEvent.php`
- Reference: `plugin/includes/Security/IncidentStore.php`

- [ ] Add tests for `VerifiedRepairReceipt::from_events(array $incident, array $failure, array $success, string $recipe): array|\WP_Error`.
- [ ] Cover required success outcome, `verification_status=verified`, `effect_verified=true`, non-empty equal `change_set_id`, non-empty equal `resource_key_hash`, exact `normalized_path`, expected verifier match, and success time newer than the last failure.
- [ ] Prove same-ability success without correlation fails with `stonewright_repair_uncorrelated`.
- [ ] Prove an empty recipe permits resolution evidence but sets `learning_eligible=false`.
- [ ] Prove expected security blocks and auth events return `stonewright_repair_not_learnable`.
- [ ] Run the focused test and confirm it fails because the class does not exist.

```bash
cd plugin
composer test -- --filter VerifiedRepairReceiptTest
```

- [ ] Implement the class as a pure normalizer/validator. Return only the contract fields from the approved design; do not carry raw event details.
- [ ] Add `scrub_recipe(string $recipe): string|\WP_Error` with a bounded length, minimum specificity, secret-like token detection, URL/domain detection, filesystem-path detection, email/username detection, and numeric resource-ID detection.
- [ ] Reject ambiguous overlength text instead of truncating it.
- [ ] Re-run the focused test.

**Commit:** `test: define verified repair receipts`

---

## Task 2: Make IncidentStore the only lifecycle authority

**Files:**

- Modify: `plugin/includes/Security/IncidentStore.php`
- Modify: `plugin/includes/Core/PluginRegistration.php`
- Modify: `plugin/tests/Unit/Security/AuditEventIncidentTest.php`
- Modify: `plugin/tests/Unit/Security/IncidentLessonSeparationTest.php`
- Create: `plugin/tests/Unit/Security/IncidentRepairLifecycleTest.php`

- [ ] Add failing tests for metadata fields `repair_phase`, `learning_status`, `learning_memory_key`, `repair_receipt_id`, and `learned_at` while keeping incident states limited to `observing`, `open`, `resolved`, and `suppressed`.
- [ ] Test `observing -> open -> resolved`, then a matching recurrence `resolved -> open` with `reopened_count + 1`, cleared resolution metadata, `repair_phase=proposed`, and `learning_status=stale`.
- [ ] Test that legacy rows lacking correlation remain visible and cannot be guessed closed.
- [ ] Run focused tests and confirm the schema/transition assertions fail.

```bash
cd plugin
composer test -- --filter 'IncidentRepairLifecycleTest|AuditEventIncidentTest|IncidentLessonSeparationTest'
```

- [ ] Extend `maybe_install_table()` additively. Preserve existing rows during `dbDelta`; never delete or recreate the table.
- [ ] Add public lookup methods `get(string $incident_id): ?array` and `failure_event(string $incident_id): ?array` or an equivalent bounded persisted-audit lookup needed by the recorder.
- [ ] Add `record_verified_repair(array $receipt): array|\WP_Error`. It must be idempotent by `repair_receipt_id` and update only the canonical row.
- [ ] Add `mark_learning_promoted(string $incident_id, string $memory_key, string $receipt_id): bool` and `mark_learning_stale(string $incident_id): bool`.
- [ ] Keep `resolve(array $event)` as a compatibility entry point, but route its decision through the strict receipt/correlation rules. Missing required keys fail closed.
- [ ] Update `public_row()` so admin/task-start consumers get lifecycle metadata but not raw evidence.
- [ ] Re-run the focused tests.

**Commit:** `refactor: centralize incident lifecycle`

---

## Task 3: Remove independent resolution decisions from ErrorPatterns

**Files:**

- Modify: `plugin/includes/Security/ErrorPatterns.php`
- Modify: `plugin/includes/Abilities/AbilityKernel.php`
- Modify: `plugin/tests/Unit/Security/ErrorPatternsPromotionTest.php`
- Modify: `plugin/tests/Unit/Security/ErrorPatternsTest.php`

- [ ] Replace current promotion tests with failing assertions that same-ability success cannot resolve or teach without a canonical receipt.
- [ ] Test that `ErrorPatterns::observe_verified_repair()` delegates to `IncidentStore` or becomes a compatibility adapter that cannot independently change lifecycle state.
- [ ] Test recurrence aggregation and repair hints still work.
- [ ] Run focused tests and confirm the old permissive path fails the new expectations.

```bash
cd plugin
composer test -- --filter 'ErrorPatternsPromotionTest|ErrorPatternsTest'
```

- [ ] Remove ability-only matching and independent `verified_resolved`/promotion decisions.
- [ ] Keep bounded recurrence counts, normalized error codes, repair hints, dismiss compatibility, and legacy migration.
- [ ] Update `AbilityKernel` so a successful ability result is audited normally; automatic repair processing occurs only when a valid normalized receipt is explicitly present.
- [ ] Confirm ordinary successful writes remain unaffected and cannot create memory.
- [ ] Re-run focused tests.

**Commit:** `fix: require correlated incident closure`

---

## Task 4: Add the typed incident repair recorder

**Files:**

- Create: `plugin/includes/Abilities/Security/IncidentRepairRecord.php`
- Modify: `plugin/includes/Core/AbilityRegistry.php`
- Modify: `plugin/includes/Abilities/System/ToolProfile.php`
- Create: `plugin/tests/Unit/Abilities/IncidentRepairRecordTest.php`
- Modify: `plugin/tests/Unit/Security/AbilitySecurityEnvelopeTest.php`

- [ ] Define failing schema tests for `stonewright/incident-repair-record` with required `incident_id`, `resolution_event_id`, `repair_recipe`, and `context_token`; optional `repair_scope`.
- [ ] Require `Permissions::manage_options()`, standard task context, audit wrapper, and production-safe confirmation behavior because it changes incident/learning state.
- [ ] Test that the ability reads the incident and both persisted audit events server-side. Agent claims such as `effect_verified=true` are ignored unless present in the stored verifier event.
- [ ] Test missing event, mismatched keys/path/verifier, old success, unsafe recipe, duplicate receipt, memory-disabled state, write failure, and readback failure.
- [ ] Test successful output contains `incident_id`, `repair_receipt_id`, `incident_state=resolved`, `learning_status`, `memory_key`, and `verified=true` without raw event data.
- [ ] Run the focused test and confirm registration/class failures.

```bash
cd plugin
composer test -- --filter 'IncidentRepairRecordTest|AbilitySecurityEnvelopeTest'
```

- [ ] Implement the ability. It does not execute repairs and never opens approval URLs.
- [ ] Use `VerifiedRepairReceipt`, `IncidentStore`, and the existing `Memory::put_typed()`/readback pattern.
- [ ] Store source `verified-repair`, state `promoted_learning`, normalized error/ability family, verifier, verification time, incident hash, resolution-event hash, recipe, scope, and precedence.
- [ ] Make duplicate receipt execution return the existing verified result without creating a second active row.
- [ ] Register it in the memory/security working profiles and update tool priorities/rules.
- [ ] Re-run focused tests.

**Commit:** `feat: record verified incident repairs`

---

## Task 5: Stale promoted learning on recurrence

**Files:**

- Modify: `plugin/includes/Security/IncidentStore.php`
- Modify: `plugin/includes/Memory/Memory.php`
- Modify: `plugin/includes/Security/ErrorPatterns.php`
- Modify: `plugin/tests/Unit/Security/IncidentRepairLifecycleTest.php`
- Modify: `plugin/tests/Unit/Security/ErrorPatternsPromotionTest.php`

- [ ] Add a failing end-to-end test: recurring failure -> verified receipt -> one active lesson -> same cause recurs -> incident reopens -> lesson status becomes `stale` -> active task context excludes it.
- [ ] Add a failing test proving unrelated recurrence cannot stale another lesson.
- [ ] Add a narrow Memory status method keyed by the stored `memory_key`; do not bulk-edit rows by topic or free text.
- [ ] Trigger staling only from a canonical reopened incident that points to a promoted learning key.
- [ ] Preserve the stale row for history; do not delete it.
- [ ] Re-run focused tests.

**Commit:** `feat: stale invalidated repair lessons`

---

## Task 6: Return ranked repair actions from task-start

**Files:**

- Modify: `plugin/includes/Abilities/System/WorkflowPreflight.php`
- Modify: `plugin/includes/Context/ContextBuilder.php`
- Modify: `plugin/includes/Core/AgentInstructions.php`
- Modify: `plugin/tests/Unit/Core/TaskStartNudgeTest.php`
- Modify: `plugin/tests/Unit/WorkflowPreflightSessionProfileTest.php`

- [ ] Add failing tests for a maximum of three compact `incident_actions` ranked by requested surface, ability family, open state, severity, recurrence, then recency.
- [ ] Assert each action contains exactly: `incident_id`, `state`, `ability`, `error_code`, `occurrences`, `repair`, `next_tool`, `required_verifier`, `retry_policy`, and `learning_policy`.
- [ ] Assert `required_actions` uses `repair_open_incidents_first`; remove `fix_recurring_errors_first` from active output and instructions.
- [ ] Assert resolved/suppressed and unrelated low-relevance rows are excluded.
- [ ] Implement one shared formatter/ranker consumed by WorkflowPreflight and ContextBuilder.
- [ ] Update instructions: inspect action, use typed repair, verify, then record only a generic verified recipe. Never clear history to unblock a task.
- [ ] Re-run focused tests.

**Commit:** `feat: guide incident repair at task start`

---

## Task 7: Add a private per-site Direct incident store

**Files:**

- Create: `companion/src/direct/incidents.ts`
- Modify: `companion/src/direct/audit.ts`
- Modify: `companion/src/direct/types.ts`
- Create: `companion/tests/direct-incident-store.test.ts`
- Modify: `companion/tests/direct-error-audit.test.ts`
- Modify: `companion/tests/direct-sensitive-content.test.ts`

- [ ] Add failing tests for a JSON store beneath the existing private `stateDir`, namespaced by site binding/fingerprint and containing hashes, classifications, lifecycle metadata, and scrubbed recipes only.
- [ ] Prove identical failures aggregate; threshold opens the incident; resolved recurrence reopens and increments `reopened_count`.
- [ ] Prove aliases/sites cannot read each other's incidents.
- [ ] Prove URLs, authorization values, passwords, cookies, raw arguments, and customer text never appear in the stored file.
- [ ] Prove atomic save and reload survive a companion restart fixture.
- [ ] Implement `DirectIncidentStore` with bounded rows, restrictive file permissions, atomic temp-file rename, and corrupt-file fail-closed recovery that preserves the original for diagnosis.
- [ ] Feed it only redacted normalized audit events from `appendDirectAudit`/error escalation.
- [ ] Re-run focused tests.

```bash
cd companion
npm test -- tests/direct-incident-store.test.ts tests/direct-error-audit.test.ts tests/direct-sensitive-content.test.ts
```

**Commit:** `feat: track direct incidents privately`

---

## Task 8: Add Direct verified repair and task-start parity

**Files:**

- Modify: `companion/src/direct/incidents.ts`
- Modify: `companion/src/direct/tools/self-improve.ts`
- Modify: `companion/src/direct/registry.ts`
- Modify: `companion/tests/direct-tools-selfimprove.test.ts`
- Modify: `companion/tests/direct-selfimprove-e2e.test.ts`
- Modify: `companion/tests/direct-taskstart-gate.test.ts`
- Modify: `companion/tests/direct-parity-gates.test.ts`

- [ ] Add failing tests for the same compact task-start incident action shape and ranking as Plugin mode.
- [ ] Add a Direct `incident-repair-record` handler that reads local persisted audit events and validates strict correlation; it must not trust input proof fields.
- [ ] Prove exact correlation resolves once and promotes one local lesson after readback.
- [ ] Prove missing independent verifier/correlation returns `guidance_only=true`, leaves the incident open, and creates no lesson.
- [ ] Prove recurrence stales the local lesson and removes it from active task-start memory.
- [ ] Prove package/state migration preserves existing audit, memory, skills, and incidents without copying them into package artifacts.
- [ ] Register the tool without weakening Direct capability-tier or write-gate rules.
- [ ] Re-run focused tests, then all Direct tests.

```bash
cd companion
npm test -- tests/direct-tools-selfimprove.test.ts tests/direct-selfimprove-e2e.test.ts tests/direct-taskstart-gate.test.ts tests/direct-parity-gates.test.ts
npm test -- tests/direct-*.test.ts
```

**Commit:** `feat: verify direct repair learning`

---

## Task 9: Document and regenerate the public contract

**Files:**

- Create: `docs/verified-learning.md`
- Modify: `README.md`
- Modify: `plugin/README.md`
- Modify: `companion/README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/permanent-remediation-contracts.md`
- Modify: `docs/install-prompts.md`
- Modify: `CHANGELOG.md`
- Modify: `plugin/CHANGELOG.md`
- Regenerate: `docs/ability-truth-matrix.md`

- [ ] Explain incidents versus lessons, strict proof, guidance-only Direct limitations, stale lessons, and the typed recorder with generic examples only.
- [ ] State plainly: Stonewright learns explicit user corrections immediately, but audit-derived rules only after a correlated verified repair.
- [ ] Update all task-start examples to `repair_open_incidents_first` and the compact action shape.
- [ ] Regenerate the matrix from source.

```bash
cd plugin
composer docs:matrix
cd ..
node scripts/check-docs-freshness.mjs
git diff --check
```

- [ ] Run public hygiene against the changed tree.

```bash
node scripts/check-public-hygiene.mjs --require-private-terms --history
```

**Commit:** `docs: explain verified learning lifecycle`

---

## Task 10: Full verification for the learning slice

- [ ] Run Plugin unit, static, style, security, and dependency gates.

```bash
cd plugin
composer install
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
```

- [ ] Run companion typecheck, tests, and build.

```bash
cd companion
npm ci
npm run typecheck
npm test
npm run build
```

- [ ] Run repository docs, hygiene, and package simulation.

```bash
cd ..
node scripts/check-docs-freshness.mjs
node scripts/check-public-hygiene.mjs --require-private-terms --history
node scripts/package-verify.mjs --require-visual-bundle --strict-vendor
git diff --check
```

- [ ] Inspect archives or simulated package lists and prove no Direct runtime incident/audit/memory state ships.
- [ ] Review the final diff for customer data, unsafe claims, generated-file drift, and unrelated changes.
- [ ] Do not claim completion unless every required command passes.

**Commit:** `test: close verified learning gates`
