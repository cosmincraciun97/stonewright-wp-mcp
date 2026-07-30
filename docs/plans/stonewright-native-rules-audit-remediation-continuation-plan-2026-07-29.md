# Stonewright Native Rules and Audit Remediation Continuation Implementation Plan

> **Final implementation status — 2026-07-30:** Tasks 1–20 are complete.
> Feature PR `#33` and release PR `#34` passed their complete GitHub matrices,
> including real wp-env Playwright acceptance, before merge. Release
> `v1.0.0-alpha.92` is published and its ZIP/TGZ assets pass the published
> checksums. Task 21 configuration alignment is complete for Codex, Grok CLI,
> Claude Code, Antigravity, and Antigravity IDE. Codex has live alpha.92 proof;
> the user elected to perform the final Grok, Claude, and Antigravity live
> checks. Local Transavia acceptance remains unavailable because the local site
> is offline and its installed plugin is older.

**Goal:** Continue from completed Tasks 1–5 and finish native rules, audit/OAuth remediation, memory generalization, response-efficiency work, documentation, and live acceptance without weakening any security gate.

**Architecture:** One shared, machine-readable rules registry feeds plugin and pluginless Direct mode. Only rules backed by concrete runtime guards may be `hard`; operational instructions that PHP cannot enforce remain `strong`. Response projection is stateless and registry-level, OAuth 4xx protocol outcomes are `auth` while 5xx remain incidents, and memory cleanup is cursor-based, dry-run-first, permissioned, token-bound, and context-token protected.

**Tech Stack:** PHP 8.1+, WordPress APIs, PHPUnit 9/10, PHPStan, PHPCS, TypeScript/Node companion, vanilla admin JS/CSS, Stonewright MCP, authenticated browser verification.

## Current State

- Repository: `/Users/cosminiviteb/Personal/stonewright-wp-mcp`
- Continue only in linked worktree: `/Users/cosminiviteb/Personal/stonewright-wp-mcp/.worktrees/native-rules-audit-remediation`
- Feature branch: `codex/native-rules-audit-remediation`
- Release branch: `codex/release-1.0.0-alpha.92`
- Final handoff branch: `codex/native-rules-final-handoff`
- Audited implementation tip before final remediation:
  `35ae2eac1a49dc7247f32951f7a0f6924575a995`
- Final feature tip: `f964954c6817d3ffeffdb20924e947be522d289d`
- Feature PR: `#33`
- Verified merge commit:
  `e92a09bb4d396648162e388b95a7158a3814c710`
- Release PR: `#34`
- Verified release merge commit:
  `2adb805991cdb11f3a205dee8c30412e3d9f125b`
- Published release:
  `https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.92`
- Do not work in the dirty `main` checkout.
- SDD ledger: `.superpowers/sdd/stonewright-native-rules-audit-remediation-ui-and-efficiency-plan-2026-07-29/progress.md`
- Original plan remains useful for problem statements, but this continuation plan is authoritative when instructions conflict.

Completed, reviewed commits:

| Task | Commit(s) | Result |
| --- | --- | --- |
| 1 — Tooltip owner | `a5cf1ba` | one shell tooltip engine |
| 2 — Primary hover | `270a00c` | primary contrast preserved |
| 3 — Remove dark mode | `4f92ac5`, `ede2fc9` | runtime and test surface removed |
| 4 — CodeFormatter | `b303ac6`, `7ae8905`, `19ac99b` | explicit opt-in, conservative fallback |
| 5 — Write-path wiring | `9a11a2c`, `21d4cb0` | canonical bytes bound before all gates |

Final Task 5 and release verification:

```text
Focused: 18 tests / 93 assertions
Full plugin: 6,208 tests / 32,180 assertions
PHPStan: clean
PHPCS: 708/708 clean
Security audit: 6/6 clean
Dependency audit: zero known vulnerability advisories
Companion: lint, typecheck, 345 tests, build, and audit clean
Visual: 80 tests, build, and audit clean
E2E: 305 tests discovered; release CI Playwright job green
Public contracts, tokens, docs freshness, and git diff --check: clean
```

