# Remaining-task prompts — Stonewright Elementor-first hardening

Each section below is a **self-contained prompt** for a fresh Claude Code session. Pick the next
unblocked task, paste the **entire section body** (everything below the `### Prompt` heading) as the
first message in a clean session, and let the session execute it end-to-end.

Every prompt assumes:

- Working directory `/Users/cosminiviteb/Personal/stonewright-wp-mcp`
- Plan file lives at `docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md`
- Hard rules in `AGENTS.md`; project notes in `CLAUDE.md`
- The session will run `/superpowers:subagent-driven-development` and dispatch
  implementer → spec reviewer → quality reviewer in that order
- Caveman mode is on for prose; code/commits/security messages stay normal English
- Verification must pass before marking the task done:
  - `cd plugin && composer test && composer phpstan && composer phpcs`
  - `cd companion && npm test && npm run typecheck && npm run lint && npm run build`

Sequence respects the dependency graph in the plan. Do **not** start a task whose prerequisites
are not yet ✅ in the TaskList of the previous session.

---

## Task 4.QR — Phase 1.2 quality-review fixes

**Prerequisite:** Task 4 implementer finished + spec review approved. Quality review found 8
Important + 5 Suggestion items. These fixes close them.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 1.2 Lock down the companion HTTP surface"
in docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: close quality-review items on Phase 1.2 companion HTTP hardening.

Implementer must:

IMPORTANT (must fix all):

1. companion/src/lib/security.ts — rate-limit buckets Map unbounded → DoS. Cap at 10_000 entries.
   On insert when over 90% full, prune entries whose lastRefill is older than 5 minutes. Add a
   test that exercises eviction.

2. companion/src/lib/security.ts:getIp() — X-Forwarded-For trusted by default. Only honor it when
   env COMPANION_TRUST_PROXY=1. Default: socket.remoteAddress only. Add test for default + opt-in.

3. companion/src/index.ts — isMainModule() detection breaks with symlinks (npm bin, pnpm). Replace
   import.meta.url.endsWith(process.argv[1]) with realpathSync(fileURLToPath(import.meta.url)) ===
   realpathSync(process.argv[1]).

4. companion/src/mcp-proxy.ts — proxyConfig loaded at module import. Convert to lazy getter
   getProxyConfig() that memoizes on first call. Update handleProxy to call it. Add test that
   env vars set AFTER module import are honored.

5. plugin/includes/Support/CompanionClient.php — wp_json_encode($body) may return false. Guard:
   return WP_Error('stonewright_companion_encode_failed', ...) if encoding fails. Add unit test
   covering the failure path (pass a value containing a resource or NAN).

6. plugin/includes/Abilities/QA/Report.php — child abilities invoked via $ability->execute() bypass
   permission_callback. Add a docblock above the loop documenting the assumption that all four QA
   child abilities share the same edit_posts cap as Report itself; flag what would need to change
   if caps diverge in the future.

7. companion/src/lib/paths.ts — TOCTOU between assertInsideArtifacts() and write. Add a docblock
   at the top of assertInsideArtifacts() documenting the trust boundary: caller MUST write to the
   returned path immediately and SHOULD NOT pass through user-controlled subdirectories. Note that
   tmpdir() per-user reduces real-world risk.

8. companion/tests/path-safety.test.ts — symlink test brittle. Add an explicit assertion that the
   symlink's realpath does NOT start with root, before invoking assertInsideArtifacts().

SUGGESTIONS (fix if simple):

S1. companion/src/lib/security.ts — COMPANION_RATE_LIMIT_RPS=0 currently falls back to 20. Treat 0
    as "disabled" (skip rate-limit middleware entirely). Document behavior in code comment.

S2. companion/src/lib/security.ts — Math.max(ab.length, bb.length, 1) magic 1 → add inline comment
    explaining timingSafeEqual rejects Buffer.alloc(0).

S3. plugin/includes/Abilities/QA/Report.php — array_merge($child_args, ['url' => $post_url]) is a
    no-op since $child_args already contains url=$post_url. Drop the redundant merge.

S4. companion/src/lib/paths.ts — replace cursor.split(sep).pop() ?? '' with basename(cursor).

S5. companion/src/mcp-proxy.ts — guard against path-traversal upstream. Normalize req.url via
    new URL(req.url, 'http://localhost').pathname before concatenating to proxyConfig.target, OR
    reject any '..' segments. Add a test.

