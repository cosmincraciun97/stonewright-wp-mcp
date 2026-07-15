# Changelog

## [Unreleased]

## [1.0.0-alpha.68] - 2026-07-15

### Fixed

- Audit payload overflow inside fixed table layout.
- Mode pill contrast on light theme (header chrome always light-on-dark).
- Blueprint Copy AI Prompt now pastes a full multi-line tool playbook.

## [1.0.0-alpha.67] - 2026-07-14

### Added

- Added the canonical `stonewright/task-start` gateway, truthful token-budget
  enforcement, provenance linting, and PHP 8.1-8.5 CI coverage.
- Added architecture-aware Elementor task routing and explicit target selection
  for empty documents on Elementor 4 runtimes.

### Changed

- Bundled expertise without exact live runtime evidence now stays candidate;
  promotion requires fixture, schema, editor, frontend, and readback proof.
- Real compact task start now measures 634 non-visual and 837 visual tokens,
  including architecture routing.
- Visual DesignEvidence now requires verifiable source hashes, measured node
  bounds, and responsive viewport evidence.

### Fixed

- Restored draft skill reactivation from the admin and skills API.
- Preserved inactive Elementor controls during full-tree validation while
  keeping newly supplied settings strict.
- Rejected missing or duplicate Elementor node IDs before writes and kept
  revision backups on the revision instead of redirecting them to its parent.
- Rejected Atomic widgets in V3 trees, corrupted Romanian Unicode remnants,
  placeholder copy, and permissive non-equal write readback.
- Added actionable batch failures and readback rollback to V3 page builds.
- Preserved schema-validated native `flex_wrap` and `_flex_*` container
  controls and rejected normalized no-op updates instead of reporting silently
  discarded layout settings as applied.
- Inferred native responsive container controls when Elementor omits that flag
  from its live schema, preserving explicit mobile/tablet layout overrides.

### Security

- Blocked raw Elementor document mutation through `php-execute`; typed write
  abilities remain the only supported mutation path.

## [1.0.0-alpha.65] - 2026-07-14

### Added

- Added reproducible MCP tool-surface and task-start token measurements.
- Added optimistic Elementor tree hashes, 24-hour idempotency, strict
  per-setting evidence, and readback/restore verification to batch mutation.
- Added live structural schema validation for container, section, and column
  settings, including the final pre-persistence tree guard.
- Added `stonewright/design-native-plan` with DesignEvidence 1.0 normalization,
  semantic action validation, deterministic native mapping, and an approval-
  gated phase-two customization proposal.
- Added DB-backed knowledge candidates with provenance, TTL, version
  fingerprints, verification and conflict gates, plus skill revision rollback.
- Added 10 versioned P0 ExpertisePacks, compact top-three task routing, lazy
  pack retrieval, deterministic scorecards, and promotion lifecycle gates.
- Added a live/versioned Elementor V4 Atomic schema repository, recursive mixed
  tree inspection, official class/variable adapters, and owned fixtures.
- Added `stonewright/elementor-v4-migrate` with explicit zero-loss reporting,
  snapshot, readback hash, and automatic restore on mismatch.
- Added setup diagnostics and a direct Streamable HTTP configuration for
  remote clients that do not need Node or the optional companion.
- Added P1/P2 expertise for Elementor Pro, advanced Gutenberg, forms, data
  plugins, WooCommerce templates, shortcodes/snippets, SEO, and other builders,
  with explicit draft and discovery-only states where writes are unverified.

### Changed

- Relicensed the plugin to AGPL-3.0-or-later before importing Novamira-derived
  source.
- Reduced compact context-bootstrap responses below the documented task-start
  token budgets.
- Reduced the default registered ability surface from 65 to 20 composite and
  discovery tools; full mode still exposes maintainer and compatibility tools.
- Replaced the duplicate 5.85 MB widget manifest with a compact index and 95
  lazy PHP shards, plus `stonewright/elementor-schema` for live discovery.
- Added runtime widget-setting validation and a final pre-persistence tree gate
  so unknown Elementor V3 keys cannot reach `_elementor_data`.
- Deprecated generated per-widget add abilities for new plans; the live schema
  plus unified batch compiler is now the recommended V3 write path.
- Changed learning records to memory-only by default; optional skills remain
  disabled drafts until two verified successes or explicit user approval.
- Replaced V4 placeholder and unknown-property fallbacks with structured hard
  errors and exact typed Atomic envelopes.

### Fixed

- Snapshot restore now removes tracked meta keys that did not exist when the
  snapshot was taken.

- Corrected the Elementor Pro Gallery required setting to the live `gallery`
  control instead of the Basic Gallery-only `wp_gallery` key.
- Removed host-specific Elementor source paths from generated widget data and
  made the inventory generator accept an explicit plugins directory.
- Cleaned PHPUnit temporary files and removed PHP 8.5 reflection deprecations.

### Security

- Added production-safe confirmation, response limits, result normalization,
  runtime cleanup, and payload-free audit metadata to `php-execute`.

## [1.0.0-alpha.64] - 2026-06-17

### Added

- Added Codex as a first-class client in admin connection snippets, including
  copyable `config.toml` output and release-refresh guidance.
- Added Codex getting-started documentation for `~/.codex/config.toml`,
  `/mcp` validation, and stale companion detection.

### Changed

- Updated active setup snippets and docs to `1.0.0-alpha.64`.
- Synchronized the companion runtime version with the package version so
  `stonewright-setup-profile` and `stonewright-wordpress-mcp-status` report the
  current release package.
- Updated public project and plugin descriptions to emphasize WordPress MCP
  builder tools, PHP runtime execution, Elementor/Gutenberg/content models, and
  tokenized WP-CLI.