The detailed Task 6–18 checklists below are retained as the implementation
record. The verified completion table and release receipts are authoritative;
do not redo completed checklist items.

## Verified Completion Status — 2026-07-30

| Task | Status | Decisive evidence |
| --- | --- | --- |
| 1–5 | Complete | Canonical write-path tests cover all four code payload abilities and token binding. |
| 6–8 | Complete | Shared 332-ability / 100-tool registry surface; Plugin and Direct digest parity; Direct loader now validates exact records. |
| 9–12 | Complete | Parser authority, responsive controls, contextual error ownership, and secret-safe OAuth audit behavior pass focused/full suites. |
| 13–16 | Complete | Cursor-based memory generalization, stateless projection, canonical knownHash, and registry-backed batching are implemented. |
| 17 | Complete | Maintained docs, READMEs, changelogs, architecture diagram, contracts, and 332/100 counts are current. |
| 18 — local gates | Complete | 6,208 PHPUnit tests / 32,180 assertions; PHPStan, PHPCS, security, dependency, provenance, contract, token, companion, Visual, and docs gates green. |
| 18 — live/CI | Complete for merge | PR runs `30525077522` and `30525248233` passed, including real wp-env Playwright. Local Transavia acceptance remains unavailable and was not replaced with production testing. |
| 19 | Complete | PR `#33` merged only after every required job was green; merge commit `e92a09b`. |
| 20 | Complete | PR `#34`, main push, and release workflow passed; tag `v1.0.0-alpha.92` resolves to merge `2adb805`; all downloaded assets match `SHA256SUMS.txt`. |
| 21 — configuration | Complete | Global companion, Codex, Grok CLI, Claude Code, Antigravity, and Antigravity IDE point to the verified alpha.92 TGZ with existing targets preserved. |
| 21 — live proof | Partial by user choice | Codex reports `companion_version: 1.0.0-alpha.92`. User will verify Grok, Claude, and Antigravity. Antigravity last reported its configured alpha.92 package while its pre-restart process still reported alpha.91. |

Independent audit fixes added after `35ae2ea`:

- canonical Elementor tree hashes now ignore associative key order but preserve
  element-list order;
- OAuth diagnostics redact echoed request/response credentials and
  Authorization values;
- partial memory update failures return an explicit error with failed ids;
- Direct mode validates packaged rule records instead of trusting a type cast;
- the removed dark theme is also removed from the Playwright project/test
  matrix;
- WordPressCS and E2E dependencies were moved to patched versions with zero
  known vulnerability advisories;
- docs freshness no longer rewrites immutable historical release counts while
  still requiring current counts during release preparation.

## Global Constraints

- No `__return_true` on any write ability.
- Every write permission callback uses `Stonewright\WpMcp\Security\Permissions`.
- Snapshot before Elementor, template, global-style, custom-CSS-post, or theme.json-backed mutation.
- Validate design specs before rendering; invalid specs return `stonewright_spec_invalid`.
- Production-safe destructive operations verify confirmation tokens against canonical arguments.
- Never double-encode `_elementor_data`, strip unknown settings, convert `widgetType`, or full-tree rewrite for one control.
- Never transform code after token, grant, candidate hash, validation, or backup gates.
- `decode_escaped_layout` remains explicit opt-in. Ambiguous grammar remains unchanged.
- OAuth/client 4xx may be `auth`; OAuth 5xx remain `error`.
- Do not hand-edit generated contracts or `docs/ability-truth-matrix.md`.
- `stonewright-task-start` remains canonical first MCP call.
- No REST, JSON-RPC, shell WP-CLI, scratch runner, raw meta, or private-config workaround.
- Public docs, commits, changelogs, and PR text must not mention automated authorship or internal tooling.
- Every task uses RED → GREEN TDD, a scoped commit, independent spec/quality review, and a fix loop for Important/Critical findings.

