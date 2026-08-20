# Changelog

## [Unreleased]

### Added

- Add Codex in ChatGPT Desktop, Codex CLI, ChatGPT, Claude.ai, and Antigravity
  CLI to the shared OAuth and Application Password client choosers.
- Add a read-only Elementor performance audit ability reporting bounded document,
  settings, backup, and revision size metrics without exposing content.
- Add a browser-assisted Gutenberg block finalizer: queue `{name, attributes,
  innerBlocks}` specs, serialize them with the live editor registry, and persist
  only hashed HTML through a guarded finalize ability.

### Security

- Enforce php-execute read-only at runtime, block concatenated protected meta
  keys and direct database writes to core tables, and keep php-execute off the
  default compact MCP surfaces.
- Sign one-time admin login links, bind them to IP and User-Agent, rate-limit
  issuance, skip remember-me cookies, and require confirmation in production-safe
  mode.
- Restrict REST ability execution to the active tool profile unless confirmed,
  and require a confirmation token to toggle abilities over REST.

### Fixed

- Encode Cursor MCP install deeplinks as base64url and keep them as clickable
  `cursor://` links on both OAuth and Application Password setup.
- Store backup snapshots and restores through a slash-safe post-meta boundary so
  Elementor documents containing escaped JSON round-trip byte-faithfully; the
  snapshot integrity hash check now passes for legitimate writes and still fails
  closed on genuine storage corruption.

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

## [1.0.0-beta.6] - 2026-08-12

### Changed

- Make the credential-free installed-plugin prompt choose one transport and
  route companion setup through an alias-specific `plugin-only` installer or
  an existing-alias repair flow.
- Mark every generated Application Password stdio snippet as explicit Plugin
  mode so a temporary probe failure cannot silently select Direct mode.

### Fixed

- Render domain-lock status without nested action forms inside the Settings API
  form, keeping **Save Settings** associated with the correct form and returning
  the operator to Stonewright Setup after WordPress saves the options.

## Older releases

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
