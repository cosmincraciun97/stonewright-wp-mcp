# Changelog

## [Unreleased]

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

## [1.0.0-beta.5] - 2026-08-12

### Added

- Add deterministic OAuth discovery, PKCE, resource-binding, challenge,
  refresh-rotation, replay, and terminal-reauthorization matrix coverage plus
  distinct client support, certification-priority, and evidence metadata.
- Add native row/container layout normalization, live-gated cover CTA and
  testimonial carousel rendering, chip groups, schema-backed button icons, and
  `stonewright/elementor-v3-kit-batch-mutate` for typed kit globals.
- Add typed WPCode, Code Snippets, Customizer CSS, and theme-file providers
  with approval-gated dry runs, concurrency checks, snapshots, verification,
  and rollback guidance.

### Changed

- Separate unresolved audit incidents from active learning and promote durable
  repair guidance only after exact verified evidence or user correction.
- Make the paste-to-agent setup prompt prefer the versioned multi-site
  installer, secret-free credential references, per-site/client choices, and
  spawned runtime verification after restart.
- Require explicit browser-provider, scan, and installation consent; Playwright
  remains the recommended default without becoming a silent dependency.
- Aggregate terminal OAuth client failures over 24 hours while retaining the
  short visibility window for retryable and server-side failures.
- Default fresh installs to the bounded `essential` MCP surface and make
  generated client profiles follow the operator's explicit surface selection;
  strict-cap clients retain their bounded override.
- Disable the Design Library navigation, pages, page assets, and catalog
  starters without deleting stored directions or removing typed MCP engines.

### Fixed

- Reject catalog-only Pro widget substitutions, accept rollback without dummy
  operations, verify boolean snapshot restoration, treat identical kit plans as
  no-ops, restore on kit readback mismatch, and reject unsafe icon classes or
  SVG URL schemes while writing only live-supported Button icon controls and
  selecting only carousel widgets with a compatible live repeater.
- Restrict snippet-plugin source reads to `manage_options`, retain
  `edit_theme_options` only for theme-owned providers, and require production
  confirmation tokens for provider apply and rollback.
- Reconcile every page of legacy audit lessons and keep failed migrations
  retryable instead of marking them complete.
- Document the exact Composer audit exception for PHP_CodeSniffer 3.13.6, the
  patched boundary release that Composer 2.9.5 still misclassifies as affected.
- Add deliberate spacing and consistent control heights across Audit incident
  cards, Memory forms, Skills availability, and Sandbox action cells.
- Display registered OAuth client names for pre-login audit events through one
  batched lookup and replace ambiguous `(unknown)` labels with explicit source
  fallbacks.
- Preserve operator enablement intent during domain mismatch and require an
  audited, reversible explicit rebind before abilities become effective.
- Keep Application Password creation in the current Setup tab, update private
  snippets and inventory in place, restore placeholders on clear/revoke, and
  eliminate plaintext password transients from the no-JavaScript path.
- Save all runtime Step 1 controls immediately and bump a shared surface
  revision so active transports can re-list without unrelated form writes.

## [1.0.0-beta.4] - 2026-08-05

### Added

- Add the permanent audit/incident taxonomy, OAuth server rate-limit contract,
  Elementor transaction receipts and evidence-preserving patch validation,
  Gutenberg block batches, design manifests/comparison, and read-only form and
  capability diagnostics.
- Add a count-only, hash-bound runtime-history purge with production
  confirmation, reviewed watermarks, concurrent-row preservation, and one
  retained cleanup receipt.

### Fixed

- Bind OAuth tokens to the canonical MCP resource, publish the minimal `mcp`
  resource scope, and revoke all descendant refresh and access tokens when a
  rotated refresh token is replayed.
- Neutralize spreadsheet formulas in redacted audit CSV exports and include
  both OAuth audit transient families in purge hashes and counts.

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

## Older releases

- [1.0.0-beta.2](../docs/releases/1.0.0-beta.2.md)
- [1.0.0-beta.1](../docs/releases/1.0.0-beta.1.md)

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