## Closed Deferred Minors

- Task 2 hover coverage now asserts tokens inside the primary selector and
  explicitly excludes disabled controls.
- Task 3 rendered-shell buffering now uses cleanup-safe `try/finally`.
- `EscapedLayoutDecoder` was reviewed and intentionally kept conservative:
  heredoc/nowdoc, `${...}`, and script/style ambiguity still bail out instead of
  risking code corruption.

---

## Task 6: Shared Global Rules Registry

**Files:**

- Create: `resources/global-rules.json`
- Create: `plugin/includes/Security/GlobalRules.php`
- Create: `plugin/tests/Unit/Security/GlobalRulesTest.php`
- Modify: `plugin/composer.json` only if autoload/resource packaging requires it
- Modify: `companion/package.json` / build copy configuration only when Task 8 consumes the shared file

**Interfaces:**

- `GlobalRules::all(): array`
- `GlobalRules::get(string $id): ?array`
- `GlobalRules::ids_for_severity(string $severity): array`
- `GlobalRules::digest(): string`
- Each JSON record contains:

```json
{
  "id": "backup-before-write",
  "severity": "hard",
  "scope": "all",
  "rule": "Snapshot protected records before mutation.",
  "why": "A snapshot is the rollback boundary.",
  "enforcement": {
    "kind": "runtime",
    "guard": "backup"
  }
}
```

Binding classification:

- `hard` requires `enforcement.kind = "runtime"` and a non-empty concrete guard id.
- Instructions not mechanically enforceable inside plugin/companion are `strong`, including `read-before-write`, `verify-after-write`, `no-schema-guessing`, `no-transport-workarounds`, `missing-tool-means-stop`, `record-corrections`, and `never-trade-gates-for-tokens`.
- Do not claim an agent instruction is runtime-enforced.

- [ ] Write tests that fail because registry does not exist.
- [ ] Assert unique slug ids, allowed severities/scopes, no URL/hostname/site id, and every `hard` rule has a concrete runtime guard.
- [ ] Assert digest from a fixture changes when content changes and stays stable for identical canonical JSON; do not test an immutable method against itself.
- [ ] Implement JSON loading with one-request static caching and structured failure on malformed/missing data.
- [ ] Run:

```bash
cd plugin
./vendor/bin/phpunit --filter GlobalRulesTest --testsuite Unit
composer phpstan
composer phpcs
```

- [ ] Commit:

```bash
git add resources/global-rules.json plugin/includes/Security/GlobalRules.php plugin/tests/Unit/Security/GlobalRulesTest.php
git commit -m "feat: add shared native rule registry"
```

## Task 7: Rule Enforcement and Blocked Audit Classification

**Files:**

- Create: `plugin/includes/Security/RuleEnforcer.php`
- Create: `plugin/tests/Unit/Security/RuleEnforcerTest.php`
- Modify: concrete existing guards selected by `resources/global-rules.json`
- Modify: `plugin/includes/Security/ErrorPatterns.php`
- Modify: `plugin/includes/Abilities/AbilityKernel.php` or central audit mapper only where required to preserve `blocked`
- Test: relevant existing guard and audit tests

**Interfaces:**

```php
RuleEnforcer::violation(
	string $rule_id,
	string $detail,
	array $diagnostics = []
): \WP_Error;
```

Canonical data cannot be overwritten by diagnostics:

```php
[
	'status'              => 409,
	'execution_status'    => 'blocked',
	'verification_status' => 'blocked',
	'retryable'           => false,
	'error_code'          => 'rule_violation',
	'cause_key'           => 'rule:' . $rule_id,
	'rule_id'             => $rule_id,
]
```

- [ ] Write RED tests for immutable error metadata, unknown id exception, and audit status/severity.
- [ ] Map every `hard` registry record to a real guard. If no concrete guard exists, downgrade that record to `strong`; never fake coverage.
- [ ] Wire `RuleEnforcer::violation()` into selected concrete gates and prove the prohibited call fails before mutation.
- [ ] Add `stonewright_rule_violation` to expected-safety handling so a working guard never creates a learning incident.
- [ ] Prove audit rows remain `blocked`, include `rule_id`, and never become high-severity `error` solely because HTTP status is 409.
- [ ] Run focused guards, full Unit, PHPStan, PHPCS, security audit.
- [ ] Commit:

