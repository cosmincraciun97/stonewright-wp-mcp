# Changelog

## [Unreleased]

### Fixed

- Elementor write abilities return the real gate/validator error via
  `ElementorData::write_error_for_ability()` instead of generic write_failed.
- Context-token error names `stonewright-task-start` first.
- V3 architecture mismatch and raw php-execute Elementor write remediation
  hints name concrete V4/V3 tools (no dead-end "V4 editor pipeline" text).

### Added

- Elementor Integrity Gate P0 on `ElementorData::write` (double-encode, size
  collapse, widgetType remap blocks; readback restore).
- Tree validation preserves unknown settings and coexisting `e-*` widgets.

## [1.0.0-alpha.77] - 2026-07-22

### Fixed

- Task-start always signals tools re-list when admin surface is full/essential or
  the session profile leaves bootstrap (stdio companion surface sync).

### Added

- Bootstrap MCP surface ≤12 tools with php-execute, confirmation, content and
  Elementor read tools, theme-file-read.
- `stonewright/theme-file-read` and `stonewright/theme-file-patch` allowlisted
  theme file abilities with backup and production confirmation.
- `php-execute` `read_only` flag; clearer Elementor write-vs-read policy.
- Task-start `write_target_url` / active write target labeling.
- Direct remote Elementor data path via REST meta when registered.

## [1.0.0-alpha.76] - 2026-07-16

### Added

- Direct mode: permanent product HARD RULES on every `stonewright-task-start`
  (single-target scope, remote tool path, no ad-hoc plugins, HTTP-first
  automation, additive content models) plus five new enabled `_builtin` skills.
- Plugin: permanent operating rules in agent instructions (not Safety Memory UI)
  covering the same workflow discipline plus Elementor native-first lessons
  (responsive typography, Nested Carousel offset, swiper overflow, CSS parent class).
- Built-in skill pack `agent-operating-rules` for matched task playbooks.

### Changed

- Direct `AGENTS.md` managed template includes the permanent operating rules.

## [1.0.0-alpha.75] - 2026-07-16

### Added

- Direct mode: `stonewright-content-create` creates items of any registered post
  type (**99** tools total).
- Direct mode: `stonewright-task-start` returns `session_tools` (exact enabled
  tool list) and structured `capabilities.content_model` guidance.

### Changed

- Direct mode: content and taxonomy tools auto-resolve `rest_base` from
  `/wp/v2/types` and `/wp/v2/taxonomies` (CPTs whose `rest_base` differs from
  the slug now work).
- Direct mode: site-discover and capability tiers state that Direct fully edits
  existing CPT content, taxonomy terms, and ACF field values; registering new
  models requires server-side PHP (plugin) — a WordPress REST limit, not a
  Stonewright gap.

## [1.0.0-alpha.74] - 2026-07-16

### Changed

- `task-start` exposes `configured_mcp_surface` and resolves task-specific tool
  recommendations without changing the admin-selected surface.
- Activating a site-wide tool profile now requires `manage_options`; read-only
  profile resolution remains available to authenticated readers.
- Bootstrap task-start binds its recommended profile to `Mcp-Session-Id`, so
  tools expand for that session without changing the saved site preference.

### Fixed

- Setup Apply-now verifies the persisted value, generated stdio snippets use it,
  and shared admin JavaScript changes invalidate browser caches.
- Elementor raw-write blocks are explicitly non-retryable and direct agents back
  to typed schema requests plus one consolidated batch dry-run.

## [1.0.0-alpha.73] - 2026-07-16

### Added

- Real FSE engine path on `blueprint-apply` (`engine=fse`) with constrained layout
  wrappers, `EditorSnapshot`, and `FseTransactionQueue` apply + readback/rollback.
- Brand-kit `preview=true` diff mode and unconditional option/theme_mod
  `restore_id` via `Backup::snapshot_options` / `restore_options`.
- Setup “Apply now” control for MCP tool surface with honest per-transport messaging.
- Blueprint render-output suite (bundled blueprints × engines) and extra e2e specs
  (blueprints, setup-profile, connect).
- DesignEvidence pixel-perfect fields (`measured_targets`, spacing/typography
  ramps, `figma_token_table`, layout intent); native plan per-element
  `native_mapping` / `native_gap` for elementor|gutenberg|fse; ImplementationContract
  `action=validate` rejects CSS without native_gap; front-end visual matrix e2e.
- All 12 bundled blueprints authored as DesignSpec 2.0.0 with content facts,
  native policy, and Elementor layout intent (`fullWidth`, `align_items`,
  `justify_content`). Elementor blueprint writes go through
  `ElementorTransactionRunner` full-tree/`replace_tree`. e2e applies real
  blueprints and screenshots the front-end.

### Changed

- Elementor blueprint writes use transactional full-tree path (snapshot +
  structural readback + rollback) via `ElementorWriter::write_transactional`.
- Marked `stonewright/task-start` as the canonical first call and retained
  `stonewright/context-bootstrap` as the full-context compatibility path.
- Elementor schema summaries rank useful controls first and accept a focused
  control query for compact validation repair.

### Fixed

- Elementor V3 batch dry-runs report all invalid operations together, return
  exact schema requests, and block every partial write.
- Typography aliases normalize to live keys with compact warnings.
- Admin e2e writes are serialized, run once, and restore shared settings.
- Visual e2e writes pass the required task context token, while nonce discovery
  avoids waiting on an absent optional DOM attribute.
- Prompt Library loads catalog card CSS; blueprint action buttons have spacing.

