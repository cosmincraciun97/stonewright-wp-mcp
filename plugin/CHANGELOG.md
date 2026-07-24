# Changelog

## [Unreleased]

## [1.0.0-alpha.84] - 2026-07-24

### Added

- Add `stonewright/elementor-wire-loop` for transactional native Loop
  Grid/Carousel planning and writes with live schemas, staged templates,
  idempotency, readback, and rollback.
- Add controlled schema-repair learning that activates only after two distinct
  verified tasks or explicit approval.

### Changed

- Serialize Elementor page writes and bound learned repairs by runtime,
  retention, and task-start limits.

## [1.0.0-alpha.83] - 2026-07-24

### Added

- Bounded, content-free Elementor document health diagnostics for architecture,
  document weight, invalid settings, and excessive `e-paragraph` use.

### Fixed

- Elementor CSS invalidation is scoped to the changed post.
- Mixed documents permit surgical V3 batch writes under explicit V3 parents;
  root adds and full-document renderers remain blocked.
- Schema errors identify the rejected setting path and expected/received shape.
- The V4 abilities checkbox persists both checked and unchecked states.

## [1.0.0-alpha.82] - 2026-07-23

### Fixed

- REST mutation audit replaces free-form code, instruction, skill, and memory
  bodies with irreversible hashes and byte counts.
- Elementor V3 batch mutations enforce authorized breakpoint scope and verify
  non-target breakpoint hashes before persisting settings.
- Compact task-start preserves target binding evidence within enforced token budgets.

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