```bash
git commit -m "feat: enforce native rules through runtime guards"
```

## Task 8: Rules Through Task Start, Rules Get, and Direct Mode

**Files:**

- Create: `plugin/includes/Abilities/System/RulesGet.php`
- Modify: `plugin/includes/Abilities/System/WorkflowPreflight.php`
- Modify: plugin ability registration/profile lists
- Modify: `companion/src/direct/tools/self-improve.ts`
- Add companion Direct rules-get implementation consuming `resources/global-rules.json`
- Test: plugin payload/profile/budget tests and companion Direct tests
- Regenerate: `docs/ability-truth-matrix.md`, public contracts

**Compact contract:**

```php
'hard_rules' => [
	'digest' => GlobalRules::digest(),
	'tool'   => 'stonewright-rules-get',
]
```

Do not place all ids or rule bodies in compact task-start. Baseline was 799/800 tokens; first remove at least 64 estimated tokens of duplicate compact guidance, then add the digest/reference while keeping the existing 800/1200 budgets.

- [ ] Write RED runtime tests for full and compact payloads; source-string tests are forbidden.
- [ ] Write RED profile tests proving `stonewright-rules-get` is callable on bootstrap, essential, low-tools, and task-start suggested surfaces.
- [ ] Write RED Direct tests proving pluginless task-start returns the same digest and Direct rules-get returns the same records.
- [ ] Implement read-only `RulesGet` with severity/scope filters and `knownDigest` short-circuit.
- [ ] Add plugin and Direct profile exposure; rebalance capped lists rather than increasing budgets.
- [ ] Run:

```bash
cd plugin
composer tokens:measure
composer docs:matrix
./vendor/bin/phpunit
composer phpstan
composer contracts:compat

cd ../companion
npm run typecheck
npm test
npm run build
```

- [ ] Commit:

```bash
git commit -m "feat: expose native rules across plugin and Direct mode"
```

## Task 9: Remove PHP Bare-Assignment Heuristic

**Files:**

- Modify: `plugin/includes/Security/PhpSyntaxValidator.php`
- Modify/Create: `plugin/tests/Unit/Security/PhpSyntaxValidatorTest.php`

- [ ] Add failing behavior tests for valid mixed PHP/HTML, escaped quotes, class constants/properties, and genuine parse failure.
- [ ] Delete `looks_like_bare_assignment_corruption()` and `php_candidate_bare_assignment`; keep `TOKEN_PARSE` authority.
- [ ] Search for orphaned cause keys and regenerate generated docs instead of editing them.
- [ ] Run focused test, Unit, PHPStan, PHPCS.
- [ ] Commit:

```bash
git commit -m "fix: trust the PHP parser instead of a regex heuristic"
```

## Task 10: Responsive Controls and Standalone Visibility

**Files:**

- Modify: `plugin/includes/Elementor/Schema/WidgetSchemaRepository.php`
- Modify: `plugin/includes/Elementor/Schema/ResponsiveScope.php`
- Test: `plugin/tests/Unit/Elementor/ResponsiveScopeTest.php`
- Test: `plugin/tests/Unit/Elementor/WidgetSchemaRepositoryTest.php`

Corrections to original plan:

- Real API is `ResponsiveScope::assert_settings_in_scope()`, not `check()`.
- `key_breakpoint()` must become `?string`.
- Standalone visibility handling must also be applied in `hash_non_target_breakpoints()`.
- Do not classify every arbitrary `hide_*` or `show_*` key as visibility. Match only Elementor visibility names built from registered breakpoint ids.