Process:

1. Implementer fixes all Important items + reasonable Suggestions.
2. Spec reviewer confirms no spec regressions.
3. Quality reviewer confirms all Important items addressed.
4. Run full verification suite. ALL must pass:
   - cd plugin && composer test && composer phpstan && composer phpcs
   - cd companion && npm test && npm run typecheck && npm run lint && npm run build
5. Mark Task 4 completed in TaskList.

Output: brief report. file:line for each fix. Tail of verification output.
```

---

## Task 5 — Phase 1.3 Confirmation tokens fix

**Prerequisite:** Task 4 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "Confirmation tokens for destructive operations"), CLAUDE.md, and
plan section "Phase 1.3 Confirmation tokens hardening" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: harden Stonewright\WpMcp\Security\ConfirmationToken so production-safe mode actually blocks
destructive abilities without a valid token, and every mutator wires it correctly.

Spec deliverables (read full plan section before starting):

1. ConfirmationToken::verify( $token, $ability_name, $args ) must:
   - HMAC over (ability_name, canonicalized args, expiry ts, nonce) using a secret derived from
     wp_salt('auth') + a per-install random stored in option stonewright_confirmation_secret.
   - Constant-time compare (hash_equals).
   - Reject if expired (>5 minutes), reused (nonce already consumed — store consumed nonces in a
     transient set), or args mismatch (re-canonicalize and compare).
   - Return structured WP_Error with code stonewright_confirmation_invalid / expired / replayed /
     args_mismatch.

2. ConfirmationToken::issue( $ability_name, $args, $ttl_seconds = 300 ): array { token, expires_at }
   used by a new ability stonewright/confirm.issue that REST clients call before destructive ops.

3. Every destructive ability (sandbox writes, theme.json writes, options writes, post writes when
   Permissions::is_production_safe() === true) calls ConfirmationToken::verify() before performing
   the mutation. Use the SandboxGuards trait pattern (already in place for sandbox abilities) and
   extend it / introduce a generic ConfirmationGuard trait.

4. Audit log records confirmation_token verification result (valid/invalid/expired/replayed) with
   nonce_sha8 — never log the raw token.

5. Tests:
   - plugin/tests/Unit/ConfirmationTokenTest.php covering issue → verify happy path, expired,
     replayed (same nonce twice), args-mismatch, mode-toggle behavior.
   - plugin/tests/Integration/AbilityConfirmationTest.php covering at least one sandbox-write and
     one theme.json-write ability rejecting requests without token in production-safe mode and
     accepting them in development mode.

Process:

1. Read the plan section verbatim before delegating.
2. Implementer (sonnet) writes failing tests first (TDD), then implementation. Touches:
   - plugin/includes/Security/ConfirmationToken.php (rewrite/extend)
   - plugin/includes/Abilities/Common/ConfirmationGuard.php (new trait)
   - plugin/includes/Abilities/Sandbox/*.php (wire trait for mutators if not already)
   - plugin/includes/Abilities/Confirm/Issue.php (new ability stonewright/confirm.issue)
   - plugin/includes/Plugin.php / registry wiring
   - tests as above
3. Spec reviewer (opus) verifies every spec bullet line-by-line.
4. Quality reviewer (opus) checks HMAC correctness, replay-protection storage, args canonicalization
   determinism, audit redaction.
5. Run cd plugin && composer test && composer phpstan && composer phpcs. ALL must pass.
6. Mark Task 5 completed.

Output: file:line per change, verification tail.
```

---

## Task 6 — Phase 1.4 Content capability checks

**Prerequisite:** Task 5 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "No __return_true for writes"), CLAUDE.md, and plan section
"Phase 1.4 Content capability checks" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: replace every __return_true / lax permission_callback in content-writing abilities with a
real capability check routed through Stonewright\WpMcp\Security\Permissions.

Spec deliverables:

1. Audit every ability under plugin/includes/Abilities/. List each ability with its current
   permission_callback. Output the audit as a table in the PR description (NOT a separate doc file).

