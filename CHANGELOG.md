# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

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

## [1.0.0-alpha.79] - 2026-07-22

### Added

- Tool-profile responses identify truncation, list omitted tools, and apply the
  selected profile to the current MCP session without narrowing the configured
  surface.
- A monotonic `surface_revision` propagates tool-surface changes; the companion
  re-lists tools and emits `notifications/tools/list_changed` when it advances.
- `stonewright/elementor-v3-repair-document` provides backup-first,
  idempotent recovery for malformed Elementor documents.
- A recovery runbook and client-surface diagnostics cover capped or stale MCP
  clients without unsupported REST or raw-meta workarounds.

### Fixed

- Essential and capped tool surfaces retain write-critical Elementor and theme
  tools in deterministic priority order.
- Elementor validation is scoped to touched nodes while whole-tree structure,
  backup, permission, confirmation-token, audit, and integrity gates remain
  enforced.
- Elementor transactions no longer fall back to raw metadata writes; unknown
  settings are preserved, atomic siblings do not block valid V3 edits, and
  cleared responsive sliders validate correctly.
- PHP parse failures return a dedicated code with actionable remediation, and
  live registration status replaces stale startup-only reporting.

## [1.0.0-alpha.78] - 2026-07-22

### Fixed

- Direct task-start latch is strictly per-site (no any-site fallback); write tools pass the resolved site alias.
- Intentional Elementor removals/full rebuilds pass `force_destructive` so confirmed size reductions are allowed after snapshot.
- Elementor write readback rollback reports restore failure when previous document could not be re-persisted.
- V4 `elementor-v4-update-node` rejects known settings with the wrong `$$type` envelope.

- Elementor write abilities surface the real integrity/validator error (code +
  hints) instead of generic "Could not save Elementor data."
- Context-token missing error points agents to canonical `stonewright-task-start`
  (compatibility path: context-bootstrap).
- Remediation for `v3_architecture_mismatch` and raw php-execute Elementor
  writes names concrete V4/V3 tools and forbids blind php-execute retries.
- Memory schema version option bumps only after `table_schema_ok()` verifies all
  v3 columns exist (failed `dbDelta` no longer pretends install succeeded).
- `Memory::put_typed()` logs `memory_put_failed` with `wpdb_error` + schema
  health; `learning-record` returns `stonewright_memory_write_failed` on store
  failure; audit error-pattern promotion logs write/throw failures instead of
  failing silently.

### Added

- **Elementor V4 surgical node update:** experimental ability
  `stonewright/elementor-v4-update-node` patches one Atomic node's settings by
  id (merge/replace, dry_run, snapshot, no integrity bypass). Remediation for
  V3 architecture mismatch points agents at this tool.
- **Elementor summary read defaults:** Direct `elementor-data-get` and plugin
  `elementor-v4-read-atomic-tree` return a capped outline by default
  (`responseMode=summary`, max 200 nodes; `full` opts into raw tree). Shared
  `TreeSummary` backs V3 get-page-structure and V4 atomic reads. Write backups
  always snapshot the full tree.
- **Task-start delivery (Phase 4):** companion proxy merges plugin
  `initialize.instructions` into MCP handshake instructions; Direct write latch
  is per-site with a 30-minute TTL (matches plugin context token); pre-session
  read responses carry a non-blocking `task_start_hint` until task-start runs
  (plugin MCP sessions + Direct registry).
- **Retry-storm brake:** repeated identical ability/tool failures (2+) escalate
  with a hard-stop envelope (`STOP: this exact error occurred N times…`) plus
  `occurrences` and `repair` fields so agents stop blind retries.
  Plugin: `ErrorPatterns::escalate_error` after audit observe; Direct:
  `escalateDirectError` at the registry dispatch choke point.
- **Elementor Integrity Gate (P0)** on plugin `ElementorData::write` and Direct
  `elementor-data-update`: reject double-encoded JSON, size collapse, and
  widgetType remaps; auto-restore previous document on readback failure.
- Tree validation preserves unknown settings and allows coexisting `e-*` atomic
  widgets (no forced convert-to-pass).
- Memory admin page error notice when the `stonewright_memory` table is missing
  or outdated (learning promotion cannot store rows).