- [ ] RED tests: array-valued responsive metadata, allowlist parity, every supported visibility switch, normal suffixed styles, desktop scope, and non-target hash invariance.
- [ ] Implement `control_is_responsive()` and shared responsive allowlist parity.
- [ ] Implement exact visibility detection using registered breakpoint ids.
- [ ] Ensure scope assertion and non-target hash both skip standalone visibility controls.
- [ ] Run:

```bash
cd plugin
./vendor/bin/phpunit --filter 'ResponsiveScopeTest|WidgetSchemaRepositoryTest' --testsuite Unit
composer elementor:schema-diff
./vendor/bin/phpunit
composer phpstan
composer phpcs
```

- [ ] Commit:

```bash
git commit -m "fix: detect responsive controls and visibility switchers"
```

## Task 11: Context-Aware Error Code Namespacing

**Files:**

- Modify: `plugin/includes/Security/ErrorPatterns.php`
- Test: `plugin/tests/Unit/Security/ErrorPatternsTest.php`

Do not globally preserve `invalid_request`: Stonewright abilities also emit it and those codes must be namespaced. Normalization must receive origin context:

```php
ErrorPatterns::normalize_code(string $code, string $ability, string $status): string
```

- Stonewright-owned ability error: `invalid_request` → `stonewright_invalid_request`.
- Namespaced Stonewright code stays unchanged.
- OAuth/auth protocol `invalid_request` and `invalid_grant` remain protocol codes when status/origin is `auth`.
- `rest_`, `oauth_`, and `http_` foreign prefixes remain unchanged.

- [ ] RED tests for each ownership branch and empty input.
- [ ] Route internal signature extraction through contextual normalization.
- [ ] Update expected-safety spelling to `stonewright_feature_disabled`.
- [ ] Run Unit and PHPStan.
- [ ] Commit:

```bash
git commit -m "fix: namespace Stonewright-owned error codes"
```

## Task 12: OAuth Auth Events With Persisted Diagnostics

**Files:**

- Modify: `plugin/includes/Security/AuditLog.php`
- Modify: `plugin/includes/Core/RestRoutes.php`
- Modify: `plugin/includes/OAuth/Endpoints/Token.php`
- Modify: `plugin/includes/Admin/AuditLogPage.php`
- Test: `plugin/tests/Unit/Security/AuditAuthClassificationTest.php`

Required behavior:

- OAuth 4xx protocol/client outcomes → status `auth`, severity `warning`, excluded from `ErrorPatterns`.
- OAuth 5xx/internal exceptions → status `error`, normal incident observation.
- Persist allowlisted diagnostics in top-level audit metadata, not only nested params.
- Allowed: `oauth_error`, `oauth_error_description` truncated, `oauth_error_uri`, `oauth_hint`, `client_id`.
- Forbidden everywhere: `client_secret`, authorization code, refresh token, assertion, bearer token, Authorization header.

- [ ] RED behavior tests dispatch real 400 and 500 response objects and inspect persisted audit rows.
- [ ] Prove repeated 4xx does not create learning records; repeated 5xx still reaches incident logic.
- [ ] Prove secret redaction with sentinel values.
- [ ] Add Auth filter/badge in admin page.
- [ ] Run full Unit, PHPStan, PHPCS.
- [ ] Commit:

```bash
git commit -m "fix: classify OAuth protocol failures without hiding server errors"
```

## Task 13: Cursor-Based Memory Generalization

**Files:**

- Create: `plugin/includes/Memory/Scrubber.php`
- Create: `plugin/includes/Abilities/System/MemoryGeneralize.php`
- Modify: ability registration and context-token classification
- Test: `plugin/tests/Unit/Memory/ScrubberTest.php`
- Test: permission/context/confirmation integration tests

Ability contract:

```php
[
	'apply'              => false,
	'limit'              => 100,
	'cursor'             => null,
	'confirmation_token' => null,
]
```

Output includes `applied`, `scanned`, `changed`, bounded row previews, `next_cursor`, and `done`.

Corrections:

