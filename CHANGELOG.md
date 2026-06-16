# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha.29] - 2026-06-16

### Changed

- `stonewright/tool-profile` now filters recommendations to actually enabled
  abilities and reports missing profile tools with MCP names plus recovery
  hints, preventing agents from silently planning around disabled or gated
  tools.

## [1.0.0-alpha.28] - 2026-06-16

### Changed

- Direct WordPress MCP discovery now defaults to essential tools mode when the
  option is unset, keeping new sessions on the compact fast-path surface unless
  a user explicitly opts into the full registered ability list.
- Admin settings, REST settings, workflow preflight, and tool profile responses
  now report essential tools mode as enabled by default for consistent
  token-efficient startup guidance.

## [1.0.0-alpha.27] - 2026-06-16

### Changed

- `stonewright/workflow-preflight` now omits the full Elementor capability
  summary for content-model, Gutenberg, WP-CLI, and site-admin tasks, returning
  a compact `elementor.included=false` stub with the follow-up tool to call only
  if Elementor context becomes needed.
- ACF, CPT UI, Pods, WooCommerce, and other content-model preflights now stay
  smaller while preserving the same batch-first WP-CLI and bulk-upsert guidance.

## [1.0.0-alpha.26] - 2026-06-16

### Changed

- Companion proxied WordPress MCP tools now default to the compact `essential`
  profile when `STONEWRIGHT_MCP_TOOL_PROFILE` is unset, reducing first-session
  tool surface and token use for clients that omit setup-profile env guidance.
- Explicit `STONEWRIGHT_MCP_TOOL_PROFILE=full`, `all`, `off`, `false`, or `0`
  still proxies every WordPress MCP tool for specialist sessions.

## [1.0.0-alpha.25] - 2026-06-16

### Changed

- Companion startup now also degrades gracefully when WordPress MCP config
  resolution fails before proxy discovery, such as malformed site URLs during
  local auto-credential checks.
- `stonewright-wordpress-mcp-status` reports configuration-resolution failures
  while setup-profile and direct WP-CLI tools remain visible.

## [1.0.0-alpha.24] - 2026-06-16

### Added

- Added `stonewright-wordpress-mcp-status`, a local companion diagnostic tool
  that stays available even when the proxied WordPress MCP endpoint is down or
  misconfigured.

### Changed

- Companion startup now degrades gracefully when WordPress MCP proxy discovery
  fails, keeping setup-profile and direct WP-CLI tools visible so agents can
  recover without losing the MCP session.
- Setup profile now includes `stonewright-wordpress-mcp-status` in tool
  visibility checks.

## [1.0.0-alpha.23] - 2026-06-16

### Changed

- Switched generated companion setup snippets from the unpublished npm package
  name to the versioned GitHub release tarball, so fresh MCP sessions can start
  without npm publishing credentials.
- Added a companion app-version constant and wired MCP server info,
  WordPress-MCP client initialization, and HTTP health responses to the current
  package version instead of the old alpha.1 placeholder.

## [1.0.0-alpha.22] - 2026-06-16

### Added

- Added companion-side proxied tool profiles with `STONEWRIGHT_MCP_TOOL_PROFILE`
  so new stdio MCP sessions can start on a compact fast-path surface instead of
  registering every WordPress MCP tool.

### Changed

- The companion now keeps direct `stonewright-wp-cli-*` tools local and
  deduplicated when the WordPress MCP endpoint exposes abilities with the same
  names.
- Updated setup profile, admin snippets, and docs to recommend
  `STONEWRIGHT_MCP_TOOL_PROFILE=essential` for faster startup and lower-token
  WordPress, Elementor, Gutenberg, content-model, and WP-CLI workflows.

## [1.0.0-alpha.21] - 2026-06-16

### Changed

- Extended `stonewright-setup-profile` with `first_calls` and
  `tool_visibility_checks` so new MCP sessions can verify bootstrap,
  preflight, tool-profile, and direct WP-CLI aliases before real work.
