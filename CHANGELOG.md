# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

## [1.0.0-alpha.77] - 2026-07-22

### Fixed

- **MCP tool surface sync (P0):** `task-start` always emits `tools_changed` +
  `re_list_instruction` when the effective profile is not bootstrap **or** the
  admin-configured surface is already essential/full, so stdio companions stuck
  on env bootstrap re-register `php-execute` without REST workarounds.
- Companion parses ability JSON from `content[].text` when `structuredContent`
  is missing (common WP MCP transport), so profile drift actually triggers
  proxy re-registration + `tools/list_changed`.

### Added

- Bootstrap surface expands to ≤12 tools with runtime escape hatches:
  `php-execute`, confirmation token, `site-info`, `content-get-page`, Elementor
  structure/schema reads, `theme-file-read`.
- Companion local tool `stonewright-client-surface-check` and
  `stonewright doctor --client-surface` for profile/client mismatch diagnosis.
- Theme abilities `stonewright/theme-file-read` and `stonewright/theme-file-patch`
  (allowlisted child-theme CSS/JS/PHP with backup + production confirmation).
- `php-execute` `read_only:true` input to block mutation APIs while allowing
  Elementor meta reads.
- `task-start` returns `write_target_url` / `site.active_write_target` for clear
  live vs local binding.
- `elementor-design` profile includes theme-file tools + confirmation token.
- **Direct mode:** remote Elementor `data-get` / `data-update` without the editor
  via core REST meta when registered (WP-CLI still preferred on local hosts).

### Changed

- Task-start non-visual compact token budget raised to **800** (write-target +
  re-list signals for client surface sync).

## [1.0.0-alpha.76] - 2026-07-16

### Added

- Direct mode: permanent product HARD RULES on every `stonewright-task-start`
  (single-target scope, remote tool path, no ad-hoc plugins, HTTP-first
  automation, additive content models) plus five new enabled `_builtin` skills.
- Plugin: permanent operating rules in agent instructions (not Safety Memory UI)
  covering the same workflow discipline plus Elementor native-first lessons
  (responsive typography, Nested Carousel offset, swiper overflow, CSS parent class).
- Built-in skill pack `agent-operating-rules` for matched task playbooks.

### Changed

- Direct `AGENTS.md` managed template includes the permanent operating rules.

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