- Use actual Memory fields: `id`, `memory_key`, `name`, `topic`, `value`, `scope`, lifecycle metadata.
- Generic de-identified lessons move to `_global`; site-only observations remain site-scoped or are reported for explicit deletion.
- Iterate deterministically with cursor; never claim a one-page read cleaned all memory.
- Permission callback uses `Permissions::manage_options()`.
- Production-safe apply verifies `ConfirmationToken::verify_or_error()` inside execution; do not call `Permissions::destructive()` because it rejects production-safe writes before token verification.
- Explicitly classify `stonewright/memory-generalize` as a context-token-required mutation.

- [ ] RED pure scrubber tests for URL/host/id fields, nested values, key/topic/name, idempotence, and scope conversion.
- [ ] RED ability tests for dry-run default, cursor continuation, bounded output, context token, permission denial, valid/invalid production token.
- [ ] Implement using sanctioned `Memory` APIs only; no direct SQL.
- [ ] Register, regenerate matrix/contracts, run all plugin checks.
- [ ] Live phase only after code review:

```text
1. stonewright-task-start
2. memory-generalize apply:false, review every proposed category
3. issue confirmation token when production-safe
4. apply one bounded batch
5. read back and continue by next_cursor
6. delete meaningless/duplicate rows only with explicit reviewed ids
```

- [ ] Commit:

```bash
git commit -m "feat: generalize stored memory in reviewed batches"
```

## Task 14: Stateless Global Response Projection

**Files:**

- Create: `plugin/includes/Support/ResponseProjection.php`
- Modify: `plugin/includes/Core/AbilityRegistry.php`
- Test: projection unit tests and registry execution/schema tests

Corrections:

- Do not store requested fields on reusable ability instances.
- Do not capture fields in `AbilityKernel::audit()`; read-only abilities may never call it.
- Inject optional `fields` centrally where `stonewright_context_token` is injected, so strict schemas advertise it.
- Apply projection statelessly in the central execution path using current-call args only.
- Preserve the established `ok` envelope and define whether required output fields are projected before or after runtime output validation. Tests must prove contracts remain valid.

- [ ] RED projection tests: top-level, nested, list projection, unknown omission, merge.
- [ ] RED registry tests: strict schema injection, read ability projection, consecutive projected/unprojected calls on same ability instance, error responses unchanged, `ok` envelope retained.
- [ ] Implement one central schema/execution seam.
- [ ] Run full plugin, PHPStan, contracts compatibility, token measurement.
- [ ] Commit:

```bash
git commit -m "feat: add stateless response field projection"
```

## Task 15: Known-Hash Page Structure Short-Circuit

**Files:**

- Modify: `plugin/includes/Abilities/ElementorV3/GetPageStructure.php`
- Test: `plugin/tests/Unit/Elementor/GetPageStructureHashTest.php`

- [ ] RED tests for schema, stable/changed hash, unchanged execution branch, changed execution branch, no outline build on unchanged, and fields interaction.
- [ ] Add `knownHash`, `hash`, `unchanged`.
- [ ] Route successful output through the Task 14 stateless projection convention without dropping `ok`.
- [ ] Measure live payload before/after against one read-only Elementor page.
- [ ] Commit:

```bash
git commit -m "perf: skip unchanged Elementor structure reads"
```

## Task 16: Native Batching Instruction Without Contract Drift

**Files:**

- Modify: `plugin/includes/Abilities/System/WorkflowPreflight.php`
- Test: `plugin/tests/Unit/Abilities/System/BatchingRulesTest.php`

`batching_rules()` remains `list<string>`. Do not append associative keys. Add `batching_rule_id: "batch-related-mutations"` as a sibling compact field; include body only in full mode or rules-get.

- [ ] RED runtime payload test, not source grep.
- [ ] Implement sibling field and shared registry lookup.
- [ ] Run focused, token budgets, full preflight tests.
- [ ] Commit:

```bash
git commit -m "refactor: source batching guidance from native rules"
```

## Task 17: Documentation and Generated Artifacts

**Files:**

