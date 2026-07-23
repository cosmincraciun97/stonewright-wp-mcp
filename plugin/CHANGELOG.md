# Changelog

## [Unreleased]

## [1.0.0-alpha.81] - 2026-07-23

### Security

- `php-execute` permanently blocks filesystem mutation APIs (theme/plugin/core code writes). Use `theme-file-patch` instead.
- Theme file writes validate the complete candidate (in-process PHP parser), require a wp-admin-reviewed single-use custom-code grant for PHP/CSS/JS apply, atomic replace, readback, bootstrap smoke, and automatic rollback.
- Theme backups are stored under opaque references with non-executable filenames and web-access guards; `stonewright/theme-backup-restore` restores only an owned, hash-verified backup.
- Expected safety blocks no longer promote active project/user learning; audit feedback stays unresolved until verified repair.
- Production WordPress environment with non-`production-safe` Stonewright mode surfaces a P0 admin warning.

### Added

- `ProtectedFilesystemWriteGuard`, `PhpSyntaxValidator`, `ThemeWriteTransaction`, `CustomCodeGrant`.
- Materialized audit columns and admin filters for event/operation/resource/change-set, execution/verification/rollback, hashes, errors, backend, mode, and severity; failed audit persistence surfaces a degraded-state notice.
- Custom-code proposal review page showing bounded diff, hashes, native-gap evidence, test plan, and rollback plan before minting a path/hash/user/site-bound grant.
- Memory admin lifecycle tabs for user/project rules, verified repairs, unresolved incidents, audit feedback, and reference entries; controlled legacy-feedback migration preserves history.
- Canonical rule `custom_code_operator_grant` (Plugin/Direct/skill parity).
- Learning receipts report `memory_backend` and visibility labels.

### Fixed

- Direct learning no longer silently falls back to `_global` for unknown site aliases.
- Direct learning receipts label local-only visibility (not wp-admin Memory UI).
- Task context tokens bind site fingerprint, environment, Stonewright mode, and memory backend; task-start receipts expose those values.

## [1.0.0-alpha.80] - 2026-07-22

### Added

- Canonical permanent operating rules with Plugin/Direct/skill parity fingerprint.
- Verified learning-record receipts (write-then-readback) and user/project memory types.
- Central REST mutation audit under `stonewright/v1` with blocked status and exact counts.
- MethodRouter ladder and Elementor ResponsiveScope isolation helpers.

## [1.0.0-alpha.79] - 2026-07-22

### Fixed

- Session tool profiles now apply on essential surfaces through task-start and tool-profile activation, and always union with the configured surface instead of narrowing it.
- The architecture-ambiguous Elementor gate names `post_id` as the unblock path; when no post is inspected, the router reports `not_inspected` instead of falsely reassuring `unknown`.
- php-execute parse failures return `stonewright_php_parse_error` with transport guidance instead of a generic failure.
- Elementor settings validation accepts the cleared responsive-slider sentinel (`{size:'', sizes:[]}`) instead of rejecting it as `invalid_shape`.
- `update-element` merge and replace preserve pre-existing unknown Elementor settings instead of rejecting a valid patch because of a key the agent never sent.
- Per-node Elementor content validation is scoped to touched nodes; structural integrity, backup, permission, confirmation-token, and audit gates remain whole-tree.
- V3 batch mutation uses a subtree-aware architecture gate, so atomic nodes elsewhere do not block a legitimate V3 edit; `widgetType` is never auto-converted.
- The Elementor transaction runner no longer falls back to raw meta writes that bypass the integrity gate and validator; it rolls back and surfaces the real error.

### Added

- Tool-profile responses report `degraded`, `truncated_tools`, and `truncation_hint`; `session_profile_applied` is paired with `session_profile_reason`.
- `theme-file-patch` joined the essential surface, and the `elementor-design` profile is ordered write-critical-first so capped clients retain write gates.
- `stonewright/elementor-v3-repair-document` provides backup-first, idempotent recovery for double-encoded data and duplicate or missing ids without converting `widgetType` or stripping settings.
- Gateway and status responses expose monotonic `surface_revision`, backed by the `stonewright_tool_surface_changed` action, so clients detect and re-list a stale tool surface.

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
