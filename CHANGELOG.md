# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha.10] - 2026-06-11

### Added

- Added a `visual_build_gate` contract to context bootstrap and workflow
  preflight so pixel-matching tasks require a token table, media reuse audit,
  section plan, screenshot deltas, and logged-out viewport checks before
  signoff.

### Changed

- Strengthened visual workflow docs and Elementor guidance for faster native
  first-pass builds, existing media reuse, section-sized fallbacks, and
  screenshot-driven fixes.

## [1.0.0-alpha.9] - 2026-06-11

### Added

- Added task-aware `stonewright/workflow-preflight` output with compact
  `task_profile`, hyphenated `recommended_mcp_tools`, and a `call_sequence`
  containing example arguments for common Elementor, WP-CLI, and
  production-safe destructive workflows.
- Added release documentation for the alpha.9 preflight fast-path contract.

### Changed

- Updated the public README, plugin README, and installation docs to describe
  the task-aware preflight response shape.

## [1.0.0-alpha.8] - 2026-06-11

### Fixed

- Made Composer dependency audit policy explicit for the WordPress Abilities API
  compatibility package so abandoned-package reporting does not fail releases
  when there are no vulnerability advisories.

## [1.0.0-alpha.7] - 2026-06-11

### Added

- Added Elementor widget schema tab grouping so agents can inspect Content,
  Style, Advanced, and unknown controls before writing widget settings.
- Added shared Advanced tab guidance for positioning, z-index, motion effects,
  transform, background, borders, masks, responsive controls, attributes, CSS
  IDs/classes, order, alignment, width, margin, and padding.
- Added Gutenberg/block-theme workflow guidance for `theme.json`, templates,
  template parts, patterns, block supports, and Create Block Theme handoff.

### Changed

- Strengthened the public README introduction with release badges and a compact
  Stonewright capability summary.
- Updated agent instructions and built-in skills to favor schema-driven
  Elementor work, semantic naming for major parent containers, and native
  block-theme editing flows.

### Fixed

- Fixed ability truth matrix write/read detection so tool references inside
  guidance strings do not mark read-only abilities as write operations.

## [1.0.0-alpha.6] - 2026-06-11

### Added

- Added official-docs-based specialization guidance for ACF, ACPT, Meta Box,
  ASE, Pods, and WooCommerce catalog work.
- Added content-model and WooCommerce built-in skills so task bootstrap can
  steer agents toward the right REST, WP-CLI, and native WordPress surfaces.
- Added preflight specialization summaries to reduce discovery loops before
  custom-field, content-model, and catalog edits.

### Fixed

- Isolated sandbox admin tests from integration fixtures so the full PHPUnit
  suite stays deterministic.

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
- `stonewright/media-upload-batch` for per-item batch media uploads.
- `stonewright/elementor-v3-capabilities-summary` for fast native-widget planning.
- `stonewright/elementor-v3-apply-bundle` for guarded multi-post Elementor writes.
- Tag-driven GitHub release packaging for plugin ZIP, companion TGZ, and checksums.

### Fixed

- Pinned Composer's PHP platform to 8.1 so CI installs compatible dev dependencies.
- Preferred LocalWP WP-CLI phars near the WordPress root before generic cache phars.
- Added fixed-width Elementor row overflow diagnostics.

### Changed

- Expanded release and installation docs for GitHub release downloads.
- Moved public widget registry data into a neutral documentation path and removed internal planning notes from tracked docs.

### Added

- Mandatory task bootstrap through `stonewright/context-bootstrap`, returning
  active instructions, persistent memory, enabled skills, relevant knowledge,
  and a short-lived context token for write abilities.
- `stonewright/learning-record`, so user corrections can be stored as persistent
  memory and optionally appended to an active skill.
- Elementor widget implementation guidance that forces native widget selection,
  Content/Style/Advanced configuration, and official documentation research
  when internal docs are insufficient.
- Full companion-backed WP-CLI support:
  - `stonewright/wp-cli-status`
  - `stonewright/wp-cli-discover`
  - `stonewright/wp-cli-run`
- Companion WP-CLI runner with argv validation, allowed root checks, timeout
  handling, JSON parsing, and blocked arbitrary PHP/shell command groups.

### Removed

- Built-in design-tool ingestion from Stonewright.
- Automated visual QA, browser audit, accessibility audit, layout diff, and
  screenshot/diff abilities from Stonewright.
- Companion modules and contracts used only for removed ingestion/QA workflows.
- Obsolete skill packs and operational plans that told agents to run removed
  workflows.

### Changed

- The companion is now focused on health, optional MCP HTTP/proxy transport, and
  guarded WP-CLI execution.
- Active documentation now points agents to persistent context, Elementor native
  widget discipline, and WP-CLI acceleration.

## [1.0.0-alpha.2] - 2026-05-22

Elementor-first hardening milestone. This release expanded Elementor, Gutenberg,
FSE, memory, sandbox, and system abilities, and introduced the security envelope
around permissions, backups, validators, confirmation tokens, and audit logging.

## [1.0.0-alpha.1] - 2026-05-21

Initial tagged release of Stonewright WP MCP.

[1.0.0-alpha.10]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.10
[1.0.0-alpha.9]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.9
[1.0.0-alpha.8]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.8
[1.0.0-alpha.7]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.7
[1.0.0-alpha.6]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.6
[1.0.0-alpha.5]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.5
[1.0.0-alpha.4]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.4
[1.0.0-alpha.3]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.3
[1.0.0-alpha.2]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.2
[1.0.0-alpha.1]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.1
