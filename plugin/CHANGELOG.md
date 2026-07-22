# Changelog

## [Unreleased]

## [1.0.0-alpha.78] - 2026-07-22

### Fixed

- Direct task-start latch is strictly per-site (no any-site fallback); write tools pass the resolved site alias.
- Intentional Elementor removals/full rebuilds pass `force_destructive` so confirmed size reductions are allowed after snapshot.
- Elementor write readback rollback reports restore failure when previous document could not be re-persisted.
- V4 `elementor-v4-update-node` rejects known settings with the wrong `$$type` envelope.

- Elementor write abilities return the real gate/validator error via
  `ElementorData::write_error_for_ability()` instead of generic write_failed.
- Context-token error names `stonewright-task-start` first.
- V3 architecture mismatch and raw php-execute Elementor write remediation
  hints name concrete V4/V3 tools including `elementor-v4-update-node`.
- Memory schema install verifies columns via `Memory::table_schema_ok()` before
  bumping `stonewright_memory_schema_version`; failed installs log
  `memory_schema_install_failed` and retry on next `init`.
- `put_typed` / error-pattern learning promotion / AuditLog observe path log
  failures; `stonewright/learning-record` returns
  `stonewright_memory_write_failed` when the table cannot store the row.

### Added

- Experimental `stonewright/elementor-v4-update-node`: surgical settings patch
  for one Atomic node by id (merge/replace, dry_run, snapshot, integrity-gated
  write). Never writes the inspector `atomic_tree` projection.
- Elementor tree reads default to capped summary outlines: V3
  `elementor-v3-get-page-structure` (existing), V4
  `elementor-v4-read-atomic-tree` (`responseMode` + `max_nodes`), shared
  `Support\TreeSummary` utility with `estimated_tokens`.
- Non-blocking `task_start_hint` on pre-session read ability results (MCP
  sessions) until `task-start` / context-bootstrap / workflow-preflight marks
  the session; latch is a 30-minute session transient.
- Retry-storm brake: `ErrorPatterns::occurrence_count` + `escalate_error`
  rewrites identical ability WP_Errors on the 2nd+ occurrence with STOP
  guidance, `occurrences`, and RemediationHints repair text (wired from
  `AbilityRegistry::execute_with_context_guard` after audit observe).
- Elementor Integrity Gate P0 on `ElementorData::write` (double-encode, size
  collapse, widgetType remap blocks; readback restore).
- Tree validation preserves unknown settings and coexisting `e-*` widgets.
- Memory admin schema-health notice when the memory table is missing/outdated.

## [1.0.0-alpha.77] - 2026-07-22

### Fixed

- Task-start always signals tools re-list when admin surface is full/essential or
  the session profile leaves bootstrap (stdio companion surface sync).

### Added

- Bootstrap MCP surface ≤12 tools with php-execute, confirmation, content and
  Elementor read tools, theme-file-read.
- `stonewright/theme-file-read` and `stonewright/theme-file-patch` allowlisted
  theme file abilities with backup and production confirmation.
- `php-execute` `read_only` flag; clearer Elementor write-vs-read policy.
- Task-start `write_target_url` / active write target labeling.
- Direct remote Elementor data path via REST meta when registered.

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

- `task-start` exposes `configured_mcp_surface` and resolves task-specific tool
  recommendations without changing the admin-selected surface.
- Activating a site-wide tool profile now requires `manage_options`; read-only
  profile resolution remains available to authenticated readers.
- Bootstrap task-start binds its recommended profile to `Mcp-Session-Id`, so
  tools expand for that session without changing the saved site preference.

### Fixed

- Setup Apply-now verifies the persisted value, generated stdio snippets use it,
  and shared admin JavaScript changes invalidate browser caches.
- Elementor raw-write blocks are explicitly non-retryable and direct agents back
  to typed schema requests plus one consolidated batch dry-run.
