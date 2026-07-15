# Changelog

## [Unreleased]

## [1.0.0-alpha.70] - 2026-07-16

### Added

- `tool-profile` resolve action and priority-ordered profile tools (blueprints first).
- Strict blueprint `engine` gate; Gutenberg columns / alignfull / hero media-text.
- Brand surface scanner and 5-release retention policy tests.

### Changed

- Companion consumes plugin-resolved tool lists; falls back only offline/Direct.

## [1.0.0-alpha.69] - 2026-07-15

### Added

- Essential tools for blueprints, brand kits, digest, pulse, learning.
- HTML widget site hard-block; QA block on blueprint apply; rebuilt blueprint specs.

## [1.0.0-alpha.68] - 2026-07-15

### Fixed

- Audit payload overflow inside fixed table layout.
- Mode pill contrast on light theme (header chrome always light-on-dark).
- Blueprint Copy AI Prompt now pastes a full multi-line tool playbook.

## [1.0.0-alpha.67] - 2026-07-14

### Added

- Added the canonical `stonewright/task-start` gateway, truthful token-budget
  enforcement, provenance linting, and PHP 8.1-8.5 CI coverage.
- Added architecture-aware Elementor task routing and explicit target selection
  for empty documents on Elementor 4 runtimes.

### Changed

- Bundled expertise without exact live runtime evidence now stays candidate;
  promotion requires fixture, schema, editor, frontend, and readback proof.
- Real compact task start now measures 634 non-visual and 837 visual tokens,
  including architecture routing.
- Visual DesignEvidence now requires verifiable source hashes, measured node
  bounds, and responsive viewport evidence.

### Fixed

- Restored draft skill reactivation from the admin and skills API.
- Preserved inactive Elementor controls during full-tree validation while
  keeping newly supplied settings strict.
- Rejected missing or duplicate Elementor node IDs before writes and kept
  revision backups on the revision instead of redirecting them to its parent.
- Rejected Atomic widgets in V3 trees, corrupted Romanian Unicode remnants,
  placeholder copy, and permissive non-equal write readback.
- Added actionable batch failures and readback rollback to V3 page builds.
- Preserved schema-validated native `flex_wrap` and `_flex_*` container
  controls and rejected normalized no-op updates instead of reporting silently
  discarded layout settings as applied.
- Inferred native responsive container controls when Elementor omits that flag
  from its live schema, preserving explicit mobile/tablet layout overrides.

### Security

- Blocked raw Elementor document mutation through `php-execute`; typed write
  abilities remain the only supported mutation path.


## Older releases

Older release notes were removed under the 5-release retention policy. See `docs/releases/` for the retained notes and `docs/licensing.md` for permanent licensing history.
