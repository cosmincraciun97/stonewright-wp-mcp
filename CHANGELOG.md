# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Added reproducible MCP surface and task-start token benchmarks with 20-tool
  default and 12-tool strict profile budgets.
- Added upstream source provenance, attribution, and license notices for
  Novamira-derived components.
- Added the AGPL Stonewright Visual foundation with one top-level workspace
  request tool and nested editor/backend tool discovery.
- Added nested batch calls with aliases, mandatory mutation readback,
  transaction rollback, compact schema summaries, and confirmation state.
- Added a page-resident Elementor V3 editor adapter with live widget schemas,
  per-setting evidence, idempotency, refs, rollback, undo/redo/save, and
  immediate editor-model readback.
- Added optimistic tree hashes, persistent idempotency, strict evidence mode,
  and post-write hash verification to the Elementor V3 batch compiler.
- Added live structural schemas for Elementor containers, sections, and
  columns so the final write gate rejects unknown layout settings too.
- Added vendor-neutral DesignEvidence validation, deterministic native-first
  planning, semantic action gates, and separate non-applying customization
  proposals for Elementor, Gutenberg, and WordPress design tasks.
- Added an Elementor builder reference that turns Figma/image evidence into a
  native plan before any schema compilation or visual write.
- Added site-local knowledge candidates with source hashes, TTL, Elementor
  version constraints, verification fingerprints, conflict gates, and audited
  promotion through `stonewright/knowledge-candidate-record`.
- Added disabled research drafts plus immutable skill revision history, lint,
  and rollback.

### Changed

- Relicensed the Stonewright WordPress plugin to AGPL-3.0-or-later before the
  first Novamira source import; the Node companion remains MIT licensed.
- Reduced compact task-start context to stay below the 700-token non-visual
  and 1,200-token visual budgets.
- Reduced the real default MCP surface to 20 tools and strict mode to 12;
  legacy companion aliases now appear only in the explicit full profile.
- Added a live, fingerprinted Elementor V3 schema repository with one compact
  schema tool, lazy widget shards, cache invalidation, and strict write guards.
- Deprecated generated `elementor-add-*` tools for new plans in favor of the
  unified schema plus batch compiler while retaining a compatibility window.
- Changed learning so one correction records memory by default and can no
  longer activate a skill automatically; task start now routes through compact,
  relevant, non-stale memory refs with explicit precedence.

### Fixed

- Fixed snapshot restore so Elementor meta created after a snapshot is deleted
  when rollback restores an originally absent value.

- Corrected the Elementor Pro Gallery required control from the invalid
  `wp_gallery` key to the live `gallery` control.
- Isolated WP-CLI fixture discovery from real LocalWP binaries on the test host
  and removed machine-specific paths from Elementor registry artifacts.
- Cleaned per-process PHP test files at shutdown and removed PHP 8.5 reflection
  deprecation noise.

### Security

- Hardened `stonewright/php-execute` with production-safe confirmation,
  bounded output, normalized results, buffer/time-limit cleanup, and compact
  audit metadata without exposing source code.
- Removed JavaScript eval from the adapted Visual dispatcher and restricted
  backend execution to discovered Visual-safe tools with approval for writes.

## [1.0.0-alpha.64] - 2026-06-17

### Added

- Added Codex as a first-class MCP client in the admin setup catalogue with
  copyable `config.toml` output and release refresh guidance.
- Added Codex getting-started docs covering `~/.codex/config.toml`, project
  `.codex/config.toml`, `/mcp` validation, and stale companion detection.

### Changed

- Updated active plugin, companion, install snippets, and release docs to
  `1.0.0-alpha.64`.
- Synchronized the companion runtime `APP_VERSION` with the package version so
  setup/status payloads report the current companion release.
- Updated public project and plugin descriptions around WordPress MCP builder
  tools for Elementor, Gutenberg, content models, PHP runtime execution, and
  tokenized WP-CLI.

### Fixed

- Fixed fresh activation on MySQL variants that reject default values on
  `TEXT` columns by removing the `description text DEFAULT ''` schema default
  from the skills table.

## [1.0.0-alpha.63] - 2026-06-17

### Added

- Added `stonewright/theme-builder-apply-template`, a one-call Elementor Theme
  Builder orchestrator that validates a spec, snapshots, renders Elementor
  data, applies display conditions, and returns verification/repair hints.
- Added `stonewright/content-model-loop-grid-flow` for CPT/ACF-backed
  Elementor Loop Grid sections, including post type contract, field contract,
  rows, loop item template, and widget settings output.
- Added first-install companion refresh hints through setup/status payloads:
  `companion_version`, `expected_companion_package`, and
  `refresh_required_tool_names`.

