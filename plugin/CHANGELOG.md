# Changelog

## [Unreleased]

## [1.0.0-alpha.50] - 2026-06-17

### Changed

- Kept direct WP-CLI background job tools visible in strict `low-tools`
  companion profiles while preserving the under-30 client-visible startup
  budget.

## [1.0.0-alpha.49] - 2026-06-17

### Added

- Added guarded WP-CLI background job tooling for long-running CLI work.
- Added compact companion MCP handshake guidance for first calls, recovery,
  low-tools clients, and guarded WP-CLI usage.

### Fixed

- Prevented stale `.env` `PORT` values from starting the optional HTTP bridge
  during stdio MCP startup unless HTTP mode is explicitly enabled.
- Updated install snippets and companion setup profiles to the alpha.49
  release package so new MCP sessions load the current tool surface.

## [1.0.0-alpha.44] - 2026-06-16

### Changed

- Added startup-readiness diagnostics to `stonewright-wordpress-mcp-status` so
  agents can see missing bootstrap, preflight, profile, or skills tools without
  broad discovery.

## [1.0.0-alpha.43] - 2026-06-16

### Changed

- Kept `stonewright/skills-get` visible in essential and compact tool profiles
  so agents can load one matched site playbook without full ability discovery.
- Updated workflow preflight to recommend `stonewright/skills-get` when matched
  skills or content-model specializations are present.

## [1.0.0-alpha.14] - 2026-06-12

### Changed

- Clarified install docs so `STONEWRIGHT_WP_ROOT` is optional and means the
  absolute WordPress install folder containing `wp-config.php`.
- Updated release metadata for the companion WP-CLI batch runner release.

## [1.0.0-alpha.13] - 2026-06-12

### Added

- Exposed prompt-enabled Stonewright skills as MCP prompts from the companion.
- Added a companion credential store fallback for per-project Application
  Password reuse across local agent sessions.

### Fixed

- Fixed Sandbox Library embedded and direct tab routing for Widgets and
  Generated Plugins.
- Fixed Sandbox Library View, Edit, Diff, Delete, Activate, Disable, and
  Deactivate action redirects.
- Fixed Elementor Icon Box rendering to use the current `selected_icon`
  setting schema.

## [1.0.0-alpha.12] - 2026-06-12

### Added

- Added a generated PHP cache for the Elementor widget manifest to avoid
  repeated large JSON decoding during widget catalog loads.
- Added one-section visual batching guidance with automatic continuation after
  desktop, tablet, and mobile verification passes.
- Added admin JavaScript tests for copy fallback and declarative button
  behaviour.

### Changed

- Context bootstrap now returns compact visual stubs for non-visual tasks.
- Admin copy, reveal, tab, panel, row, and skill controls now prevent accidental
  form submission.

### Fixed

- Fixed admin copy buttons when browser clipboard permissions reject writes.
- Fixed Memory admin delete confirmation button markup.

## [1.0.0-alpha.11] - 2026-06-11

### Added

- Added visual source-authority guidance so reference screenshots define layout
  matching while design-tool layers remain token, style, text, and asset hints.
- Added section reference screenshot evidence for long visual pages.

### Changed

- Strengthened Elementor implementation guidance to build native structures
  from the visible reference layout instead of mirroring broken design-tool
  hierarchy.

## [1.0.0-alpha.10] - 2026-06-11

### Added

- Added visual-build gate output to context bootstrap and workflow preflight so
  agents must provide design tokens, media reuse evidence, section plans,
  screenshot deltas, and logged-out viewport checks before visual signoff.

### Changed

- Strengthened agent and Elementor implementation guidance for native first-pass
  visual builds, asset reuse, and screenshot-driven corrections.

## [1.0.0-alpha.8] - 2026-06-11

### Fixed

- Made Composer dependency audit policy explicit for the WordPress Abilities API
  compatibility package so abandoned-package reporting does not fail releases
  when there are no vulnerability advisories.

## [1.0.0-alpha.7] - 2026-06-11

### Added

- Added Elementor widget schema tab grouping for Content, Style, Advanced, and
  unknown controls.
- Added Advanced tab guidance for positioning, z-index, motion effects,
  transform, backgrounds, borders, masks, responsive controls, attributes, CSS
  IDs/classes, order, alignment, width, margin, and padding.
- Added native Gutenberg/block-theme workflow guidance for `theme.json`,
  templates, template parts, patterns, block supports, and Create Block Theme
  handoff.

### Changed

- Updated built-in skills and agent instructions for schema-driven Elementor
  work, major-container naming, and native block-theme editing flows.

### Fixed

- Fixed ability truth matrix write/read detection so guidance strings do not
  mark read-only abilities as write operations.

## [1.0.0-alpha.6] - 2026-06-11

### Added

- Added official-docs-based specialization guidance for ACF, ACPT, Meta Box,
  ASE, Pods, and WooCommerce catalog workflows.
- Added content-model integration and WooCommerce catalog skills for bootstrap
  and preflight guidance.
- Added compact specialization data to context bootstrap and workflow preflight
  so agents spend fewer calls discovering plugin-specific workflows.

### Fixed

- Isolated sandbox admin tests from integration fixtures.

## [1.0.0-alpha.5] - 2026-06-11

### Fixed

- Made visual implementation tasks stop before the first write when external
  Playwright/browser MCP is missing.
- Expanded Antigravity and client onboarding with Playwright MCP setup, restart,
  and tool-list verification.
- Added fast-path guidance for spec/bundle first-pass Elementor builds to avoid
  slow repeated single-widget loops.

## [1.0.0-alpha.4] - 2026-06-11

### Fixed

- Restored PHP 8.1 syntax compatibility by replacing PHP 8.2 literal return
  types in sandbox, security, companion, and test support code.

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
