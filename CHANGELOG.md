# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha.75] - 2026-07-16

### Added

- Direct mode: `stonewright-content-create` creates items of any registered post
  type (**99** tools total).
- Direct mode: `stonewright-task-start` returns `session_tools` (exact enabled
  tool list) and structured `capabilities.content_model` guidance.

### Changed

- Direct mode: content and taxonomy tools auto-resolve `rest_base` from
  `/wp/v2/types` and `/wp/v2/taxonomies` (CPTs whose `rest_base` differs from
  the slug now work).
- Direct mode: site-discover and capability tiers state that Direct fully edits
  existing CPT content, taxonomy terms, and ACF field values; registering new
  models requires server-side PHP (plugin) — a WordPress REST limit, not a
  Stonewright gap.

## [1.0.0-alpha.74] - 2026-07-16

### Changed

- MCP companion startup now follows the tool surface saved in WordPress Setup;
  strict-cap and specialist client profiles remain explicit overrides.
- `task-start` reports the configured MCP surface without silently rewriting it.
- Fresh companion and Direct/pluginless sessions default to Bootstrap, then
  unlock a compact task profile only for the current MCP session.

### Fixed

- Setup-generated stdio snippets now preserve the selected bootstrap, essential,
  or full surface across client restarts.
- Raw Elementor write blocks return a non-retryable typed repair path instead of
  leaving agents to repeat `php-execute` fallbacks.
- Direct Bootstrap always exposes task-start, stays at eight tools, and uses
  task-aware Elementor, Gutenberg, content-model, and site-admin profiles.

## [1.0.0-alpha.73] - 2026-07-16

### Added

- Repository documentation freshness gate in CI and release packaging.
- FSE blueprint engine path with constrained layout + FSE transaction apply.
- Brand-kit preview/diff mode and option/theme_mod restore points.
- Setup “Apply now” for MCP tool surface; extra admin e2e coverage.
- Figma→native DesignEvidence/native plan/CSS-gap gate; front-end visual matrix.
- Authored v2 blueprints with Elementor layout intent; transaction full-tree
  path; e2e real blueprint-apply + screenshots.

### Changed

- Synchronized evergreen install/client guides, prompts, capability counts,
  changelog retention (5-release retention policy), and skills with the
  canonical task-start workflow.
- Elementor blueprint writes use transactional snapshot + readback rollback.
- Elementor schema summaries rank useful controls first and support focused
  control queries for smaller repair responses.

### Fixed

- Elementor V3 batch dry-runs collect all validation failures in one response,
  return exact schema repair requests, and never persist partial batches.
- Common typography aliases normalize to live Elementor keys and report the
  normalization without requiring another model round trip.
- Admin e2e writes run once, restore shared settings, and serialize CI access
  to the shared WordPress database.
- Visual e2e writes obtain a task context token first, and REST nonce discovery
  no longer waits for a missing optional DOM attribute.
- Prompt Library enqueues catalog CSS; blueprint card action buttons have spacing.
- Connect e2e handles multi-snippet strict mode; visual e2e uses session REST helpers.

## [1.0.0-alpha.72] - 2026-07-16

### Added
- Actionable audit error codes/messages and RemediationHints at task-start.
- Direct Elementor data tools, gutenberg-validate, builtin skills, agents-md-sync.
- Direct task-start write gate and recurring error surfacing.

### Changed
- README Elementor-first with full capability tables.


## [1.0.0-alpha.71] - 2026-07-16

### Added
- Pluginless Direct self-improvement (local skills/memory + task-start).
- ACF, multi-plugin SEO, and CPT/taxonomy registration abilities.
- Discovery wiring for wave-3 admin ops profiles.
- Dual-mode AI install prompts.

### Fixed
- wp-admin paste prompt starts with task-start.
- REST parity security review items (audit redaction, search visibility, rest-request read-only).