### Changed

- Updated compact tool profiles and skills so weak-model clients route Theme
  Builder and editable repeated-card work to composite fast paths instead of
  raw meta edits or many small calls.
- Enriched Design Spec validator failures with exact paths, received types,
  allowed shapes, nearest valid examples, and repair hints.
- Bumped active plugin, companion, install snippets, and release docs to
  `1.0.0-alpha.63`.

## [1.0.0-alpha.62] - 2026-06-17

### Added

- Added `stonewright/php-execute` as the dedicated full WordPress runtime
  execution ability for short PHP snippets, plugin API inspection, and compact
  implementation loops inside the loaded WordPress process.
- Added runtime tooling to compact MCP profiles, workflow preflight, context
  bootstrap guidance, setup profiles, skills, docs, fixtures, and ability
  matrix coverage so strict tool-cap clients can route directly to PHP runtime
  access without another adapter.

### Changed

- Repositioned Stonewright docs and agent guidance around PHP-first runtime
  implementation, tokenized WP-CLI, fast composite writes, and lower-token
  profile routing while keeping production-safe mode, backups, permissions,
  confirmation tokens, validators, audit logging, and companion command
  blocking intact.

### Fixed

- Prevented `stonewright/php-execute` from being classified as read-only in the
  generated ability matrix and added regression coverage for its write
  envelope.
- Synchronized the installed Codex Stonewright skill so weak-model startup
  guidance includes PHP runtime routing and no longer points agents at stale
  guarded-WP-CLI wording.
- Bumped the plugin and companion release assets to `1.0.0-alpha.62`.

## [1.0.0-alpha.61] - 2026-06-17

### Added

- Added deep companion WP-CLI health diagnostics for PHP ini loading, MySQL
  extensions, WordPress bootstrap, database checks, LocalWP DB host/port
  detection, cached default WordPress roots, and actionable setup guidance.
- Added read-only `stonewright/site-shortcodes-discover` for shortcode tag and
  callback-shape discovery without executing shortcode handlers.

### Fixed

- Added typed `wp_cli_context` support while preserving legacy custom
  `context`, and improved diagnostics when a selected cwd is not a WordPress
  root.
- Aligned bulk content, Elementor batch mutation, widget schema, and design spec
  payloads with their examples: `post_status` aliases, compact mutation aliases,
  widget schema filters, and validator-valid shorthand design sections.
- Added compact bootstrap/preflight hash responses for lower-token startup.
- Bumped the plugin and companion release assets to `1.0.0-alpha.61`.

## [1.0.0-alpha.60] - 2026-06-17

### Fixed

- Added a `php_ini_not_loaded` warning to companion WP-CLI status responses so
  agents do not treat `wp cli info` success as proof that WordPress can boot.
- Added setup-profile, MCP handshake, admin prompt, skill, and documentation
  guidance for local WP-CLI prerequisites: PHP CLI with mysqli/MySQL, WP-CLI,
  a WordPress root with `wp-config.php`, and a running database.
- Clarified that remote HTTP MCP sites do not require local PHP/MySQL unless
  the companion is expected to run WP-CLI for that site.
- Bumped the plugin and companion release assets to `1.0.0-alpha.60`.

## [1.0.0-alpha.58] - 2026-06-17

### Fixed

- Added shared MCP startup guardrails across companion handshake instructions,
  setup profiles, WordPress admin client snippets, repository agent guidance,
  and public docs so agents stop when `stonewright-context-bootstrap` is not
  visible instead of bypassing the Stonewright MCP tool surface.
- Explicitly blocked private client config inspection, repository-file
  substitutes, scratch helper scripts, helper JSON argument files, ad hoc
  companion shell launches, action scripts, source-code schema spelunking,
  hand-rolled JSON-RPC, REST ability runner shell calls, and shell `wp ...`
  commands as Stonewright MCP recovery paths.
- Bumped the plugin and companion release assets to `1.0.0-alpha.58`.

## [1.0.0-alpha.57] - 2026-06-17

### Fixed

- Prevented MCP ability execution from fatalling when runtime REST validation
  sees public JSON Schema placeholder objects used for strict MCP discovery.
- Added regression coverage for permissive array item schemas so
  `stonewright-context-bootstrap` remains callable through the MCP companion.

## [1.0.0-alpha.56] - 2026-06-17

### Added

- Added Antigravity setup documentation with `low-tools` config, refresh steps,
  and troubleshooting for missing Stonewright MCP tools.
- Added a release notes index for the existing alpha release notes and release
  checklist.

### Fixed

