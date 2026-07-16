# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
  changelog retention, and skills with the canonical task-start workflow.
- Elementor blueprint writes use transactional snapshot + readback rollback.

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

## Older releases

Older release notes were removed under the 5-release retention policy. See `docs/releases/` for the retained notes and `docs/licensing.md` for permanent licensing history.