- Modify: `README.md`
- Modify: `plugin/README.md`
- Modify: `plugin/CHANGELOG.md`
- Modify: `docs/install-prompts.md`
- Modify: `docs/architecture.md`
- Regenerate: `docs/ability-truth-matrix.md`, public contracts
- Do not modify companion changelog unless Direct behavior added in Task 8 requires it; Task 8 does change Direct behavior, so document that exact user-visible addition.

- [ ] Document explicit `decode_escaped_layout` opt-in and conservative fallback from Tasks 4–5.
- [ ] Document shared rules, enforceability classification, rules-get, Direct parity, auth status, memory cursor workflow, fields, knownHash, and removed dark mode.
- [ ] Recompute ability counts from generated surface; never guess.
- [ ] Use `VERSION` in evergreen asset URLs.
- [ ] Run:

```bash
cd plugin && composer docs:matrix && composer contracts:generate && composer contracts:compat
cd ..
node scripts/check-docs-freshness.mjs
git diff --check
```

- [ ] Commit:

```bash
git commit -m "docs: document native rules and remediation contracts"
```

## Task 18: Final Verification, Live Acceptance, and Branch Handoff

- [ ] Run plugin gates:

```bash
cd plugin
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
composer contracts:generate
composer contracts:compat
composer tokens:measure
composer docs:matrix
```

- [ ] Run companion gates:

```bash
cd companion
npm run typecheck
npm test
npm run build
```

- [ ] Run repository gates:

```bash
node scripts/check-docs-freshness.mjs
git diff --check
git status --short
```

- [ ] Start live work with `stonewright-task-start`; use Stonewright typed tools and Claudeus site-health reads. Never use REST/shell workarounds.
- [ ] Use authenticated browser for admin acceptance:
  - Design Studio: exactly one tooltip, no theme toggle.
  - Visual Workspace: primary hover darkens, white label remains, one tooltip.
  - Audit Log: Auth filter exists; OAuth 4xx is auth/warning; controlled 5xx remains error.
  - Memory: reviewed batches contain no host/absolute URL/bare local id and native-rule duplicates are gone.
- [ ] Read-only MCP acceptance:
  - rules-get digest/cache/filter behavior in plugin and Direct.
  - page structure knownHash short-circuit and fields projection.
- [ ] Write acceptance only with proper target, context token, dry-run/backup/token gates:
  - one temporary or approved theme-file patch with `decode_escaped_layout:true`;
  - readback proves canonical bytes;
  - restore/delete through sanctioned reversible path.
- [ ] Dispatch final whole-branch reviewer on merge-base → HEAD, including deferred minors.
- [ ] Fix one final review wave, re-review once, adjudicate residuals.
- [ ] Use `superpowers:finishing-a-development-branch`.
- [ ] Do not push, open PR, release, or mutate production beyond approved acceptance until user explicitly authorizes that external action.

## Task 19: Pull Request and CI

- [x] Commit the independent remediation without squashing the auditable task
  history.
- [x] Push `codex/native-rules-audit-remediation` and open a PR against `main`.
- [x] In the PR description, list changed abilities, permission/backup/token/
  validation/audit effects, public docs changed, and the unavailable local-live
  acceptance.
- [x] Wait for every required PR job, including all PHP versions and the real
  wp-env Playwright job.
- [x] Fix root causes on this branch and rerun local proportional gates after
  every CI correction.
- [x] Do not merge or tag while any required check is pending, skipped
  unexpectedly, cancelled, or red.

## Task 20: Release

- [x] Read the repository release-hygiene instructions before changing
  versions.
- [x] Determine the next alpha from tags; do not guess or reuse a version.
- [x] Update plugin, companion, public metadata, all three changelogs, and a new
  `docs/releases/<version>.md`; retain at most five dated release sections.
- [x] Rebuild generated contracts/matrix and rerun the complete CI-equivalent
  local gates.
- [x] Merge only the green PR, tag the verified merge commit, push the tag, and
  wait for the release workflow to publish all assets.
