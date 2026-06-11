# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[1.0.0-alpha.5]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.5
[1.0.0-alpha.4]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.4
[1.0.0-alpha.3]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.3
[1.0.0-alpha.2]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.2
[1.0.0-alpha.1]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.1