### Fixed

- Fixed the skills table schema so `description` no longer sets a default value
  on a `TEXT` column, avoiding MySQL warnings during fresh activation.

## [1.0.0-alpha.63] - 2026-06-17

### Added

- Added `stonewright/theme-builder-apply-template` for one-call Elementor Theme
  Builder template rendering, display-condition application, and repair hints.
- Added `stonewright/content-model-loop-grid-flow` for CPT/ACF-backed Loop Grid
  sections and editable repeated content flows.

### Changed

- Enriched Design Spec validation errors with exact paths and repair examples
  so smaller models can correct invalid payloads faster.
- Added setup/profile metadata for detecting stale MCP clients after release or
  skill updates.
- Bumped the plugin version to `1.0.0-alpha.63`.

## [1.0.0-alpha.62] - 2026-06-17

### Added

- Added `stonewright/php-execute` as the dedicated full WordPress runtime
  execution ability for short PHP snippets inside the loaded WordPress process.
- Added runtime guidance to compact profiles, workflow preflight, context
  bootstrap, public docs, skills, fixtures, and ability matrix coverage.

### Changed

- Updated agent-facing guidance to prefer PHP runtime access for compact
  implementation loops while keeping WP-CLI tokenized and keeping backups,
  permissions, confirmation tokens, validators, audit logging, and command
  blocking intact.

### Fixed

- Classified `stonewright/php-execute` as a write-capable ability in the
  generated truth matrix and added regression coverage.
- Synchronized the installed Codex Stonewright skill with the PHP runtime
  guidance.

## [1.0.0-alpha.61] - 2026-06-17

### Added

- Added read-only `stonewright/site-shortcodes-discover` for shortcode tag and
  safe callback-shape discovery.
- Added compact hash response support for context bootstrap and workflow
  preflight.

### Fixed

- Aligned bulk content upserts, Elementor batch mutations, widget schema
  discovery, and design spec building with the public schemas and examples.
- Updated WordPress-side WP-CLI schemas for typed `wp_cli_context` and deep
  status diagnostics returned by the companion.

## [1.0.0-alpha.60] - 2026-06-17

### Fixed

- Added local WP-CLI runtime guidance to plugin agent instructions and admin
  client setup prompts so agents report missing PHP mysqli/MySQL, WP-CLI,
  WordPress root, or database prerequisites instead of using shell workarounds.
- Clarified that remote HTTP MCP sites do not require local PHP/MySQL unless
  the companion is expected to run WP-CLI for that site.
- Bumped the plugin version to `1.0.0-alpha.60`.

## [1.0.0-alpha.58] - 2026-06-17

### Fixed

- Added shared MCP-use policy text for agent instructions and admin client
  snippets so supported agents stop when `stonewright-context-bootstrap` is not
  visible.
- Blocked private client config inspection, repository-file substitutes,
  scratch helper scripts, helper JSON argument files, ad hoc companion shell
  launches, action scripts, source-code schema spelunking, hand-rolled
  JSON-RPC, REST ability runner shell calls, and shell `wp ...` commands as MCP
  recovery workarounds.

## [1.0.0-alpha.57] - 2026-06-17

### Fixed

- Prevented MCP ability execution from fatalling when runtime REST validation
  sees public JSON Schema placeholder objects used for strict MCP discovery.
- Added regression coverage for permissive array item schemas so
  `stonewright-context-bootstrap` remains callable through the MCP companion.

## [1.0.0-alpha.56] - 2026-06-17

### Fixed

- Hardened MCP client setup prompts to warn against fragile
  `node companion/dist/index.js` configs and generic WordPress MCP adapters,
  preferring the explicit `npx -y --package <tarball> stonewright-mcp`
  companion launch or source-development `mcp:source` command.
- Added a shebang to the companion MCP entrypoint so release tarball bin shims
  execute through Node instead of silently exiting on Windows.

## [1.0.0-alpha.55] - 2026-06-17

### Fixed

- Normalized ability output schemas through the registry before public
  registration, keeping array shapes strict-client safe.
- Added test coverage that prevents future public output schemas from omitting
  `items` on array schemas.

## [1.0.0-alpha.54] - 2026-06-17

### Changed

- Updated Stonewright agent guidance, context followups, and admin client
  prompts to use the workflow preflight `fast_path.tool_profile` before making
  a separate `stonewright-tool-profile` call.
- Companion startup readiness now requires only the first-call bootstrap,
  workflow preflight, and skill retrieval tools; `stonewright-tool-profile`
  remains available for profile switching and verification.

## [1.0.0-alpha.52] - 2026-06-17

### Added

- Added grouped `tool_groups`, `next_best_tools`, and `discovery_policy` data
  to `stonewright/tool-profile` so direct WordPress MCP sessions can choose
  compact task tools without broad discovery.

## [1.0.0-alpha.51] - 2026-06-17

### Added

- Added compact `tool_inventory` data to companion setup and WordPress MCP
  status responses so agents can choose first-call, diagnostic, direct WP-CLI,
  long-running WP-CLI, and proxied profile tools without broad discovery.

## [1.0.0-alpha.50] - 2026-06-17

### Changed

- Kept direct WP-CLI background job tools visible in strict `low-tools`
  companion profiles while preserving the under-30 client-visible startup
  budget.

## [1.0.0-alpha.49] - 2026-06-17

### Added

- Added tokenized WP-CLI background job tooling for long-running CLI work.
- Added compact companion MCP handshake guidance for first calls, recovery,
  low-tools clients, and tokenized WP-CLI usage.

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
