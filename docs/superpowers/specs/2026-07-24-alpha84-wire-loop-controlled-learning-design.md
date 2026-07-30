# Alpha.84 wire-loop and controlled learning design

## Goal

Ship `1.0.0-alpha.84` as a focused Elementor execution release. Add one safe,
fast orchestration ability for wiring native Elementor Pro loop widgets and
record verified schema repairs through the existing knowledge-candidate
lifecycle without allowing unverified lessons to affect future tasks.

## Scope

### Included

1. A typed `stonewright/elementor-wire-loop` ability for native Loop Carousel
   and Loop Grid widgets.
2. Support for either an existing loop-item `template_id` or a validated
   `template_spec` that creates a new loop-item.
3. Live Elementor widget-schema discovery before translating compact intent
   into widget settings.
4. Dry-run, permission, confirmation, backup, locking, idempotency, readback,
   rollback, audit, and post-scoped cache invalidation.
5. Controlled automatic creation and verification of schema-repair candidates
   through `CandidateRepository`.
6. Task-start injection only after two verified successes from distinct tasks
   or explicit user approval.
7. Plugin and companion release metadata, public documentation, generated
   contracts, tests, archives, local refresh, and live verification.

### Explicitly excluded

- CPT, taxonomy, ACF field, post, or media creation. Existing content-model
  abilities remain responsible for data.
- Automatic conversion of an existing non-loop template into a loop item.
- Automatic repair or stripping of invalid settings from unrelated elements.
- Full Direct-mode parity. That is a separate release because it changes the
  companion execution surface.
- The Figma-to-Elementor tokens pack. That is a separate release because it
  needs a stable, site-aware design-token contract.

## Architecture

### Transactional orchestration service

`stonewright/elementor-wire-loop` is a thin ability over an internal
transaction service. The service composes existing Stonewright components
directly; it does not invoke registered abilities from inside another ability
and does not duplicate Theme Builder, batch-mutation, idempotency, backup, or
candidate-lifecycle logic.

The transaction has five phases:

1. inspect and validate;
2. compile the complete mutation in memory;
3. stage a new template when requested;
4. perform exactly one page-tree write;
5. verify, finalize, audit, and invalidate only affected CSS.

No persistent mutation occurs until the complete plan has validated.

### Typed ability contract

Required inputs:

- `post_id`: target Elementor page or template;
- `parent_id`: explicit existing V3 parent container;
- `display`: `carousel` or `grid`;
- `post_type`: query source;
- exactly one of `template_id` or `template_spec`;
- `idempotency_key`;
- `dry_run`.

Optional compact intent:

- query include/exclude, taxonomy, ordering, offset, and page-size intent;
- responsive columns or slides-to-show values;
- slides-to-scroll;
- arrows and pagination intent;
- `require_results`;
- `expected_tree_hash`;
- production-safe `confirmation_token`.

The public contract accepts intent, not copied raw Elementor settings. A
runtime schema adapter resolves intent to the installed Elementor Pro control
keys and value shapes. Unsupported intent fails with a structured error; the
adapter never guesses a setting name.

Output includes:

- `status`: planned, applied, replayed, or rolled_back;
- target page, parent, template, widget ID, and widget type;
- whether the template was existing or transaction-created;
- resolved settings and bounded tree diff for dry-run;
- pre-write and verified tree hashes;
- query probe count and warnings;
- readback checks;
- rollback result when applicable;
- audit and candidate references.

### Live schema and compatibility

Before compilation, the service reads the registered Elementor Pro widget and
its controls for `loop-carousel` or `loop-grid`. It verifies:

- Elementor Pro and the requested widget are available;
- the installed widget schema exposes a compatible template/query contract;
- the requested responsive and navigation intent can be represented;
- `post_type` is registered and queryable;
- the target document, parent, and template are accessible;
- `parent_id` resolves to an existing V3-only parent;
- an existing `template_id` is a valid loop-item template;
- a supplied `template_spec` passes
  `DesignSpec\Validator::validate()` before rendering.

The runtime schema fingerprint is included in planning, audit, idempotency, and
learning records. A schema change invalidates stale mappings and candidates.

### Existing-template path