2. For every ability that writes/updates/deletes WordPress state, the permission_callback MUST:
   - Call Permissions::current_user_can_edit_post( $post_id ) for post-targeted abilities (uses
     edit_post + edit_others_posts as appropriate).
   - Call Permissions::current_user_can_manage_theme() for theme/site-wide writes
     (edit_theme_options + customize).
   - Call Permissions::current_user_can_manage_options() for option writes (manage_options).
   - Call Permissions::can_manage_sandbox() for sandbox abilities (already done — verify).
   - Reject with WP_Error('stonewright_forbidden', ..., ['status' => 403]) on failure.

3. Add Permissions helpers as needed (current_user_can_edit_post, current_user_can_manage_theme).

4. Update every ability schema docblock to declare the required cap (so the truth matrix in Task 14
   can consume it via reflection).

5. Tests:
   - plugin/tests/Unit/PermissionsTest.php extended with new helpers.
   - plugin/tests/Integration/AbilityPermissionsTest.php sweep that loops every registered ability
     and asserts: anonymous = denied, subscriber = denied for writes, editor = allowed for content
     writes / denied for theme writes, admin = allowed for everything except sandbox without
     edit_plugins.

Process:

1. Implementer (sonnet) does audit pass first, then makes systematic fixes.
2. Spec reviewer verifies coverage matrix matches the audit.
3. Quality reviewer checks no ability still uses __return_true, no bare current_user_can outside
   Permissions class, no missing post-id checks.
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs.
5. Mark Task 6 completed.
```

---

## Task 7 — Phase 2 Truthful contracts

**Prerequisite:** Tasks 5 + 6 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 2 — Truthful contracts" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: make every ability output JSON Schema match what the handler actually returns.

Spec deliverables (read plan section in full):

1. Introduce a schema-introspection harness in plugin/tests/Integration/ContractTest.php that:
   - Loads every registered ability via the registry.
   - Invokes a representative input (use ability-supplied fixtures under
     plugin/tests/fixtures/abilities/<ability_slug>.json).
   - Validates the actual response against the ability's declared output_schema using
     opis/json-schema (already in vendor) or a small in-tree validator.
   - Fails the test on any mismatch (extra field, missing field, type drift).

2. Walk each existing ability and either:
   - Fix the handler to match the declared schema, OR
   - Fix the schema to match what the handler actually returns.
   Document each decision inline in the ability docblock.

3. Add fixtures under plugin/tests/fixtures/abilities/ for every ability — bare minimum input
   payload that exercises the happy path. Include a NEGATIVE case fixture
   (<slug>.error.json) that should produce WP_Error and validate the error envelope.

4. Standardize error envelope: { error: { code: string, message: string, data?: { status: int, ... } } }
   across every ability that returns WP_Error. Add Stonewright\WpMcp\Support\ErrorEnvelope helper
   if needed.

5. Tests added to Integration suite; Unit suite must still pass.

Process:

1. Implementer (sonnet) builds harness first, runs against current codebase, catalogs failures.
2. Implementer fixes each failure one ability at a time, committing after each.
3. Spec reviewer verifies every ability in the registry is covered by the harness.
4. Quality reviewer checks error-envelope consistency, schema strictness (no `additionalProperties:
   true` unless intentional).
5. Verification: cd plugin && composer test && composer phpstan && composer phpcs.
6. Mark Task 7 completed.
```

---

## Task 8 — Phase 3 Companion QA REST contract

**Prerequisite:** Tasks 4 + 7 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "Companion never writes to WordPress"), CLAUDE.md, and plan section
"Phase 3 — Companion QA REST contract" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: lock the WordPress ↔ companion REST contract for QA flows (screenshot, diff, a11y, lighthouse,
responsive, report). Produce a versioned, typed contract that both sides import.

Spec deliverables (read plan section in full):

1. Define an OpenAPI-style schema (or JSON Schema) for the companion HTTP endpoints under
   companion/src/contracts/<endpoint>.schema.json. Endpoints: /screenshot, /diff, /a11y,
   /lighthouse, /responsive, /report, /health, /mcp, /proxy.

2. Generate TypeScript types from those schemas. Pick one of:
   - json-schema-to-typescript (already a candidate)
   - typebox + Static<typeof X>
   Whichever you pick, document it in companion/README.md.

3. Companion handlers consume the generated types — no inline ad-hoc interfaces.