- Hardened companion startup guidance so release tarballs always build
  `dist` before packing, source-development MCP configs use `mcp:source`, and
  docs/admin prompts warn against generic WordPress MCP adapters and fragile
  `node companion/dist/index.js` IDE configs.
- Added a Node shebang to the companion MCP entrypoint so npm-generated bin
  shims execute the published tarball through Node on Windows, macOS, and
  Linux.
- Updated setup-profile, admin snippets, and public docs to run the explicit
  `stonewright-mcp` bin through `npx -y --package <tarball> stonewright-mcp`,
  avoiding ambiguous npx bin inference for scoped release tarballs.
- Updated the Codex skill sync script so backups are stored outside
  `~/.codex/skills`, stale nested skill copies are cleaned, and older indexed
  backup directories are relocated instead of appearing as duplicate slash
  commands.

## [1.0.0-alpha.55] - 2026-06-17

### Fixed

- Normalized public ability output schemas before registration so permissive
  arrays declare `items` and strict MCP clients do not reject Stonewright tools
  during schema validation.
- Added a registry guard that keeps future public output schemas compatible
  with strict client discovery.

## [1.0.0-alpha.54] - 2026-06-17

### Changed

- Companion startup and setup guidance now treat `stonewright-tool-profile` as
  an optional profile switch/verification tool instead of a required first-call
  step, using `fast_path.tool_profile` from workflow preflight first.
- Companion startup readiness no longer fails solely because
  `stonewright-tool-profile` is unavailable when bootstrap, preflight, and
  skills retrieval are present.

## [1.0.0-alpha.53] - 2026-06-17

### Added

- `stonewright/workflow-preflight` now inlines a compact `tool_profile`
  summary with profile counts, grouped tools, next-best tool recommendations,
  and discovery policy so token-sensitive agents can skip a separate
  `stonewright/tool-profile` call when the preflight profile is sufficient.

## [1.0.0-alpha.52] - 2026-06-17

### Added

- `stonewright/tool-profile` now returns grouped tool inventory,
  `next_best_tools`, and a discovery policy so direct HTTP MCP sessions can pick
  the next Elementor, content/media, Gutenberg/FSE, WP-CLI, or site-admin tool
  without broad discovery.

## [1.0.0-alpha.51] - 2026-06-17

### Added

- `stonewright-setup-profile` and `stonewright-wordpress-mcp-status` now return
  a compact grouped `tool_inventory` for first-call, diagnostic, direct WP-CLI,
  long-running WP-CLI, and proxied profile tools.

## [1.0.0-alpha.50] - 2026-06-17

### Changed

- `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` now exposes direct WP-CLI background
  job tools while staying under the strict startup budget for Antigravity,
  Gemini API, and similar clients.

## [1.0.0-alpha.49] - 2026-06-17

### Added

- Added guarded companion WP-CLI background jobs through
  `stonewright-wp-cli-job-start` and `stonewright-wp-cli-job-status`.

### Fixed

- Stale `.env` `PORT` values no longer start the optional HTTP bridge during
  stdio MCP startup unless HTTP bridge opt-in is explicit.

## [1.0.0-alpha.48] - 2026-06-16

### Changed

- `stonewright-wordpress-mcp-status` now reports the normalized compact
  `tool_profile`, expected profile tool count, and missing profile tools even
  when the WordPress MCP endpoint fails before tool registration.
- Failed connections now keep profile repair hints specific to the selected
  compact profile instead of returning a null profile.

## [1.0.0-alpha.47] - 2026-06-16

### Changed

- `stonewright-wordpress-mcp-status` now reports
  `profile_expected_tool_count` and `profile_missing_tool_names` for the active
  compact proxy profile.
- Recovery hints now tell agents how to repair missing non-startup profile tools
  without blind retries or broad discovery.

## [1.0.0-alpha.46] - 2026-06-16

### Changed

- `stonewright-wordpress-mcp-status` now reports `local_tool_names`, covering
  setup, proxy status, direct Stonewright WP-CLI tools, and legacy companion
  WP-CLI aliases so agents can recover without broad tool discovery.
- `local_recovery_tool_names` and `stonewright-setup-profile` visibility checks
  now include direct `stonewright-wp-cli-run` and `stonewright-wp-cli-install`.

## [1.0.0-alpha.45] - 2026-06-16

### Changed

- Companion compact proxy profiles now accept common aliases such as
  `elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli`, normalizing them
  to canonical compact profiles before WordPress MCP tool filtering.
- `stonewright-setup-profile` now tells agents about these profile aliases so
  new sessions can choose task-specific compact tool surfaces without exact
  profile-name memorization.