- [x] Verify ZIP/TGZ contents, `SHA256SUMS.txt`, GitHub release status, and the
  released companion version before touching client installations.

Receipts:

- release PR `#34`, CI run `30526383410`: green;
- post-merge main run `30526825936`: green;
- release workflow `30527278528`: green;
- annotated tag `v1.0.0-alpha.92` dereferences to
  `2adb805991cdb11f3a205dee8c30412e3d9f125b`;
- downloaded plugin, companion, and Visual assets match
  `SHA256SUMS.txt`;
- archive inspection confirms alpha.92 metadata, packaged rules/Visual runtime,
  and absence of forbidden development paths.

## Task 21: Local Companion Alignment

- [x] Use only the verified release TGZ and checksum.
- [x] Update Grok CLI, Codex, and Claude through their supported MCP CLI
  commands, preserving each existing target and environment.
- [x] Update Antigravity through its supported UI/configuration surface without
  printing or copying private configuration.
- [x] Restart/reload Codex and confirm the running released version.
- [ ] User acceptance: restart/reload Grok, Claude, and Antigravity and confirm
  the released `companion_version`. The user explicitly retained these checks.
- [x] Record client-by-client evidence and any auth blocker without exposing
  credentials.

Client evidence:

| Client | Configuration | Live acceptance |
| --- | --- | --- |
| Codex | alpha.92 verified release TGZ | Complete: `companion_version: 1.0.0-alpha.92` |
| Grok CLI | alpha.92 verified release TGZ; existing environment preserved | User retained final check |
| Claude Code | alpha.92 verified release TGZ; `claude mcp get` reports connected | User retained final check; prior headless OAuth session was expired |
| Antigravity | alpha.92 verified release TGZ; JSON valid; 12 servers preserved | User retained final check |
| Antigravity IDE | alpha.92 verified release TGZ; JSON valid; 19 servers preserved | User retained final check |

## Handoff Instructions for Next Agent

1. Read this file and repository `AGENTS.md` completely.
2. Use `caveman` only for user-facing commentary; code, docs, commits, and PR
   text remain normal.
3. Verify the exact worktree and branch:

```bash
cd /Users/cosminiviteb/Personal/stonewright-wp-mcp/.worktrees/native-rules-audit-remediation
git branch --show-current
git rev-parse HEAD
git status --short
```

Expected implementation/release state:

```text
main: 2adb805991cdb11f3a205dee8c30412e3d9f125b
release: v1.0.0-alpha.92
feature PR: #33 merged
release PR: #34 merged
```

4. Do not redo Tasks 1–20. No implementation or release work remains.
5. If the user requests the retained client checks, verify only Grok, Claude,
   and Antigravity against the already published alpha.92 TGZ. Do not rewrite
   their configuration unless readback proves it drifted.
6. If the user starts the local Transavia site, first prove the installed
   plugin is alpha.92, then execute the deferred Task 18 admin/MCP acceptance.
   Never substitute the production site and never run the write acceptance
   against production.
7. For local Transavia write acceptance, keep every Task 18 gate: task-start,
   read-before-write, dry-run, context token, snapshot, confirmation token when
   required, surgical write, readback, and sanctioned restore.
8. Keep credentials and private client configuration out of terminal output,
   logs, commits, PR text, and handoff notes.
9. Do not create a new release merely to finish client or local-site
   acceptance. Alpha.92 is already published and verified.

## Self-Review

- Spec coverage: remaining original Tasks 6–18 are represented; Tasks 4–5 corrections are carried into docs/live acceptance.
- Safety gaps corrected: runtime-enforceability truth, Direct parity, token budget, OAuth 5xx, real Memory APIs/pagination/context/token, stateless projection, output envelope, responsive API/hash, and batching list contract.
- No placeholder instructions remain.
- Cross-task types are consistent: shared JSON registry → PHP/Direct loaders; Task 14 projection → Task 15 fields interaction; Task 11 contextual codes → Task 12 auth classification.
