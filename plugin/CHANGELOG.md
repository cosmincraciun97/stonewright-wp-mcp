# Changelog

## [Unreleased]

## [1.0.0-beta.11] - 2026-08-21

### Added

- Add a Connect → Troubleshoot page that runs connection diagnostics in place
  with a loading state, result cards, a copyable support report, bot/WAF
  probes, OAuth registration checks, and a clipboard fallback.
- Add a discover-execute MCP profile with three protocol tools for catalog
  discovery and gated execution without exposing the full tool list.
- Default Gutenberg block parse, registered-block list, and theme.json reads to
  bounded summaries, with `responseMode=full` restoring the previous dumps.
- Add Codex in ChatGPT Desktop, Codex CLI, ChatGPT, Claude.ai, and Antigravity
  CLI to the shared OAuth and Application Password client choosers.
- Add read-only GenerateBlocks, Kadence Blocks, Spectra, and Blocksy library
  introspection, plus Blocksy/Kadence/GeneratePress theme chrome get/update.
- Add presence-gated Blocksy, Kadence, GenerateBlocks, and Spectra build-page
  skills that compose through the Gutenberg finalizer, plus builder operating
  manuals and ACF, SEO, and forms skills.
- Add typed query-loop, FSE navigation, and synced-pattern update/delete/category
  abilities, plus a child-theme theme.json handoff that stops at human approval.
- Add a read-only Elementor performance audit ability reporting bounded document,
  settings, backup, and revision size metrics without exposing content.
- Add a browser-assisted Gutenberg block finalizer that serializes queued
  `{name, attributes, innerBlocks}` specs in the live editor iframe, persists
  path-aware hashed HTML through a guarded finalize ability, and shows a Block
  Editor Queue with heartbeat status. Partial block schemas stay marked as
  live-editor validated.
- Add Elementor list-widgets summary mode and optional full Design Direction
  document loading from `stonewright/design-direction-brief`.
- Add reviewable draft lessons when the same recurring error reaches ten repeats.

### Security

- Enforce php-execute read-only at runtime, block concatenated protected meta
  keys and direct database writes to core tables, and keep php-execute off the
  default compact MCP surfaces.
- Sign one-time admin login links, bind them to IP and User-Agent, rate-limit
  issuance, skip remember-me cookies, and require confirmation in production-safe
  mode.
- Restrict REST ability execution to the active tool profile unless confirmed,
  and require a confirmation token to toggle abilities over REST.
- Require production-safe confirmation tokens on remaining content, Elementor,
  Gutenberg, site, and admin-domain writers.

### Changed

- Style Application Password client pickers with the same button tabs as OAuth.
- Keep compact `stonewright-task-start` under the anti-slop byte budget with
  truncated Context, `design_direction_ref`, and the visual quality floor, and
  expose `stonewright/design-direction-brief` on the essential MCP surface.
- Rebuild the Context admin page into side-by-side system and user sections,
  with collapsible generated instructions and an on/off inject toggle.
- Route ambiguous design, landing-page, and section tasks to Gutenberg when
  Elementor is inactive or a block theme such as Blocksy is the stronger signal.
- Discover skills as slug plus one-line description; load playbook bodies only
  through `stonewright/skills-get`, presence-gate skill bodies as well as the
  catalog, and hide skills whose required integrations are absent.
- Harden pattern creates and FSE global-style/template writes with the same
  backup, confirmation, sanitization, and audit envelope as the canonical
  write abilities. Keep the older update-* names as compatibility wrappers.
- Clear the admin header of mode and version, mark Troubleshoot, Context,
  Design, and Block Editor Queue as Experimental, and move Memory, Skills, and
  Context under Workflows.
- Expose the generated **381**-ability Plugin and **101**-tool Direct contracts.

### Removed

- Remove packaged industry landing-page playbooks from the skills catalog.
  Already-seeded playbook rows are retired on seed and on plugin upgrade.

### Fixed

