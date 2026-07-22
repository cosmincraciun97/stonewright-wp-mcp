# Changelog

## [Unreleased]

## [1.0.0-alpha.80] - 2026-07-22

### Added

- Canonical permanent operating rules parity with the plugin (responsive tabs,
  verification tab roles, Figma section isolation, breakpoint isolation,
  native-first styling, method ladder, verified learning).
- `stonewright-learning-record` canonical receipt (`verified:true` after
  readback) accepting `topic`+`correction` or legacy `text`; Direct memory
  entries carry stable ids, dedupe, and non-secret `storage_ref`.

## [1.0.0-alpha.79] - 2026-07-22

### Fixed

- Profile refreshes never disable gateway tools, and advisory recommended-tool lists are additive. Tools are removed only after an authoritative plugin resolve, eliminating profile-refresh lockouts.

### Added

- `wordpress-mcp-status` and `client-surface-check` report live registration state rather than only the startup snapshot.
- The companion re-lists and emits `notifications/tools/list_changed` when it sees a newer `surface_revision`. Direct mode exposes the same contract through its in-process profile counter.
