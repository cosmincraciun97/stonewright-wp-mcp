# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

## [1.0.0-alpha.84] - 2026-07-24

### Added

- Add a transactional native Elementor Loop Grid/Carousel workflow with live
  schema mapping, query probing, staged templates, idempotency, readback, and
  rollback.
- Learn Elementor schema repairs only after verified readback in two distinct
  tasks or explicit operator approval.

### Changed

- Serialize Elementor writes per post and keep learned schema guidance bounded
  by runtime compatibility and task-start limits.

## [1.0.0-alpha.83] - 2026-07-24

### Added

- `stonewright/elementor-document-health` reports architecture, serialized
  size, V3/V4 counts, invalid setting paths, and bounded `e-paragraph` ids
  without exposing document content.

### Fixed

- Elementor writes invalidate only the target post's generated CSS instead of
  clearing Elementor's global CSS cache and slowing the next editor load.
- Mixed documents allow surgical V3 batch writes under an explicit V3 parent
  while ambiguous root adds and high-level full-document writes stay blocked.
- Elementor schema failures identify the first rejected setting path, expected
  shape, and received type without echoing user content.
- The V4 atomic-abilities checkbox now submits an explicit disabled value when
  unchecked; enable and disable persistence is covered bidirectionally.

## [1.0.0-alpha.82] - 2026-07-23

### Fixed

- REST mutation audit stores hashes and byte counts instead of free-form code,
  instruction, skill, and memory bodies.
- User-scoped Direct learning is stored globally across configured sites, and
  refreshed corrections move to the newest memory position.
- Elementor V3 production mutations enforce authorized breakpoint scope and
  roll back when readback detects non-target breakpoint drift.
- Compact task-start retains target binding fields while staying inside the
  enforced non-visual and visual token budgets.

## [1.0.0-alpha.81] - 2026-07-23

### Security

- `php-execute` permanently blocks filesystem mutation of theme/plugin/core code files.
- Theme file apply requires full-file validation, a wp-admin-reviewed custom-code
  proposal/grant, atomic write, readback, smoke, and rollback. Backups use opaque
  references, non-executable files, web-access guards, and a typed restore ability.
- Production environment with non-`production-safe` mode shows a P0 admin warning.

### Fixed

- Direct learning no longer silently falls back to `_global` for unknown site aliases.
- Direct sessions use authoritative plugin-site memory when the typed bridge exists,
  reject target changes after task-start, and fall back locally only when the plugin
  route is demonstrably absent.
- Expected safety blocks no longer promote active project/user learning.

### Added

- Materialized effect/incident audit fields and filters in plugin mode, aligned
  Direct JSONL records, Memory UI lifecycle tabs and controlled legacy-feedback
  migration, Direct learning visibility labels, and canonical
  `custom_code_operator_grant` rule.

## [1.0.0-alpha.80] - 2026-07-22

### Added

- Seven canonical permanent operating rules (responsive device tabs, separate
  verification tab, Figma section isolation, breakpoint isolation, native-first
  styling, fastest safe interface, verified learning) injected in Plugin and
  Direct modes and mirrored in `skills/agent-operating-rules`.
- Cross-mode `stonewright-learning-record` receipts: `stored`, `backend`,
  `scope`, `memory_id`, `storage_ref`, `verified` after write-then-readback.
  Accepts canonical `topic`+`correction` and legacy Direct `text`.
- Central Stonewright REST mutation audit for POST/PUT/PATCH/DELETE under
  `stonewright/v1`, with ability-level deduplication, `blocked` status, exact
  pagination counts, and insert-failure diagnostics.
- `MethodRouter` capability matrix (`typed_api` → `editor_command_bus` →
  `admin_form` → `browser_ui`).
- Elementor responsive scope guards (`ResponsiveScope` PHP + visual
  `assertResponsiveScope`) with non-target breakpoint hashing and
  `unsupported_responsive_control` no-ops.

### Fixed

- Explicit user/project learning no longer stored as audit `feedback` type;
  task-start reserves contextual slots for user/project memory before feedback.
- Audit log UI copy matches real coverage (Stonewright mutations only).
- Learning-record success requires verified readback in both modes.

### Documentation

- Design-to-WordPress skill requires per-section Figma manifests and separate
  editor/verification browser tabs.
- Agent operating rules skill documents the seven canonical product defaults.
