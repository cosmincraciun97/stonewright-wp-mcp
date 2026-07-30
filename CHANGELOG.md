# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

## [1.0.0-beta.1] - 2026-07-30

### Added

- Native WooCommerce catalog abilities for status, product and variation
  reads/writes, catalog terms, global attributes, safe deletes, and bounded
  catalog audits. Plugin mode now exposes 346 abilities; Direct mode remains
  read-only for WooCommerce.
- Runtime discovery for common builders, themes, block libraries, forms, field
  plugins, add-ons, dynamic-data plugins, code tools, and SEO suites. Detected
  integrations without typed adapters are reported as discovery-only.
- Public-repository hygiene checks for private project identifiers in source,
  staged release archives, and optionally commit history.

### Changed

- WordPress release archives now come from a clean production Composer install.
  The Jetpack Autoloader is a production dependency, its package loader is the
  primary bootstrap, and every manifest path is verified before publication.
- WooCommerce catalog writes use native WooCommerce objects and allowlisted
  setters, preview by default, enforce permissions and production
  confirmations, record audit entries, and verify readback.
- Storefront guidance routes Elementor through live Woo widget schemas and
  Gutenberg/FSE through registered `woocommerce/*` block schemas.

### Fixed

- Prevent activation fatals when WooCommerce and Stonewright share Jetpack
  Autoloader by eliminating stale development-only manifest paths from release
  archives.
- Release checks now test WooCommerce co-activation and reject archives with
  missing Composer-manifest targets.

## [1.0.0-alpha.92] - 2026-07-30

### Added

- A shared native-rule registry for Plugin and Direct mode, with explicit
  severity and runtime-enforcement classifications, digest caching, and the
  read-only `rules-get` surface.
- Bounded, cursor-based memory generalization with dry-run preview and guarded
  apply mode.
- Response field projection through `stonewright_fields`, plus `knownHash`
  short-circuit reads for Elementor page structures.
- Conservative, opt-in escaped-layout decoding for supported code payloads.

### Changed

- Native batching guidance is sourced from the shared rule registry.
- Direct mode validates and canonicalizes every packaged native-rule record
  before exposing it, matching the plugin loader's fail-closed behavior.
- The WordPress admin now ships one supported light theme.

### Fixed

- OAuth audit classification preserves protocol failures without storing
  request or response credentials echoed inside diagnostic fields.
- Memory generalization now returns an explicit partial-failure error when any
  planned database update fails, including the failed ids and continuation
  state.
- Elementor responsive control detection covers suffixed controls and
  standalone visibility switchers.
- Elementor structure hashes ignore associative JSON key order while preserving
  semantic element-list order.
- Projected integer-keyed maps retain their keys, projected list members retain
  source order, and the light-only browser matrix matches the shipped admin.

### Removed

- The obsolete admin theme toggle, stored dark preference, and duplicate dark
  token set.

## [1.0.0-alpha.91] - 2026-07-26

### Added

- `stonewright/design-direction-brief`, a compact read-only ability that turns
  the active direction's variance, density, and motion dials into reusable
  Elementor guidance without returning the full stored contract for every
  section.
- Guided onboarding, contextual keyboard-accessible tooltips, and explicit
  readiness controls in Design Studio.

### Changed

- The Figma-to-Elementor skills now use one shallow document read, one
  token/style read, then per-section normalized DesignEvidence. They reuse the
  compact direction brief, enforce measured responsive targets, and avoid
  repetitive generic compositions and undeclared visual defaults.
- Visual Workspace now opens the real Elementor or block editor in a
  same-origin companion window and resolves adapters against that live runtime.

### Fixed

- Style the Visual Workspace post picker and editor connection controls with
  the Stonewright admin design system.
- Mount the workspace immediately in a truthful disconnected state, then replace
  that controller without races when the real editor runtime attaches.

## [1.0.0-alpha.90] - 2026-07-26

### Fixed

- ChatGPT can scan and refresh every MCP action without rejecting empty JSON
  Schema fragments encoded as arrays.

## [1.0.0-alpha.89] - 2026-07-26

### Fixed

- ChatGPT and other OAuth clients can now complete administrator consent instead
  of stopping on a generic authorization error.
