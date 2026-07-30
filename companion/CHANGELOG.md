# Changelog

## [Unreleased]

### Added

- Direct mode serves the same native rule registry as the plugin, through the new
  `stonewright-rules-get` tool. The registry ships with the companion, so a
  pluginless site gets identical rules, severities, and enforcement
  classifications. `stonewright-task-start` returns the registry digest and names
  the tool that resolves it; cache by digest and refetch only when it changes.

### Changed

- `stonewright-rules-get` is available on the Direct bootstrap surface, because
  task start hands out a rule digest and the tool that resolves it has to be
  reachable before profile expansion. `stonewright-skill-list` left that cold
  surface to make room: task start already returns the matched skill slugs, and
  the tool returns as soon as the profile expands.
- The Direct registry loader validates exact fields, severities, scopes,
  enforcement claims, and duplicate ids before exposing packaged rules.

## [1.0.0-alpha.91] - 2026-07-26

### Changed

- Align the companion package with the plugin release that adds compact
  design-direction guidance and the guided live-editor Visual Workspace.

## [1.0.0-alpha.90] - 2026-07-26

### Changed

- Align the companion package with the plugin release that restores strict MCP
  schema discovery in ChatGPT.

## [1.0.0-alpha.89] - 2026-07-26

### Changed

- Align the companion package with the plugin release that restores OAuth
  consent completion for ChatGPT and other standards-compliant clients.

## [1.0.0-alpha.88] - 2026-07-25

### Changed

- Align the companion package with the plugin release that adds Design Studio,
  reviewed skill lifecycle, and the Visual Workspace.

## [1.0.0-alpha.87] - 2026-07-25

### Changed

- Align the companion package with the plugin release that adds evidence-based
  visual verification and the first-section design checkpoint.

## [1.0.0-alpha.86] - 2026-07-24

### Changed

- Align the companion package with the OAuth registration hotfix release.

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