An existing loop-item template is validated and referenced but never rewritten.
Only the target page is backed up and mutated. Failure before the page write
produces no persistent change; failure during or after the write restores the
page snapshot.

### New-template path

A `template_spec` is fully validated and rendered in memory first. The service
then:

1. creates a draft loop-item through `TemplateStore`;
2. marks it with a transaction owner identifier;
3. backs it up before any subsequent template mutation;
4. writes and reads back its Elementor data;
5. wires the page in one tree write;
6. verifies page-to-template linkage and query settings;
7. publishes the template only after page readback succeeds;
8. performs final readback.

Rollback restores the page and removes only the draft or published template
whose transaction owner matches the current operation. Existing templates are
never deleted. Force deletion is limited to a newly created, transaction-owned
artifact so no orphaned template metadata remains.

### Page mutation

The service reuses the V3 batch compiler and integrity gates to add one native
widget under the explicit parent. It does not rewrite the full document.

Mixed V3/V4 documents are accepted only when the requested parent and new
subtree are V3-only. Root insertion, V4 parents, missing parents, and mixed
target subtrees fail without mutation.

The operation:

- obtains the existing per-post write lock;
- checks the expected tree hash;
- resolves or replays the idempotency key;
- snapshots the page;
- compiles a stable widget ID before persistence;
- writes the page once;
- reads the page back and verifies widget ID, type, parent, template ID, query,
  responsive values, and resulting tree hash;
- invalidates CSS only for the affected page and newly created template.

Concurrent operations against the same page return a bounded busy response
with lock expiry information. Reusing an idempotency key with the same request
replays the verified result; reusing it with different intent is rejected.

## Query behavior

The query adapter emits only controls supported by the live widget schema. A
bounded preflight query checks whether the requested post type and filters can
return content.

An empty result is a warning by default because a valid layout may be prepared
before content exists. With `require_results: true`, an empty result fails
before any write.

The ability does not create missing content or silently broaden filters.

## Controlled automatic learning

### What can be learned

Learning is limited to a narrow, reproducible sequence:

1. a typed Elementor write rejects a widget control or value shape;
2. a later attempt uses corrected settings for the same widget, control,
   Elementor versions, and schema fingerprint;
3. page readback verifies the corrected effect.

The candidate contains only:

- widget and control identifiers;
- expected and rejected value types, never user content;
- installed Elementor/Pro versions and schema fingerprint;
- the corrected typed recipe;
- verification evidence and task identifier.

A failure alone remains an incident and cannot create an actionable lesson.

### Existing lifecycle reuse

An internal schema-repair learning service calls the existing
`CandidateRepository`; no parallel learning database and no duplicate public
candidate-management ability are introduced.

Candidate behavior:

- equivalent candidates deduplicate on widget, control, schema fingerprint,
  and corrected recipe;
- one successful readback records one verification;
- a second successful readback must come from a distinct task;
- only then may the existing lifecycle promote the candidate;
- explicit user approval with a note remains the alternative promotion path;
- conflicts, version drift, and schema changes leave candidates unresolved or
  stale;
- storage is bounded by retention and per-fingerprint limits.

### Task-start use

`stonewright-task-start` includes only approved, runtime-compatible candidates
relevant to the requested task and active tool profile. Injection is compact:
candidate ID, widget/control scope, corrected shape, compatibility fingerprint,
and evidence count.

Pending, conflicting, rejected, stale, or single-verification candidates are
never injected as instructions. They remain visible through the existing
knowledge-candidate inspection ability.

## Safety and permissions

- The ability uses `Permissions::edit_post( $post_id )`.
- Referencing an existing template also requires permission to read and use it.
- Creating a template requires the corresponding post-creation capability.
- Destructive rollback cleanup and any replacement-like operation honor
  production-safe confirmation-token rules.
- `Backup::snapshot_post()` runs before every page or template mutation.
- `DesignSpec\Validator::validate()` runs before template rendering.
- All mutations and rollback outcomes are written to the existing audit log.
- No raw REST write, direct `_elementor_data` update, WP-CLI eval, or shell
  workaround is introduced.
- Unknown Elementor settings are preserved; unrelated invalid settings are
  reported and never sanitized implicitly.
- Errors expose paths, types, IDs, and bounded examples, not content or secrets.

