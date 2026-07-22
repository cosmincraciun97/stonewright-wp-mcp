# Changelog

## [Unreleased]

### Fixed

- Profile refreshes never disable gateway tools, and advisory recommended-tool lists are additive. Tools are removed only after an authoritative plugin resolve, eliminating profile-refresh lockouts.

### Added

- `wordpress-mcp-status` and `client-surface-check` report live registration state rather than only the startup snapshot.
- The companion re-lists and emits `notifications/tools/list_changed` when it sees a newer `surface_revision`. Direct mode exposes the same contract through its in-process profile counter.