## [1.0.0-alpha.44] - 2026-06-16

### Added

- `stonewright-wordpress-mcp-status` now reports startup readiness, required
  startup tools, missing startup tools, and local recovery tool names for
  compact client repair.

## [1.0.0-alpha.43] - 2026-06-16

### Changed

- `stonewright/skills-get` is included in compact profiles so token-sensitive
  clients can load one matched playbook without full tool discovery.

## [1.0.0-alpha.42] - 2026-06-16

### Fixed

- `stonewright-wordpress-mcp-status` now counts only profile-hidden WordPress
  tools in `profile_filtered_tool_count`, excluding companion-owned local tools
  such as `stonewright-wp-cli-status`.
- Status responses now include a compact `profile_filtered_tool_names` sample
  and a profile-aware recovery hint so agents can switch
  `STONEWRIGHT_MCP_TOOL_PROFILE` only when needed.

## [1.0.0-alpha.41] - 2026-06-16

### Fixed

- `stonewright-wordpress-mcp-status` now reports the active compact proxy
  profile, total remote WordPress MCP tools, registered proxied tools, and
  profile-filtered tools so agents can diagnose missing tools without broad
  discovery or blind retries.

## [1.0.0-alpha.40] - 2026-06-16

### Fixed

- The companion compact `essential` and `elementor-design` proxy profiles now
  expose `stonewright-elementor-v3-container-schema`, matching the PHP
  `tool-profile` and `workflow-preflight` recommendations so new MCP sessions
  do not hide the container schema tool during Elementor design work.
- `stonewright-wordpress-mcp-status` now reports the number of proxied tools
  actually registered after compact profile filtering, instead of the larger
  remote tool count.

## [1.0.0-alpha.39] - 2026-06-16

### Fixed

- Stonewright error envelopes now preserve compact, repairable Elementor widget
  validation details (`widget` and sanitized `violations`) so agents can fix
  missing/invalid settings in the next call instead of retrying blind.
- Sensitive error data remains stripped from public envelopes, including tokens,
  passwords, raw specs, and other non-allowlisted fields.

## [1.0.0-alpha.38] - 2026-06-16

### Changed

- Stonewright MCP server startup now sends a compact fast-start description
  instead of the full visual build discipline, keeping new MCP sessions cheaper
  while still directing agents to `stonewright-context-bootstrap`,
  `stonewright-workflow-preflight`, and compact tool profiles.
- MCP server tool registration now exposes only the current public Stonewright
  abilities for the active essential-tools mode, master toggle, and disabled
  ability settings instead of probing every ability class.

### Fixed

- Site-specific custom instructions are no longer duplicated in the MCP server
  description during startup.

## [1.0.0-alpha.37] - 2026-06-16

### Added

- Added `stonewright/elementor-v3-container-schema`, a compact read-only guide
  for Elementor container layout, style, Advanced, alias, and blocked setting
  keys so agents can write section containers without broad schema discovery.

### Changed

- Elementor design profiles, workflow preflight, and capabilities summary now
  recommend the container schema before section layout writes.
- Direct Elementor container writes now map common flex aliases like
  `justify_content`, `align_items`, and `align_content` to Elementor's
  `flex_*` container keys while keeping risky flex sizing keys stripped.
- Essential tools mode stays below the compact-tool limit by replacing the
  less common save-template fast-path slot with the new container schema.

## [1.0.0-alpha.36] - 2026-06-16

### Fixed

- Direct Elementor container writes now normalize risky agent-supplied flex
  settings before saving, preventing `flex_wrap` and `_flex_*` guesses from
  causing overflow or unstable section layouts.
- Batch Elementor mutations and single-element updates now share the same
  container normalization path, so section-by-section design builds stay
  consistent across fast batch writes and targeted fixes.

## [1.0.0-alpha.35] - 2026-06-16

### Fixed

- Public input schemas now add permissive `items: {}` to array parameters that
  intentionally accept any item shape, preventing stricter MCP clients from
  hiding tools during discovery.
- Widget intent media prerequisites now use `stonewright/media-list` before
  `stonewright/media-upload-batch`, avoiding slower single-upload guidance for
  Elementor galleries, videos, speaker grids, logo/nav, and image widgets.

## [1.0.0-alpha.34] - 2026-06-16

### Fixed

- Companion compact proxy profiles now expose `stonewright-media-list` wherever
  PHP-side profiles recommend `stonewright/media-list`, so fresh MCP sessions can
  search existing media before uploading design assets.
- Essential companion proxy discovery now keeps the compact `media-list` plus
  `media-upload-batch` path instead of exposing the slower single
  `media-upload` tool.

