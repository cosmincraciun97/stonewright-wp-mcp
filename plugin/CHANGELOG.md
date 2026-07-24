# Changelog

## [Unreleased]

## [1.0.0-alpha.86] - 2026-07-24

### Fixed

- OAuth dynamic client registration no longer fails when the optional internal
  self-test header is absent.

## [1.0.0-alpha.85] - 2026-07-24

### Added

- OAuth 2.0 MCP authentication with PKCE, discovery, rotating refresh tokens,
  consent, Connected Apps, and 17-client onboarding.
- Independent `/mcp/stonewright-oauth` server while retaining Application
  Password authentication on `/mcp/stonewright`.

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
