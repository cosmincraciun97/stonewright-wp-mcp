# Changelog

## [Unreleased]

## [1.0.0-alpha.3] - 2026-06-11

### Added

- `stonewright/workflow-preflight` for compact one-call task setup.
- `stonewright/media-upload-batch` and `stonewright/elementor-v3-apply-bundle`
  for guarded batch workflows.
- `stonewright/elementor-v3-capabilities-summary` for native-widget planning.

### Fixed

- Pinned Composer's PHP platform to 8.1 for CI-compatible dependency installs.
- Added Elementor fixed-width row overflow diagnostics.

### Changed

- Expanded release installation docs and open-source packaging guidance.

### Added

- `stonewright/context-bootstrap` for mandatory per-task context loading.
- `stonewright/learning-record` for persisting user corrections into memory and
  optionally into an enabled skill.
- `stonewright/elementor-widget-implementation-guide` for native Elementor
  widget selection and full Content/Style/Advanced configuration guidance.
- `stonewright/wp-cli-status`, `stonewright/wp-cli-discover`, and
  `stonewright/wp-cli-run`.

### Removed

- Built-in design-tool ingestion abilities.
- Automated visual QA abilities and their PHP support classes.
- Companion contracts used only by removed browser audit and diff workflows.

### Changed

- Write abilities require the short-lived task context token unless explicitly
  exempted as read-only discovery.
- The companion contract now centers on health, MCP transport/proxy, and guarded
  WP-CLI execution.

## [1.0.0-alpha.2] - 2026-05-22

Elementor-first hardening milestone with expanded Elementor, Gutenberg, FSE,
memory, sandbox, and system abilities plus stronger security gates.

## [1.0.0-alpha.1] - 2026-05-21

Initial plugin release.