## [1.0.0-alpha.33] - 2026-06-16

### Added

- Added `stonewright/media-list`, a compact read-only media-library search
  ability that finds existing assets by title, filename slug, caption,
  description, alt text, and mime before agents upload duplicate design assets.

### Changed

- Essential and Elementor design profiles now expose `media-list` while keeping
  compact discovery below 60 tools by relying on `media-upload-batch` for both
  single and multi-file uploads.
- Workflow preflight now places `media-list` before `media-upload-batch` in
  visual Elementor call sequences, making asset reuse the fast default.

## [1.0.0-alpha.32] - 2026-06-16

### Changed

- `stonewright/workflow-preflight` now returns a compact
  `design_contract_ref` for visual Elementor work instead of inlining the full
  design implementation contract by default, reducing first-call payload size.
- Added `include_design_contract=true` for agents that intentionally need the
  full section-batch, native-widget, global-style, and verification contract in
  the preflight response.

## [1.0.0-alpha.31] - 2026-06-16

### Changed

- Public MCP input schemas now normalize empty JSON Schema maps such as
  `properties` to JSON objects, preventing strict MCP clients from treating
  no-input abilities as array-shaped schemas during tool discovery.
- Added registry coverage so future no-input abilities keep encoding
  `properties: {}` correctly for faster, more reliable startup in new agent
  sessions.

## [1.0.0-alpha.30] - 2026-06-16

### Changed

- Essential tool profiles now expose `stonewright/security-create-one-time-link`
  so browser automation can obtain a short-lived wp-admin login URL without
  switching to full tool discovery.
- Removed the older `content-bulk-create` helper from the essential profile in
  favor of `content-bulk-upsert-posts`, keeping compact discovery below 60
  tools while preserving the faster repeated-row workflow.

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

- Added Composite Apply v1 for Elementor with
  `stonewright/elementor-v3-batch-mutate`, allowing many add, update,
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

- Added a UTF-8-compatible companion WP-CLI batch runner for repeated argv-based
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
- `stonewright/elementor-v3-apply-bundle` for multi-post Elementor writes.
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
  handling, JSON parsing, and blocked eval/shell command groups.

### Removed

- Built-in design-tool ingestion from Stonewright.
- Automated visual QA, browser audit, accessibility audit, layout diff, and
  screenshot/diff abilities from Stonewright.
- Companion modules and contracts used only for removed ingestion/QA workflows.
- Obsolete skill packs and operational plans that told agents to run removed
  workflows.

### Changed

- The companion is now focused on health, optional MCP HTTP/proxy transport, and
  tokenized WP-CLI execution.
- Active documentation now points agents to persistent context, Elementor native
  widget discipline, and WP-CLI acceleration.

## [1.0.0-alpha.2] - 2026-05-22

Elementor-first hardening milestone. This release expanded Elementor, Gutenberg,
FSE, memory, sandbox, and system abilities, and introduced the security envelope
around permissions, backups, validators, confirmation tokens, and audit logging.

## [1.0.0-alpha.1] - 2026-05-21

Initial tagged release of Stonewright WP MCP.

[1.0.0-alpha.63]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.63
[1.0.0-alpha.62]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.62
[1.0.0-alpha.61]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.61
[1.0.0-alpha.60]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.60
[1.0.0-alpha.58]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.58
[1.0.0-alpha.57]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.57
[1.0.0-alpha.56]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.56
[1.0.0-alpha.55]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.55
[1.0.0-alpha.54]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.54
[1.0.0-alpha.53]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.53
[1.0.0-alpha.52]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.52
[1.0.0-alpha.51]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.51
[1.0.0-alpha.50]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.50
[1.0.0-alpha.49]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.49
[1.0.0-alpha.48]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.48
[1.0.0-alpha.47]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.47
[1.0.0-alpha.46]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.46
[1.0.0-alpha.45]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.45
[1.0.0-alpha.44]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.44
[1.0.0-alpha.43]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.43
[1.0.0-alpha.42]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.42
[1.0.0-alpha.41]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.41
[1.0.0-alpha.40]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.40
[1.0.0-alpha.39]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.39
[1.0.0-alpha.38]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.38
[1.0.0-alpha.37]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.37
[1.0.0-alpha.36]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.36
[1.0.0-alpha.35]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.35
[1.0.0-alpha.34]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.34
[1.0.0-alpha.33]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.33
[1.0.0-alpha.32]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.32
[1.0.0-alpha.31]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.31
[1.0.0-alpha.30]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.30
[1.0.0-alpha.29]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.29
[1.0.0-alpha.28]: https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/tag/v1.0.0-alpha.28
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