- Stop Run diagnostics from refreshing the whole admin page when JavaScript is
  available, and keep the no-JS form as a fallback.
- Detect the skills table through wrapped `$wpdb` handles so packaged
  landing-page playbooks actually retire on seed.
- Keep local-site Connect tabs actionable: ChatGPT and Claude.ai now show the
  Desktop/mcp-remote bridge instead of an empty notice.
- Hide inactive OAuth client panels so tab switches actually change the visible
  config.
- Allow `cursor://` one-click install links through WordPress URL sanitization
  and add a copy-link fallback.
- Stop Setup diagnostics from claiming a companion contract mismatch when plugin
  SemVer and the HTTP contract share major 1.
- Reseed packaged skills when the plugin version changes so file copies without
  reactivation still refresh the catalog.
- Encode Cursor MCP install deeplinks as base64url and keep them as clickable
  `cursor://` links on both OAuth and Application Password setup.
- Treat an Elementor elements manager that cannot list types as offline instead
  of failing closed on a stub or half-booted runtime.
- Store backup snapshots and restores through a slash-safe post-meta boundary so
  Elementor documents containing escaped JSON round-trip byte-faithfully; the
  snapshot integrity hash check now passes for legitimate writes and still fails
  closed on genuine storage corruption.
- Separate Codex Desktop from Codex CLI in Connect and dedupe generated MCP
  server names.
- Hide Elementor V4 tools when the atomic toggle is off, persist the MCP surface
  from Apply now, and report when a session has widened beyond compact.
- Surface audit-row error code, message, target, and repair, and make occurrence
  links filter correctly.
- Harden memory save failures, checkbox uncheck, matching window, and
  `body_tool` routing.
- Split paste-to-agent `--profile` from `--wp-surface` so compact versus full
  surfaces stay explicit.
- Normalize sparse container settings on the standalone Elementor add-container
  path.
- Restore editor iframe state after finalizer serialize, reject mixed finalizer
  batches, and honor block path, position, and update semantics on persist.

## [1.0.0-beta.10] - 2026-08-12

### Added

- Add `stonewright/incident-repair-record` for persisted, correlated repair
  receipts and one read-back verified reusable lesson.
- Add canonical incident repair, learning, reopen, and stale metadata plus
  ranked compact task-start actions.

### Changed

- Stop generic successful audit events from resolving incidents or promoting
  audit-derived learning. Explicit user corrections remain immediately
  recordable.
- Keep Plugin and companion update discovery on the installed stable or
  prerelease channel, using channel-specific caches and exact trusted release
  assets without cross-channel fallback.

## [1.0.0-beta.9] - 2026-08-12

### Added

- Add a site-independent Elementor responsive-visibility rule to the packaged
  native rule registry used by Plugin and Direct sessions.

### Fixed

- Reject bare `hidden` and cross-device values for the primary Elementor
  `hide_*` switches; accept only the matching native device value or an empty
  off state before any document write.

## [1.0.0-beta.8] - 2026-08-12

### Changed

- Align plugin metadata and generated companion package references with the
  final connection-verification release. Plugin abilities and safety gates are
  unchanged.

## [1.0.0-beta.7] - 2026-08-12

### Changed

- Align plugin metadata and generated companion package references with the
  privacy hardening release. No plugin ability, permission, backup,
  confirmation, validation, audit, or custom-code gate changed.

## Older releases

- [1.0.0-beta.6](../docs/releases/1.0.0-beta.6.md)
- [1.0.0-beta.5](../docs/releases/1.0.0-beta.5.md)
- [1.0.0-beta.4](../docs/releases/1.0.0-beta.4.md)
- [1.0.0-beta.3](../docs/releases/1.0.0-beta.3.md)
- [1.0.0-beta.2](../docs/releases/1.0.0-beta.2.md)
- [1.0.0-beta.1](../docs/releases/1.0.0-beta.1.md)

### 1.0.0-beta.3 — 2026-07-31

### Added

