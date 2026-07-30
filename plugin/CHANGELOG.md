# Changelog

## [Unreleased]

## [1.0.0-beta.3] - 2026-07-31

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

## [1.0.0-beta.2] - 2026-07-30

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

## [1.0.0-beta.1] - 2026-07-30

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
