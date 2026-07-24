# Elementor alpha.83 concentrated repair design

## Goal

Ship `1.0.0-alpha.83` as a focused Elementor reliability release. Remove global
cache invalidation from normal Stonewright writes, make mixed V3/V4 guidance
accurate, expose bounded document health diagnostics, improve schema errors,
and prove the Elementor V4 dashboard toggle works in both directions.

## Scope

### Included

1. Post-scoped Elementor cache invalidation for every Stonewright Elementor
   write path.
2. Removal of duplicate cache invalidation from full-tree transactions.
3. A read-only `stonewright/elementor-document-health` ability.
4. Surgical V3 guidance for mixed documents while high-level/full-tree writes
   remain blocked.
5. First-violation detail in Elementor settings errors.
6. Explicit and tested V4 dashboard toggle persistence.
7. Plugin and companion version bump, public documentation, generated
   contracts, changelogs, build artifacts, local installation, restart, and
   live verification.

### Deferred

- One-shot Loop Carousel orchestration.
- Full Direct-mode parity with plugin-side typed Elementor tools.
- Automatic learning persistence after schema failures.
- A Figma-to-Elementor token pack.
- Automatic `e-paragraph` conversion.
- Automatic removal of unknown or invalid saved Elementor settings.

These are independent features or destructive repair workflows. They need
separate contracts and tests.

## Architecture

### Post-scoped cache invalidation

Create one internal cache invalidation service used by Elementor data writes,
page-settings writes, and full-tree transactions.

The service:

- cleans the WordPress cache for the edited post;
- clears only the edited post through Elementor's post CSS manager when the
  runtime supports it;
- removes the edited post's generated CSS marker when required;
- never calls Elementor's global `files_manager->clear_cache()` during a normal
  Stonewright write;
- returns bounded diagnostic metadata for tests and write metrics;
- treats missing Elementor runtime APIs as a safe no-op.

Full-tree transactions must not perform a second cache clear after
`ElementorData::write()`.

### Document health ability

Add `stonewright/elementor-document-health` with `post_id` and bounded
`max_issues` input.

Output includes:

- serialized `_elementor_data` bytes and KiB;
- total element, container, widget, V3, and V4 counts;
- architecture: empty, V3, V4, or mixed;
- widget counts by type;
- IDs and count of `e-paragraph` nodes;
- bounded invalid-setting findings with element ID, widget type, setting path,
  violation code, expected shape, and received type;
- health warnings for large documents, excessive atomic paragraph nodes,
  mixed architecture, and invalid settings;
- truncation state when issue limits are reached.

The ability is read-only, uses `Permissions::edit_post()`, never repairs data,
and avoids returning full document content.

### Mixed-document routing

Mixed documents remain blocked for full-tree replacement, high-level
DesignSpec rendering, and implicit conversion.

`stonewright-task-start` and workflow preflight must instead report:

- `surgical_v3_allowed: true`;
- `high_level_write_blocked: true`;
- `write_target: v3-surgical`;
- `stonewright/elementor-v3-batch-mutate` as the write tool;
- a requirement to target an existing V3-only parent or subtree.

`elementor-v3-batch-mutate` remains the enforcement boundary. It rejects
targets that are V4 or mixed and permits additions/updates inside V3-only
subtrees. Root additions to mixed documents are rejected because they have no
reviewed V3 parent.

### Schema error detail

Keep the existing structured `violations` payload. Change the top-level error
message to include the first violation:

`Elementor setting <path> rejected: expected <expected>; received <type>.`

No setting values are echoed. Unknown settings remain preserved by final-tree
integrity checks. Stonewright does not strip residual keys automatically.

### V4 dashboard toggle

The settings form submits an explicit false value when the checkbox is
unchecked and true when checked. The registered sanitizer remains boolean.

Tests cover:

1. unchecked save persists false;
2. checked save persists true;
3. rendered checkbox reflects persisted state;
4. `V4FeatureGate` blocks when false and allows when true;
5. Elementor V3/V4 status abilities report the same saved state.

Changing the flag does not change the MCP tool surface. It changes execution
gates and status truth immediately; clients still re-list tools after the
release/restart sequence.

## Safety contracts

- Every Elementor mutation keeps its pre-write snapshot.
- No raw `_elementor_data` writes are introduced.
- Mixed documents never undergo implicit conversion.
- Unknown settings are never stripped to pass validation.
- Production-safe confirmation and permission gates remain unchanged.
- Health diagnostics are bounded and read-only.
- Public errors expose types and paths, not content or credentials.

## Testing

Use test-driven development for each behavior:

1. failing cache tests proving normal writes do not call global clear and do
   call the post-scoped path once;
2. failing full-tree test proving cache invalidation is not duplicated;
3. failing toggle persistence and gate/status tests;
4. failing schema-message test;
5. failing mixed-document routing and root-add rejection tests;
6. failing document-health fixtures for V3, V4, mixed, malformed settings,
   issue truncation, and `e-paragraph` inventory.

Then run the complete plugin and companion validation suites, documentation
freshness, generated contract checks, and `git diff --check`.

## Release and installation

1. Set plugin and companion versions to `1.0.0-alpha.83`.
2. Update root, plugin, and companion changelogs plus affected public docs.
3. Regenerate the ability truth matrix and public contracts.
4. Build the clean WordPress ZIP and companion TGZ.
5. Inspect archive contents and compute checksums.
6. Install the plugin artifact on the configured local WordPress target and
   install the new local companion package without exposing credentials.
7. Restart the MCP client/process.
8. Verify `stonewright-task-start` first, then companion version, connection,
   V4 toggle state, document health, mixed-document guidance, and a controlled
   Elementor write/readback.

Live WordPress mutation verification stops if `stonewright-task-start` is not
available after restart.

## Acceptance criteria

- Normal Elementor writes perform no global Elementor cache clear.
- One edited post receives at most one post-scoped cache invalidation per
  transaction.
- The next Elementor editor load does not rebuild unrelated posts' CSS because
  of a Stonewright write.
- V4 toggle saves and reports both enabled and disabled states correctly.
- Mixed documents advertise and enforce only surgical V3 batch mutation.
- Schema failures identify the first rejected setting without leaking values.
- Document health reports bounded, actionable evidence.
- Plugin and companion checks pass.
- ZIP and TGZ contain the expected runtime files and no development junk.
- The local WordPress plugin, local companion, and live startup state report
  `1.0.0-alpha.83`.