- Updated the admin copy-paste setup prompt and client docs to call
  `stonewright-tool-profile` for tool-cap, slow-startup, or token-sensitive
  clients before broad discovery.

## [1.0.0-alpha.20] - 2026-06-16

### Added

- Added `stonewright/tool-profile`, a compact read-only MCP tool that returns
  task-aware profiles for Elementor design, content-model, Gutenberg, WP-CLI,
  and site-admin work so agents can stay under tool caps and avoid broad
  rediscovery.
- Added `tool_profile_hint` to context bootstrap and a `tool-profile` step to
  workflow preflight so new sessions lock onto the right compact tool set
  before implementation.

### Changed

- Improved Elementor V3/V4 status responses with `v4_write_ready`,
  `recommended_renderer`, and `agent_action` so agents fall back to native V3
  tools when V4 atomic writes are not really available.
- Updated Stonewright docs and skills to route token-sensitive clients through
  the new tool-profile fast path.

## [1.0.0-alpha.19] - 2026-06-16

### Added

- Added `stonewright/design-implementation-contract`, a compact read-only MCP
  tool that gives agents a global-styles-first, section-batch, native-widget,
  token-efficiency, and hard-failure contract for design implementation work.
- Added the design implementation contract to context bootstrap, workflow
  preflight, Elementor capabilities summary, default agent instructions, and
  the ability truth matrix so new MCP sessions receive the same fast-path
  guidance before writes.

### Changed

- Tightened strict DesignSpec style fidelity checks so radius aliases are
  treated as decorative styles and require measured provenance.

## [1.0.0-alpha.18] - 2026-06-16

### Added

- Added strict DesignSpec style fidelity validation for design-derived builds.
  When `style_policy` is `strict`, decorative borders, radius, shadows, and
  filters require measured style provenance before render.
- Added compact MCP guidance for section-by-section visual builds that avoids
  invented card chrome and keeps fast paths on batch writes.

### Changed

- Updated the Elementor Basic Gallery renderer to use native gallery link and
  lightbox controls for media-file galleries.
- Improved companion WP-CLI output controls and batch command flow for faster,
  lower-token CPT UI, ACF, post, meta, option, and plugin command work.

## [1.0.0-alpha.17] - 2026-06-16

### Changed

- Strengthened the generated MCP setup note, default agent instructions, and
  companion setup profile so agents verify `stonewright-context-bootstrap` is
  visible before WordPress work.
- Updated onboarding, installation, admin client-connection docs, and README
  prompts to stop when the Stonewright MCP server is not loaded instead of
  treating local skills, repository files, private client config files, or
  manual endpoint calls as substitutes.
- Clarified that agents should not call
  `/wp-json/stonewright/v1/abilities/run` from shell as an MCP workaround.

## [1.0.0-alpha.16] - 2026-06-15

### Added

- Added a guided Configuration flow with a Stonewright brand banner, in-page
  Application Password generation, copyable client setup notes, per-client JSON
  snippets, and real-world prompt examples.
- Added admin controls for revoking existing Application Passwords and a
  browser-generated bridge token helper for the optional local WP-CLI bridge.
- Added default MCP instructions that keep site-specific memory, skills, and
  custom instructions local to the current WordPress install.

### Changed

- Simplified client setup docs around the `npx @stonewright/companion@latest`
  path and moved the companion HTTP bridge into an advanced-only workflow.
- Updated public docs with ACF, CPT UI, WooCommerce, Gutenberg/FSE, and Figma
  to Elementor V3 prompt examples.

## [1.0.0-alpha.15] - 2026-06-15

### Added

- Added Safe Fast Apply v1 for Elementor with
  `stonewright/elementor-v3-batch-mutate`, allowing many guarded add, update,
  move, and remove operations on one page in one request.
- Added `stonewright/content-bulk-upsert-posts` for fast CPT, post, and custom
  field seeding without repeated WP-CLI/meta calls.
- Added an essential tools mode so agents can expose a smaller high-value tool
  set for lower token use and faster discovery.