- Add `stonewright/elementor-post-write-verify` for post-scoped CSS
  regeneration, official frontend warming, bounded HTML assertions, and an
  explicit desktop/tablet/mobile browser recipe.
- Expose V3-safe subtree roots in mixed V3/V4 document health responses.

### Changed

- Clarify local stdio versus Remote Streamable HTTP throughout Setup and public
  installation guidance.
- Approval-gate Customizer CSS with the same native-gap dry-run, human-issued
  one-time grant, backup, exact-hash binding, readback, and audit contract used
  by theme-file code writes.
- Return an explicit human handoff (`approval_url`, target path, byte counts,
  summary, and `agent_must_stop`) and forbid agents from opening or submitting
  the approval page without an explicit user request.
- Enforce one per-post Elementor write lease across typed writers and require
  live schema evidence, consolidated mutation, post-write closure, and browser
  proof through the native rule registry.

### Fixed

- Invalidate the official Elementor document-cache key, post CSS, WordPress
  object cache, and atomic styles after verified writes and restored snapshots.
- Remove a global Elementor CSS-clear fallback from post-scoped regeneration.
- Return exact batch-mutation repair hints, guarded escaped-layout decoding,
  and stable receipts for the new post-write closure.

### 1.0.0-beta.2 — 2026-07-30

### Added

- A maintained light-mode design system and page-by-page admin release
  checklist.

### Changed

- Replace oversized Dashboard metric cards with a compact grouped overview.
- Align all wp-admin surfaces on the same typography, spacing, border, focus,
  badge, and status language.

### Fixed

- Force explicit companion release checks past stale WordPress and browser
  caches without disabling background caching.
- Keep required output-schema fields in projected responses, including the
  compact task-start handshake.
- Center Domain Lock controls, restore Sandbox file-type contrast, remove an
  inline category-action click handler, and correct setup code contrast and
  Visual Workspace focus outlines.

### 1.0.0-beta.1 — 2026-07-30

### Added

- Seventeen native WooCommerce abilities covering runtime status, products,
  variations, catalog terms, global attributes, orders, sales, and bounded
  catalog audits.
- Explicit runtime integration discovery for supported and discovery-only
  builders, themes, blocks, forms, field plugins, add-ons, dynamic-data
  plugins, code tools, and SEO suites.
- Shared native rules, bounded memory generalization, response projection,
  Elementor unchanged-hash reads, and guarded escaped-layout decoding.
- Credential-free agent setup, mode-aware Prompt Library entries, and a
  step-by-step plugin/companion update guide in wp-admin.
- A trusted latest-release companion check with version status, direct package
  and checksum links, and a credential-free agent update prompt.
- Fresh-install and upgrade lifecycle tests for memory, skills, and audit data.

### Changed

- WooCommerce catalog mutations now preview by default, use allowlisted native
  object APIs, enforce task context and permissions, require production
  confirmations where applicable, record audit entries, and verify readback.
- Production packages bootstrap through Jetpack's package-aware Composer
  loader and verify every generated manifest path.
- Persistent memory and skill writes reject high-confidence credential
  material. Existing user state and audit history remain intact on upgrade.
- Site-independent Elementor, content-model, asset, query, custom-code, dynamic
  architecture, and visual-proof rules apply from the immutable native
  registry instead of customer memory.
- Audit incidents render on one responsive page with readable error causes,
  contained payloads, and copy actions.

### Fixed

- Clean production dependency builds keep root dev autoload metadata out of
  Jetpack's optimized classmap and remove development-only paths that could
  make activation fail beside WooCommerce.
- Packaged generic skills seed through a release-scanned trusted path, while
  user-created skills cannot bypass credential guards by claiming built-in
  provenance.
- CI stages the plugin with the real release exclusions and rejects any
  resulting Jetpack manifest path that is absent from that staged package.
- The WooCommerce runtime gate boots the extracted release archive, Sandbox
  primary buttons keep readable text, and custom-code approval tokens have a
  functional copy control.
