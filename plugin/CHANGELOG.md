# Changelog

## [Unreleased]

### Added

- Admin page `stonewright-design-studio` with four views (overview, editor,
  quality, history) and REST routes under `stonewright/v1/design-studio`. Every
  route delegates to the typed design-direction and quality abilities, so
  permission checks, task context tokens, confirmation tokens, backups,
  validation, audit records, and effect readback are inherited rather than
  reimplemented. Reads require `can_read_design()`; writes require
  `can_manage_design()` and a `wp_rest` nonce.
- Design Studio front end (`assets/admin/design-studio.js`,
  `assets/admin/design-studio.css`): no framework, no jQuery, no native browser
  dialogs, and no markup built from stored content — every value reaches the DOM
  through `textContent`. Keyboard-operable tablist with URL-restored views,
  session-scoped draft recovery, a focus-trapping review drawer that returns
  focus on close, a polite live region, token-only theming for light and dark,
  and a reduced-motion path.

## [1.0.0-alpha.87] - 2026-07-25

### Added

- Persistent versioned Design Direction storage: two tables, append-only
  revision history, contract hashing in canonical key order, allowlist-only
  contract validation, and an active-direction option pointer.
- Abilities `stonewright/design-direction-list`, `-get`, `-save`, `-activate`,
  and `-restore`. Writes require `can_manage_design()` and the task context
  token; activate and restore additionally require a confirmation token bound to
  the direction id (and revision) in production-safe mode. Every write reads its
  effect back and reports the contract hash before and after.
- Ability `stonewright/design-direction-capture`, which maps compact Elementor
  kit evidence into a draft contract with provenance for every mapped token. It
  previews by default and needs an explicit `save` to store; a stored capture is
  a draft, is never marked ready, and never becomes the active direction. Absent
  evidence stays absent, contradictory evidence keeps the first value and reports
  a conflict, and unusable evidence is reported rather than dropped silently.
- Abilities `stonewright/design-direction-sync-plan` and `-sync-apply`, which
  compare a direction with the live Elementor kit and then write it. The plan
  half writes nothing and returns the exact operations plus `warnings` for
  unsupported kit groups and `blocked` for values the kit cannot store. The apply
  half requires the plan's `base_hash` and returns
  `stonewright_direction_sync_stale` when the kit moved, refuses any blocked
  value instead of coercing it, snapshots the kit through
  `Backup::snapshot_post()`, merges only the planned entry properties so unknown
  kit settings survive, and re-plans the kit before reporting success. In
  production-safe mode its confirmation token is bound to the direction id and
  the `base_hash`.
- `ElementorKitSyncPlanner` (pure contract-to-kit diff and CSS value grammar)
  and `ElementorKitWriter` (typed read/merge for `_elementor_page_settings`), so
  no sync code handles the serialized kit settings array directly.

- Ability `stonewright/design-quality-check`, which evaluates supplied browser
  evidence for a rendered page and returns coverage, findings, and repair hints.
  `QualityEvidenceValidator` holds the trust boundary with allowlist-only keys,
  hard bounds on viewports, elements, string length, and encoded size, and hex
  resolution through the same `BrandKit` math used elsewhere; a color it cannot
  measure is refused rather than guessed. Objective rules are errors, direction
  token-scale rules are warnings, and waivers downgrade a finding to `info`
  without deleting it. Evaluation is read-only under `can_view_design()`;
  `persist` requires `can_manage_design()`, the task context token, and both a
  post and a direction revision to bind the report to.
- `QualityReportStore`, a bounded per-post ledger of stored reports: newest
  first, 20 reports and 200 findings per report, numbers and rule ids only.
- Ability `stonewright/design-checkpoint-record` and the `DesignCheckpoint`
  workflow gate. `stonewright/elementor-v3-build-page-from-spec` gained optional
  `design_scope` and `checkpoint_token` inputs and stops a gated build that
  would leave more than one top-level section on the page. An unknown scope
  fails closed; an omitted scope reads as `preserve`. The token is an HMAC over
  the decision, signed with `wp_salt('auth')` plus a per-site secret, binding
  post, section, direction hash, and the approved section's render hash; it
  expires in two hours, is bound to the approving user, and authorizes the
  remaining sections of that one page. `checkpoint_token` joins the ability
  kernel's default audit redaction list.
- `fast_path.visual_checkpoint` on `stonewright/workflow-preflight` (and
  therefore `stonewright/task-start`), plus a matching default agent
  instruction, so the gate is announced before the first write rather than
  discovered by a rejected one.

### Fixed

- Saving a design direction from the Design Studio editor no longer drops the
  contract sections the editor does not expose. `components`, `provenance`,
  `waivers`, and `readiness` are carried over from the stored contract, so
  editing a summary no longer erases the provenance record or resets readiness
  to false and leaves an activated direction refusing to activate.
- The Design Studio live region keeps the result of a write on screen. A
  restore or an activation re-renders its view, and the render's "loaded"
  message used to replace the outcome before a screen reader could reach it.
- Design Studio history explicitly opts into revision versions when reading a
  direction, so real saved revisions are listed and remain restorable.

## [1.0.0-alpha.86] - 2026-07-24

### Fixed

- OAuth dynamic client registration no longer fails when the optional internal
  self-test header is absent.

## [1.0.0-alpha.85] - 2026-07-24

### Added

- OAuth 2.0 MCP authentication with PKCE, discovery, rotating refresh tokens,
  consent, Connected Apps, and 17-client onboarding.
- Independent `/mcp/stonewright-oauth` server while retaining Application
  Password authentication on `/mcp/stonewright`.

## [1.0.0-alpha.84] - 2026-07-24

### Added

- Add `stonewright/elementor-wire-loop` for transactional native Loop
  Grid/Carousel planning and writes with live schemas, staged templates,
  idempotency, readback, and rollback.
- Add controlled schema-repair learning that activates only after two distinct
  verified tasks or explicit approval.

### Changed

- Serialize Elementor page writes and bound learned repairs by runtime,
  retention, and task-start limits.

## [1.0.0-alpha.83] - 2026-07-24

### Added

- Bounded, content-free Elementor document health diagnostics for architecture,
  document weight, invalid settings, and excessive `e-paragraph` use.

### Fixed

- Elementor CSS invalidation is scoped to the changed post.
- Mixed documents permit surgical V3 batch writes under explicit V3 parents;
  root adds and full-document renderers remain blocked.
- Schema errors identify the rejected setting path and expected/received shape.
- The V4 abilities checkbox persists both checked and unchecked states.