- Added `stonewright-setup-profile` in the companion for cross-platform MCP
  setup guidance on Windows, macOS, and Linux.

### Changed

- Extended `stonewright/elementor-v3-build-page-from-spec` with dry runs,
  timing metrics, diagnostics, element counts, and `replace`, `append`, and
  `replace_section` write modes.
- Updated workflow preflight, skills, and installation docs so agents prefer
  spec, batch, and bulk content paths before single-widget or repeated WP-CLI
  writes.

### Fixed

- Treated idempotent Elementor page-settings writes as successful when the
  stored value already matches the requested value.

## [1.0.0-alpha.14] - 2026-06-12

### Added

- Added a UTF-8 safe companion WP-CLI batch runner for repeated argv-based
  commands, exposed as `stonewright-wp-cli-batch-run`,
  `companion_wp_cli_batch_run`, and `POST /wp-cli/batch`.
- Added guidance for agents to use the batch runner instead of large inline
  shell scripts when writing repeated WordPress, CPT, ACF, media, or taxonomy
  data with non-ASCII content.

### Changed

- Clarified onboarding docs so `STONEWRIGHT_WP_ROOT` is described as an
  optional absolute WordPress install folder containing `wp-config.php`, not a
  required URL or plugin path.

## [1.0.0-alpha.13] - 2026-06-12

### Added

- Added MCP prompt discovery for prompt-enabled Stonewright skills.
- Added a companion credential store fallback for per-project Application
  Password reuse across local agent sessions.

### Fixed

- Fixed Sandbox Library routing, Sandbox action redirects, active MU plugin
  detection, and Elementor Icon Box settings.
- Confirmed admin copy buttons, skill panels, memory panels, sandbox actions,
  and ability controls in browser QA.

## [1.0.0-alpha.12] - 2026-06-12

### Added

- Added an OPcache-friendly PHP manifest cache for the Elementor widget catalog
  so large widget metadata can load without decoding the full JSON manifest on
  every request.
- Added visual section batching guidance so design-derived pages are built in
  one-section passes, or two sections only when simple and tightly coupled, with
  automatic continuation after desktop, tablet, and mobile checks pass.
- Added admin JavaScript regression coverage for copy buttons and declarative
  controls.

### Changed

- Context bootstrap and workflow preflight now omit heavy visual contracts for
  non-visual tasks, reducing tokens for routine WordPress operations.
- Elementor responsive rendering now preserves additional breakpoint-specific
  sizing and spacing controls.
- Admin copy, reveal, tab, panel, row, and skill controls now prevent accidental
  form submission.

### Fixed

- Fixed admin copy buttons when the Clipboard API rejects writes by falling back
  to a temporary textarea copy path and prompt fallback.
- Fixed Memory admin delete confirmation markup so confirmation handling reliably
  targets a submit button.

## [1.0.0-alpha.11] - 2026-06-11

### Added

- Added source-authority guidance to visual build gates so reference
  screenshots are the layout truth, while design-tool layers remain hints for
  styles, tokens, text, and assets.
- Added section reference screenshot evidence for long visual pages so agents
  compare complex designs section-by-section before full-page signoff.

### Changed

- Updated Elementor and design-to-WordPress guidance to avoid copying broken
  design-tool hierarchy into WordPress structure when the visible layout calls
  for a cleaner native implementation.

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

[1.0.0-alpha.27]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.27
[1.0.0-alpha.26]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.26
[1.0.0-alpha.25]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.25
[1.0.0-alpha.24]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.24
[1.0.0-alpha.23]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.23
[1.0.0-alpha.22]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.22
[1.0.0-alpha.21]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.21
[1.0.0-alpha.20]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.20
[1.0.0-alpha.19]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.19
[1.0.0-alpha.18]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.18
[1.0.0-alpha.17]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.17
[1.0.0-alpha.16]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.16
[1.0.0-alpha.15]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.15
[1.0.0-alpha.14]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.14
[1.0.0-alpha.13]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.13
[1.0.0-alpha.12]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.12
[1.0.0-alpha.11]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.11
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
