# Changelog

## [Unreleased]

## [1.0.0-alpha.84] - 2026-07-24

### Changed

- Refresh the versioned companion for the plugin's native wire-loop tool and
  runtime-compatible verified-learning surface.

## [1.0.0-alpha.83] - 2026-07-24

### Added

- The Elementor design profile includes the plugin's document-health ability.

### Fixed

- Companion profile guidance reflects surgical mixed-document V3 writes and
  the new health-first workflow.

## [1.0.0-alpha.82] - 2026-07-23

### Fixed

- User-scoped learning uses global Direct storage across sites.
- Refreshed deduplicated memory moves to the newest position before highlight limits.

## [1.0.0-alpha.81] - 2026-07-23

### Fixed

- Direct `resolveSelfImproveScope` no longer silently maps unknown site aliases to `_global`.
- Explicit unknown site on `learning-record` fails with `site_alias_unresolved` and does not write global memory.
- A task-bound site URL change invalidates learning instead of sending a token to a different target.
- Plugin authentication, transport, and server failures no longer fall back to local memory.

### Added

- Learning receipts include `memory_backend`, `storage_scope`, `visibility` (local-only), and site alias.
- Task-start returns `target_context` with memory backend and visibility.
- Direct mode uses the typed plugin task-start/learning bridge as the authoritative
  store when present; only a confirmed missing route selects local storage.
- Direct JSONL audit rows carry the same effect and incident fields as plugin audit
  rows, including request id, resource, execution, verification, rollback, hashes,
  backend, target fingerprint, mode, and severity.
- Canonical rule `custom_code_operator_grant` parity with the plugin.

## [1.0.0-alpha.80] - 2026-07-22

### Added

- Canonical permanent operating rules parity with the plugin (responsive tabs,
  verification tab roles, Figma section isolation, breakpoint isolation,
  native-first styling, method ladder, verified learning).
- `stonewright-learning-record` canonical receipt (`verified:true` after
  readback) accepting `topic`+`correction` or legacy `text`; Direct memory
  entries carry stable ids, dedupe, and non-secret `storage_ref`.
