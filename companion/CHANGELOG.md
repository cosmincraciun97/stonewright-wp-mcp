# Changelog

## [Unreleased]

## [1.0.0-alpha.92] - 2026-07-30

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
