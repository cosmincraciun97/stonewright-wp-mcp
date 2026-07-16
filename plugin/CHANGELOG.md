# Changelog

## [Unreleased]

### Added

- Real FSE engine path on `blueprint-apply` (`engine=fse`) with constrained layout
  wrappers, `EditorSnapshot`, and `FseTransactionQueue` apply + readback/rollback.
- Brand-kit `preview=true` diff mode and unconditional option/theme_mod
  `restore_id` via `Backup::snapshot_options` / `restore_options`.
- Setup “Apply now” control for MCP tool surface with honest per-transport messaging.
- Blueprint render-output suite (bundled blueprints × engines) and extra e2e specs
  (blueprints, setup-profile, connect).

### Changed

- Elementor blueprint writes use transactional full-tree path (snapshot +
  structural readback + rollback) via `ElementorWriter::write_transactional`.
- Marked `stonewright/task-start` as the canonical first call and retained
  `stonewright/context-bootstrap` as the full-context compatibility path.

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

## [1.0.0-alpha.68] - 2026-07-15

### Fixed

- Audit payload overflow inside fixed table layout.
- Mode pill contrast on light theme (header chrome always light-on-dark).
- Blueprint Copy AI Prompt now pastes a full multi-line tool playbook.

## Older releases

Older release notes were removed under the 5-release retention policy. See `docs/releases/` for the retained notes and `docs/licensing.md` for permanent licensing history.