4. PHP side: companion/src/contracts/ files mirrored to plugin/includes/Companion/Contracts/*.php
   as plain shape arrays + a CompanionContract::validate( $endpoint, $payload ) helper.

5. Every QA ability under plugin/includes/Abilities/QA/* calls CompanionContract::validate() on
   both request payload (before send) and response payload (after parse). Returns WP_Error on
   contract violation.

6. Version the contract: contracts/version = "1.0.0", advertised on /health. Companion + plugin
   refuse to talk to a different major version. Add a test.

7. Tests:
   - companion/tests/contract.test.ts verifying each endpoint's payload matches its schema.
   - plugin/tests/Integration/QAContractTest.php verifying QA abilities reject malformed responses.

Process:

1. Implementer (sonnet) drafts schemas first, then wires both sides.
2. Spec reviewer cross-checks PHP + TS shapes match.
3. Quality reviewer checks contract drift detection works on both sides + version-mismatch handling.
4. Verification: both suites.
5. Mark Task 8 completed.
```

---

## Task 9 — Phase 4 Elementor V3 renderer completeness

**Prerequisite:** Task 7 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "Backup before write"), CLAUDE.md, and plan section
"Phase 4 — Elementor V3 renderer completeness" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: complete the Stonewright DesignSpec → Elementor V3 JSON renderer. Currently covers a subset
of section/column/widget types. Must cover every widget type listed in the spec table.

Spec deliverables:

1. Read plan section "Phase 4 — Elementor V3 renderer completeness" for the full widget list and
   acceptance criteria.

2. Renderer files live under plugin/includes/Elementor/Renderer/. One file per widget type:
   - Heading.php, TextEditor.php, Image.php, Button.php, Spacer.php, Divider.php, Video.php, Icon.php,
     IconBox.php, ImageBox.php, Testimonial.php, Tabs.php, Accordion.php, Toggle.php, SocialIcons.php,
     ProgressBar.php, Counter.php, Form.php (if Pro is detected), Slides.php (if Pro detected).
   - Plus container/section/column shells: Section.php, Column.php, Container.php.

3. Every renderer:
   - Accepts a validated DesignSpec node and returns an Elementor element array with id, elType,
     widgetType, settings, elements[].
   - Generates stable element IDs (sha1 of canonical key path, first 7 chars; do NOT use random ids).
   - Resolves design tokens (color/font/spacing) via Stonewright\WpMcp\DesignTokens\Resolver.
   - Falls back gracefully when an optional setting is absent.

4. ElementorWriter wraps the full pipeline:
   - Backup::snapshot_post( $post_id ) FIRST.
   - Validator::validate( $spec ).
   - Renderer::render( $spec ) → array.
   - update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $array ) ) ).
   - Bump _elementor_edit_mode = 'builder' and _elementor_version to current Elementor version.
   - Invalidate Elementor cache (Elementor\Plugin::$instance->files_manager->clear_cache() if loaded).
   - Audit log the write with ability name + post_id + spec sha8.

5. Tests:
   - plugin/tests/Unit/ElementorRendererTest.php — one assertion per widget renderer, comparing
     to a golden fixture under tests/fixtures/elementor/<widget>.json.
   - plugin/tests/Integration/ElementorWriterTest.php — end-to-end (validate → render → write →
     read back) on a wp_post with stub meta API.

Process:

1. Implementer (sonnet, but use opus if hitting Elementor schema judgment calls) does TDD per
   widget: golden fixture first, renderer second.
2. Spec reviewer verifies every widget in the plan table is implemented.
3. Quality reviewer checks deterministic IDs, token resolution, error handling on partial specs.
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs.
5. Mark Task 9 completed.
```

---

## Task 10 — Phase 5 Elementor widget builder

**Prerequisite:** Tasks 3 + 9 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 5 — Elementor widget builder" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: add stonewright/elementor.widget_define + stonewright/elementor.widget_register abilities
that let an MCP client create new Elementor widgets at runtime — safely, via sandbox.

Spec deliverables:

1. Read plan section "Phase 5 — Elementor widget builder" in full for the schema.

2. stonewright/elementor.widget_define:
   - Input: widget_slug, label, category, controls (array of {id, label, type, default, options}),
     template (Stonewright template DSL — NOT raw PHP), render_strategy ('twig' | 'block-binding').
   - Validates template against a whitelist of safe directives.
   - Generates the widget PHP file content, runs it through StaticGuard::scan(), saves to sandbox
     mu-plugin under SandboxFiles::active_prefix() . 'widget-<slug>.php'.
   - Returns { sandbox_file, preview_url }.
   - permission_callback: Permissions::can_manage_sandbox() (already implies edit_plugins +
     manage_options).
   - Confirmation token required in production-safe mode.

3. stonewright/elementor.widget_register:
   - Activates a previously-defined widget (renames .pending → live name).
   - Validates StaticGuard one more time before activation.
   - Records widget metadata in option stonewright_registered_widgets[].
   - Confirmation token required in production-safe mode.

4. stonewright/elementor.widget_list: enumerate registered widgets with status (active/sandboxed).

5. Tests:
   - plugin/tests/Unit/WidgetDefineTest.php — schema validation, template compilation, StaticGuard
     rejection of unsafe templates.
   - plugin/tests/Integration/WidgetRegistrationTest.php — full define → register → render cycle on
     a wp_post.

6. Code generation MUST NOT use eval / dynamic include of user-supplied source. Output is plain
   PHP file written to disk; only the validated template DSL becomes executable code, and only
   after StaticGuard scan.

Process:

1. Implementer (opus — design judgment) drafts the template DSL spec first, then the generators.
2. Spec reviewer verifies the DSL whitelist is enforced.
3. Quality reviewer attacks the DSL — try to smuggle PHP through it, confirm StaticGuard catches
   each attempt.
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs.
5. Mark Task 10 completed.
```

---

## Task 11 — Phase 6 Design ingestion

**Prerequisite:** Tasks 7 + 8 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 6 — Design ingestion" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: ingest Figma designs (via companion) and produce validated DesignSpec ready for the renderer.

Spec deliverables:

1. companion side: companion/src/figma-bridge.ts (already exists) returns a normalized intermediate
   shape — extend it to produce the FULL DesignSpec shape that the WordPress side expects. Schema
   defined in companion/src/contracts/design-spec.schema.json (link to plugin side).

2. WordPress side: stonewright/design.ingest_figma:
   - Input: figma_url OR (file_key, node_id), optional token override.
   - Calls companion_figma_fetch via CompanionClient.
   - Runs Stonewright\WpMcp\DesignSpec\Validator::validate( $spec ); rejects with structured WP_Error
     on failure.
   - Returns { spec, spec_sha8, asset_count, warnings[] }.

3. stonewright/design.preview_render:
   - Takes a DesignSpec, returns the Elementor JSON (without writing) for a dry-run preview.

4. stonewright/design.apply_to_post:
   - Takes spec + post_id. Backup → validate → render → write (uses ElementorWriter from Task 9).
   - Confirmation token required in production-safe mode.

5. Asset handling: image refs in the spec are uploaded to the WP media library via
   wp_handle_sideload (NOT via the companion writing to WP). Companion exports images; PHP side
   ingests the exported URLs and sideloads them.

6. Tests:
   - plugin/tests/Integration/DesignIngestionTest.php — happy path with a fixture figma response.
   - plugin/tests/Integration/DesignApplyTest.php — apply to a wp_post, verify _elementor_data
     written.
   - companion/tests/figma-bridge.test.ts — golden output for a canned Figma node.

Process:

1. Implementer (opus, mixed PHP + TS) coordinates both sides.
2. Spec reviewer verifies the contract version-locks.
3. Quality reviewer checks asset-sideload safety (no SSRF on attacker-controlled image URLs —
   wp_safe_remote_get + content-type validation + size cap).
4. Verification: both suites.
5. Mark Task 11 completed.
```

---

## Task 12 — Phase 7 Gutenberg + FSE parity

**Prerequisite:** Task 7 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "Backup before write" applies to theme.json writes too), CLAUDE.md,
and plan section "Phase 7 — Gutenberg + FSE parity" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: add Gutenberg block + Full-Site-Editing template abilities so non-Elementor sites can use
Stonewright too.

Spec deliverables:

1. Read plan section "Phase 7 — Gutenberg + FSE parity" for the full ability list.

2. Abilities to add under plugin/includes/Abilities/Gutenberg/:
   - stonewright/gutenberg.render_blocks (DesignSpec → block markup string).
   - stonewright/gutenberg.apply_to_post (Backup → render → wp_update_post with post_content).
   - stonewright/fse.write_template (write to wp_template post type with template + theme columns).
   - stonewright/fse.write_template_part.
   - stonewright/fse.write_global_styles (writes wp_global_styles post; Backup snapshot too).
   - stonewright/fse.read_template / read_global_styles.

3. Renderer pipeline mirror to Elementor:
   - plugin/includes/Gutenberg/Renderer/<BlockName>.php for core/paragraph, core/heading,
     core/image, core/columns, core/group, core/buttons, core/quote, core/list, core/cover,
     core/spacer, core/separator.
   - Each accepts DesignSpec node, returns serialize_block()-ready array.

4. Validator: theme.json writes go through Stonewright\WpMcp\ThemeJson\Validator (new), schema
   from WordPress core's theme.json schema (vendor it under plugin/schemas/theme-json.schema.json).

5. Tests:
   - plugin/tests/Unit/GutenbergRendererTest.php — golden fixtures per block.
   - plugin/tests/Integration/FSEWriteTest.php — write template, write template part, write global
     styles, verify backup snapshot exists, verify post meta + content.

Process:

1. Implementer (sonnet) does block-by-block TDD.
2. Spec reviewer checks every block in the plan table is covered.
3. Quality reviewer checks theme.json schema compliance + backup-before-write enforcement.
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs.
5. Mark Task 12 completed.
```

---

## Task 13 — Phase 8 Admin UX + Sandbox library

**Prerequisite:** Tasks 3 + 10 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 8 — Admin UX + Sandbox library" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: ship a WordPress admin page so site operators can see what Stonewright is doing.

Spec deliverables (read plan section in full):

1. Admin menu under Tools → Stonewright. Capability: manage_options.

2. Tabs (built with React + WP components, bundled via @wordpress/scripts):
   - "Overview": current mode (development | production-safe), version, abilities count, last 20
     audit log entries (paginated).
   - "Sandbox library": list of sandbox mu-plugin files (live + .crashed + .disabled), per-row
     actions (edit, deactivate, delete, view source). Source view uses a read-only Monaco editor.
     Edit actions require ConfirmationToken in production-safe mode (UI shows a confirmation modal
     that prompts the user to type the file name to confirm — frontend then calls
     stonewright/confirm.issue and chains the destructive call with the returned token).
   - "Audit log": full audit log table with filter by ability + actor + result, CSV export, redacted
     fields visibly marked as [redacted, sha8=XXX].
   - "Settings": toggle mode, configure companion_url, manage confirmation secret rotation.

3. JS source under plugin/admin/src/, bundled output under plugin/admin/build/. Add npm scripts
   under a new plugin/admin/package.json (yes, this is JS inside the PHP plugin — keep it isolated).

4. Server-side endpoints reuse existing abilities; no new business logic. Admin React shell only
   wires UI → REST → abilities.

5. Tests:
   - plugin/tests/Integration/AdminPageTest.php — admin menu registers, REST routes respond, cap
     checks enforce.
   - plugin/admin/tests/*.test.tsx (vitest + testing-library) — component tests for confirmation
     modal flow, sandbox list rendering, audit table.

Process:

1. Implementer (opus — UI design + chained REST flow) builds tab-by-tab.
2. Spec reviewer checks every tab matches the plan section.
3. Quality reviewer attacks the React shell: XSS via audit log entries, CSRF via REST nonce,
   confirmation-modal bypass (clicking confirm without typing the name correctly).
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs AND
   cd plugin/admin && npm test && npm run build.
5. Mark Task 13 completed.
```

---

## Task 14 — Phase 9 Documentation + ability truth matrix

**Prerequisite:** Tasks 10 + 12 + 13 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 9 — Documentation + ability truth matrix"
in docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: produce the docs surface — operator quickstart, ability reference, security model — without
restating internals. Document the things that are NOT obvious from code.

Spec deliverables (read plan section in full):

1. docs/ structure (under repo root):
   - docs/quickstart.md — install plugin + companion, set tokens, run a sample ability.
   - docs/security.md — threat model, sandbox model, confirmation tokens, audit log redaction,
     companion isolation. Reference AGENTS.md hard rules.
   - docs/abilities.md — auto-generated. Each ability gets: slug, description, required cap,
     destructive (yes/no), confirmation-required-in-prod-safe (yes/no), input schema, output
     schema, error codes.
   - docs/companion.md — how to run companion locally, env vars, supported platforms.
   - docs/elementor.md — DesignSpec → Elementor mapping table (one row per widget renderer).
   - docs/gutenberg.md — DesignSpec → Gutenberg block mapping.
   - docs/troubleshooting.md — common errors + fixes (sandbox crash recovery, confirmation token
     mismatch, companion bearer-token failures, FIGMA_TOKEN missing).

2. Auto-generate docs/abilities.md from the registry. Add a CLI tool under plugin/bin/generate-docs.php
   that loads abilities and writes the markdown.

3. CONTRIBUTING.md at repo root: how to add a new ability, how to add a new renderer, how to add
   a fixture, link to /subagent-driven-development workflow.

4. README.md at repo root: one-paragraph pitch, install snippet, link to docs/.

5. Tests:
   - plugin/tests/Integration/DocsTest.php — runs the generator, snapshots the output, fails if
     ability registry produces docs that don't compile (broken JSON schema, missing fields).

Process:

1. Implementer (sonnet) builds the generator first, then writes the hand-authored docs.
2. Spec reviewer checks every doc file the plan lists exists with substantive content.
3. Quality reviewer checks for stale info (links to renamed files, outdated ability counts).
4. Verification: cd plugin && composer test && composer phpstan && composer phpcs AND
   php plugin/bin/generate-docs.php (must exit 0 and produce identical-to-checked-in output).
5. Mark Task 14 completed.
```

---

## Task 15 — Phase 10 Final verification

**Prerequisite:** Task 14 completed.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md, CLAUDE.md, and plan section "Phase 10 — Final verification" in
docs/superpowers/plans/2026-05-21-elementor-first-hardening-and-roadmap.md.

Task: full-system pre-release verification. Goal: prove the plugin + companion are shippable as
1.0.0-alpha.1.

Spec deliverables:

1. Run the full automated suite on a clean checkout:
   - cd plugin && composer install --no-dev && composer install && composer test && composer phpstan
     && composer phpcs
   - cd companion && rm -rf node_modules && npm ci && npm test && npm run typecheck && npm run lint
     && npm run build
   Capture output in a verification log.

2. Manual smoke test (use wordpress-studio MCP if available — there's a Studio site for this repo):
   - Install plugin on a fresh WordPress site.
   - Activate.
   - Issue every ability in the registry once via wp-cli or REST. Capture the response.
   - Inspect audit log: every call recorded, redactions in place.
   - Trigger a sandbox crash (write a malformed mu-plugin via the ability), verify .crashed rename
     + admin notice.
   - Trigger a confirmation-token failure (call destructive ability without token in prod-safe
     mode), verify 403.

3. Run /ultrareview (user must trigger) on the branch and fix any high-severity findings.

4. Update CHANGELOG.md with the 1.0.0-alpha.1 entry summarizing each phase.

5. Bump version in plugin/stonewright.php header + companion/package.json + any other version
   surface.

6. Tag the commit (do NOT push — leave for the human to confirm).

7. Final verification doc: docs/release-notes/1.0.0-alpha.1.md with:
   - What's in (phase-by-phase summary).
   - Known limitations.
   - Upgrade notes (none — first release).
   - Acknowledgments.

Process:

1. Implementer (opus — judgment-heavy) drives the verification, captures all output.
2. Spec reviewer checks every plan section's "Acceptance" criteria are demonstrated.
3. Quality reviewer attacks one more time: try a documented bypass, verify it's blocked.
4. Mark Task 15 completed only after every check passes.

Output: verification log + release-notes file path + tag name.
```

---

## Task 16 — StaticGuard follow-up: Reflection + Closure::fromCallable bypasses

**Prerequisite:** Task 3 completed. Independent of the main path — can run any time after Task 3.

### Prompt

```text
/caveman
/subagent-driven-development

Repo: /Users/cosminiviteb/Personal/stonewright-wp-mcp
Read first: AGENTS.md (hard rule "No arbitrary PHP execution"), CLAUDE.md, and the existing
StaticGuard at plugin/includes/Sandbox/StaticGuard.php + its tests at
plugin/tests/Unit/SandboxStaticGuardTest.php.

Task: close two confirmed StaticGuard bypasses.

Bypass A — Reflection-based dispatch:

    $ref = new ReflectionFunction('\exec');
    $ref->invoke('id');

    (new ReflectionMethod('SomeClass', 'someMethod'))->invoke($obj);

The Reflection chain is broader than Closure::bindTo and can call literally anything.

Bypass B — Closure::fromCallable with literal blocked builtin:

    $c = Closure::fromCallable('\exec');
    $c('id');

The literal lets the attacker reify a callable to the blocked function. Unlike call_user_func, the
returned closure can be passed around, stored, smuggled past later checks.

Deliverables:

1. StaticGuard token-pass: detect `new ReflectionFunction(LITERAL)` and `new ReflectionMethod(...)`
   where the literal (after stripping leading `\`) matches is_blocked_function_name(). Emit:
   "Disallowed pattern: Reflection construction targeting blocked function <name>".

2. StaticGuard token-pass: flag any `->invoke(` / `->invokeArgs(` method call following a variable
   that holds (or could hold) a Reflection instance. Conservative: flag every ->invoke / ->invokeArgs
   inside the sandbox file. False positives acceptable here — legitimate Reflection invocation
   inside sandbox code is a smell. Emit:
   "Disallowed pattern: Reflection invocation (->invoke / ->invokeArgs)".

3. Optionally flag any T_STRING reference to "ReflectionFunction" / "ReflectionMethod" / "ReflectionClass" used as a class name
   (e.g. `new ReflectionFunction(...)` regardless of arg, or `ReflectionClass::*`). Mark as
   Suggestion in the reviewer report — implement if it doesn't fail current allowed fixtures.

4. StaticGuard token-pass: detect `Closure::fromCallable(LITERAL)` where LITERAL (after stripping
   leading `\`) matches is_blocked_function_name(). Emit:
   "Disallowed pattern: Closure::fromCallable referencing blocked function <name>".

5. Keep `Closure::fromCallable('my_safe_function')` allowed when the literal does NOT match a
   blocked builtin.

6. Fixtures (write via Bash heredoc):
   - plugin/tests/fixtures/sandbox/blocked/reflection_function_exec.php
   - plugin/tests/fixtures/sandbox/blocked/reflection_method_invoke.php
   - plugin/tests/fixtures/sandbox/blocked/closure_from_callable_exec.php
   - plugin/tests/fixtures/sandbox/allowed/closure_from_callable_safe.php (literal = 'my_handler', not blocked)

7. Wire all four into the appropriate providers in SandboxStaticGuardTest.php
   (blocked_fixture_provider for the three blocked ones, allowed_fixture_provider for the safe one).

8. Verification: cd plugin && composer test && composer phpstan && composer phpcs.

Process:

1. Implementer (sonnet) writes failing fixtures first (TDD), then extends StaticGuard.
2. Spec reviewer verifies each bypass example in the prompt produces a diagnostic.
3. Quality reviewer attempts new bypasses (e.g. ReflectionFunction stored in a property,
   Closure::fromCallable with a method-array like ['SomeClass', 'someMethod']) and records any
   remaining gaps as a new follow-up task.
4. Mark Task 16 completed.

Constraint: don't break legitimate Reflection usage for class introspection — only the dispatching
forms (->invoke, ->invokeArgs) plus literal blocked-builtin constructors are worth catching.
```

---

## Order of execution

```
Task 4.QR  (closes Phase 1.2)
  ↓
Task 5     (Confirmation tokens)
  ↓
Task 6     (Capability checks)
  ↓
Task 7     (Truthful contracts)         ←──── Task 16 can run any time after Task 3
  ↓                                            (independent — no deps on 4–15)
Task 8     (Companion QA REST contract — needs 4 + 7)
  ↓
Task 9     (Elementor renderer — needs 7)
  ↓
Task 10    (Elementor widget builder — needs 3 + 9)
  ↓
Task 11    (Design ingestion — needs 7 + 8)
  ↓
Task 12    (Gutenberg + FSE — needs 7)
  ↓
Task 13    (Admin UX — needs 3 + 10)
  ↓
Task 14    (Docs — needs 10 + 12 + 13)
  ↓
Task 15    (Final verification — needs 14)
```

Some pairs are parallelizable in principle (e.g. Tasks 9 + 12 both need only 7, no cross-deps;
Task 16 has no deps on anything past Task 3). But each session is a separate fresh session — only
run two in parallel if you have two terminals open and accept the merge cost.
