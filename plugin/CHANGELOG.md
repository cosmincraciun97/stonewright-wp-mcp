# Changelog

## [Unreleased]

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

## [1.0.0-alpha.72] - 2026-07-16

### Added
- Actionable audit error codes/messages and RemediationHints at task-start.
- Direct Elementor data tools, gutenberg-validate, builtin skills, agents-md-sync.
- Direct task-start write gate and recurring error surfacing.

### Changed
- README Elementor-first with full capability tables.


## [1.0.0-alpha.71] - 2026-07-16

### Added
- Pluginless Direct self-improvement (local skills/memory + task-start).
- ACF, multi-plugin SEO, and CPT/taxonomy registration abilities.
- Discovery wiring for wave-3 admin ops profiles.
- Dual-mode AI install prompts.

### Fixed
- wp-admin paste prompt starts with task-start.
- REST parity security review items (audit redaction, search visibility, rest-request read-only).


## [1.0.0-alpha.70] - 2026-07-16

### Added

- `tool-profile` resolve action and priority-ordered profile tools (blueprints first).
- Strict blueprint `engine` gate; Gutenberg columns / alignfull / hero media-text.
- Brand surface scanner and 5-release retention policy tests.

### Changed

- Companion consumes plugin-resolved tool lists; falls back only offline/Direct.

## [1.0.0-alpha.69] - 2026-07-15

### Added

- Essential tools for blueprints, brand kits, digest, pulse, learning.
- HTML widget site hard-block; QA block on blueprint apply; rebuilt blueprint specs.
