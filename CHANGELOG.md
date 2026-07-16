# Changelog

## 1.0.0-alpha.71

### Added
- Pluginless Direct self-improvement (local skills/memory + task-start).
- ACF, multi-plugin SEO, and CPT/taxonomy registration abilities.
- Discovery wiring for wave-3 admin ops profiles.
- Dual-mode AI install prompts.

### Fixed
- wp-admin paste prompt starts with task-start.
- REST parity security review items (audit redaction, search visibility, rest-request read-only).


All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-alpha.70] - 2026-07-16

### Added

- Tool profile `action=resolve` as the single ordered tool list source for the companion.
- Deterministic client-cap trimming via `STONEWRIGHT_MCP_MAX_TOOLS` (trim from priority tail).
- Companion emits tools/list_changed when a tool-profile response sets `tools_changed`.
- Blueprint apply `engine` is strict: explicit `elementor` fails when Elementor is inactive.
- Gutenberg renderer maps `row`/`column` to `core/columns`/`core/column`, full-width section bands, palette buttons, and hero `core/media-text`.
- Blueprint section `role` (hero and others) in DesignSpec schema; all 12 blueprints mark hero.
- Public surface brand scanner and 5-release retention tests; licensing history in `docs/licensing.md`.

### Changed

- Companion fallback profile lists include blueprints and brand kits on essential and thematic profiles.
- Blueprint AI prompts and agent instructions map Elementor / Gutenberg / FSE to the engine parameter.
- Changelog and `docs/releases/` retain only the most recent five versions.

### Fixed

- Silent Elementor-to-Gutenberg fallback on explicit `engine=elementor` is removed.

## [1.0.0-alpha.69] - 2026-07-15

### Added

- Essential profile exposes blueprint, brand-kit, page-digest, build-tree, site-pulse, learning-record.
- Tool profile `extras` + `tools_changed` / re-list instruction for MCP clients.
- Premium blueprints (12) with rows, images, per-industry copy; BlueprintSpecQualityTest guardrail.
- Gutenberg renderer applies design tokens (button/heading/body fonts and colors).
- Site-level HTML widget hard-block (`stonewright_allow_html_widgets`, default off).
- `QaReport` block on blueprint-apply responses.
- Skill pack `skills/elementor-site-clone`.
- Direct mode blueprint list/get/apply (Gutenberg) + companion `init` CLI.
- Security review notes in `docs/security-review-2026-07.md`.

### Changed

- Essential tool budget raised to 30; Direct full surface budget raised to 50.

## [1.0.0-alpha.68] - 2026-07-15

### Fixed

- Audit log open payload no longer overflows the table; fixed layout + max-height scroll.
- Mode pill (`development` / staging / production-safe) always uses light-on-dark header colors so light theme is readable.
- Blueprint / brand-kit **Copy AI Prompt** now copies a full multi-line playbook (tools, sections, palette, constraints), not a one-liner.

### Changed

- Admin shell content clips horizontal overflow; audit Details column width constrained.

## [1.0.0-alpha.67] - 2026-07-15

### Added

- Premium admin shell: sticky nav, mode pill, dark/light theme toggle, notice
  drawer, page-scoped CSS for Setup, Abilities, Dashboard, Audit, Skills,
  Memory, Sandbox, and Blueprints.
- Semantic design tokens with a deliberate dark palette (desaturated indigo
  accents) and WCAG contrast guardrails in PHPUnit.
- Site pulse, change timeline/restore, blueprints and brand kits, playbooks,
  page digest/build-tree, MCPB bundle, admin-bar kill switch, GitHub updater.
- Companion Direct mode: core WordPress REST tools without the plugin, multi-site
  config, HTTP-safe Application Passwords, `scripts/e2e-direct.mjs` smoke script.
- `stonewright/feedback-capture` and learning-record fields `trigger`,
  `severity`, `source`; audit ErrorPatterns with Recurring errors UI and
  task-start warnings.
- CI token-surface budgets for plugin and companion measure scripts.

### Changed

- Setup treats HTTP transport as informational (not a hard failure).
- Root README documents plugin and plugin-less install paths equally.

### Fixed

- Dark mode text/input contrast across admin pages (legacy raw hex removed).
- Sandbox tab underline leak; Abilities table sticky header overlap.
- Blueprints page missing stylesheet enqueue (raw dump fixed).

## [1.0.0-alpha.66] - 2026-07-14

### Added

- Added the real `stonewright/task-start` one-call gateway, provenance linting,
  PHP 8.1-8.5 CI, Visual CI, reproducible Visual release packaging, and nested
  progressive-disclosure skill loading.
- Added architecture-aware Elementor preflight with document classification,
  explicit empty-document targets, and hard blocking for ambiguous Elementor 4
  writes.

### Changed

- Reclassified bundled expertise without live fixture fingerprints as
  advisory candidates; verification now requires persisted fixture, schema,
  editor, frontend, and readback evidence.
- Reduced the measured real task-start payload to 634 estimated tokens for
  non-visual work and 837 for visual work, including architecture routing.
- Required verifiable visual-source hashes, measured semantic bounds, and at
  least desktop/mobile evidence before native visual planning.

### Fixed

- Kept the default companion profile aligned with the native design planner.
- Restored draft skill reactivation and preserved inactive Elementor controls
  during full-tree validation.
- Rejected missing or duplicate Elementor node IDs before writes and kept
  revision backups on the revision instead of redirecting them to its parent.
- Fixed Gutenberg batch `client_id` refs, missing-ref preflight, recursive block
  schema validation, and strict attribute type/enum checks.
- Fixed Elementor V4 update/move/undo/redo readback and clean retry after
  automatic rollback.
- Blocked Atomic `e-*` widgets in V3 trees, stripped Unicode escape remnants,
  mojibake, placeholder copy, and non-equal Elementor write readback.
- Added exact failed-operation diagnostics, repair hints, optimistic hashes,
  readback verification, and automatic restore to high-level V3 page builds.
- Preserved schema-validated native `flex_wrap` and `_flex_*` container
  controls and rejected normalized no-op updates instead of reporting silently
  discarded layout settings as applied.
- Recognized native responsive container controls when Elementor's live control
  arrays omit responsive metadata, preserving explicit mobile/tablet layouts.

### Security

- Blocked `php-execute` from mutating protected Elementor document metadata or
  calling internal Elementor writers outside typed permission, backup,
  validation, confirmation, readback, and audit gates.


## Older releases

Older release notes were removed under the 5-release retention policy. See `docs/releases/` for the retained notes and `docs/licensing.md` for permanent licensing history.