## Error behavior

- Missing Elementor Pro, widget, or compatible live schema fails before writes.
- Missing, invalid, V4, or non-V3-only parent fails before writes.
- An invalid existing template fails; it is never converted automatically.
- Template staging failure removes only a transaction-owned new template.
- Page-write or readback failure restores the page and removes a
  transaction-owned template.
- Template-publish or final-readback failure restores the page and removes the
  transaction-owned template.
- Readback mismatch identifies the exact failed invariant.
- Empty query results warn unless `require_results` is true.
- Lock contention returns busy plus bounded retry timing.
- Idempotency conflicts reject the request without mutation.

Every error reports its transaction phase, mutation state, rollback state, and
safe next action.

## Testing

Use test-driven development for each behavior:

1. contract and registration tests for both display modes;
2. dry-run tests proving zero persistent writes;
3. existing-template success for carousel and grid;
4. new-template success, staged publication, and transaction ownership;
5. live-schema translation and structured unsupported-control failures;
6. mixed-document success under a V3 parent and rejection for root/V4 targets;
7. missing Pro, invalid template, invalid post type, and empty-query behavior;
8. backup, permission, production-safe confirmation, and audit gates;
9. rollback injection tests at template creation, template write/readback, page
   write/readback, publication, and final verification;
10. readback mismatch tests for parent, widget, template, query, responsive
    settings, and hash;
11. concurrency, lock expiry, idempotent replay, and conflict tests;
12. schema-repair candidate tests proving failure alone does not teach;
13. candidate deduplication and two verified successes from distinct tasks;
14. explicit approval, conflict, retention, fingerprint invalidation, and
    task-start relevance tests;
15. post-scoped CSS invalidation and no unrelated/global cache clearing.

Then run the complete plugin and companion suites, static analysis, coding
standards, security and dependency audits, generated contract checks,
documentation freshness, archive inspection, and `git diff --check`.

## Documentation and release

1. Set plugin and companion versions to `1.0.0-alpha.84`.
2. Update affected README files, changelogs, architecture, capability examples,
   install prompts, roadmap, skills, and versioned release notes.
3. Regenerate the ability truth matrix and public contracts.
4. Build clean plugin ZIP and companion TGZ artifacts.
5. Inspect archive contents and compute SHA-256 checksums.
6. Install the plugin artifact on the configured local WordPress target and
   refresh the local companion without exposing credentials.
7. Restart or reload the MCP client.
8. Call `stonewright-task-start` first, then verify version, connection,
   ability contract, dry-run, controlled carousel/grid wiring, readback,
   rollback safety, and candidate gating.
9. Push a topic branch, open a pull request, fix every failing check, merge only
   after all required checks are green, and rebuild artifacts from the merged
   source if the merge commit changes packaged content.

Live WordPress mutation verification stops if `stonewright-task-start` is not
available after restart.

## Acceptance criteria

- One typed call can safely plan or wire a native Loop Carousel or Loop Grid.
- Existing loop-item templates are referenced without mutation.
- New loop-item templates are staged, verified, wired, and published
  transactionally.
- The target page receives exactly one surgical tree write.
- Failure at any mutation phase restores the page and removes only
  transaction-owned new artifacts.
- Live schema controls are used; unsupported settings are never guessed.
- Mixed pages allow only explicit V3-parent insertion.
- Locking and idempotency prevent lost or duplicated writes.
- Schema failures alone never become task instructions.
- Only approved, compatible, relevant candidates reach task-start.
- Automatic promotion requires verified success in two distinct tasks.
- Plugin and companion validation suites pass.
- ZIP and TGZ contain the expected runtime files and no development junk.
- Local plugin, companion, and live startup state report
  `1.0.0-alpha.84`.

## Later releases

### Direct parity

Define the exact typed-tool parity matrix first, then port shared contracts and
execution adapters into the companion without allowing REST writes or
duplicating WordPress business logic. Ship and validate it independently.

### Figma tokens pack

Define a normalized, versioned token contract for color, typography, spacing,
radius, and responsive values. Map that contract to a detected Elementor kit
through explicit previews and site-specific overrides. Keep external Figma
acquisition outside the plugin and companion, and ship the pack independently.
