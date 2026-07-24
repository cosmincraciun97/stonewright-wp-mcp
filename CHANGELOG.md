# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

### Added

- Design Directions: a site's design language is now stored as a validated,
  versioned contract instead of prose, with append-only revision history and one
  active direction per site.
- Eight MCP abilities to list, read, save, capture, activate, restore, and
  synchronize design directions. Activation, restore, and synchronization
  replace live design intent, so each is gated by permission, task context
  token, and a confirmation token bound to the exact target in production-safe
  mode.
- Capture a starting direction from an existing Elementor kit: colors,
  typography, spacing, breakpoints, and button styles become a reviewable draft
  contract that records where each value came from. It shows the draft without
  storing anything unless asked, and never guesses a value the kit did not
  report.
- Push a design direction back into the Elementor kit, in two steps. A dry run
  shows exactly which kit globals would change, what the kit has no setting for,
  and any value it cannot store. The write step needs that dry run, refuses to
  proceed if the kit changed in the meantime, backs the kit up first, leaves every
  setting it was not asked to change alone, and checks the result before
  reporting success.

## [1.0.0-alpha.86] - 2026-07-24

### Fixed

- OAuth client registration now accepts requests without the internal self-test
  header, restoring sign-in from Codex and other standards-compliant clients.

## [1.0.0-alpha.85] - 2026-07-24

### Added

- Browser-based OAuth for a dedicated `stonewright-oauth` MCP resource with
  PKCE, discovery, rotating refresh tokens, consent, revocation, and Connected
  Apps.
- OAuth-first onboarding for 17 AI clients while preserving the independent
  Application Password route.

### Security

- OAuth tokens are audience-bound to the dedicated MCP resource; redirect URIs,
  scopes, transport, registration rates, replay, and refresh rotation fail
  closed.

## [1.0.0-alpha.84] - 2026-07-24

### Added

- Add a transactional native Elementor Loop Grid/Carousel workflow with live
  schema mapping, query probing, staged templates, idempotency, readback, and
  rollback.
- Learn Elementor schema repairs only after verified readback in two distinct
  tasks or explicit operator approval.

### Changed

- Serialize Elementor writes per post and keep learned schema guidance bounded
  by runtime compatibility and task-start limits.

## [1.0.0-alpha.83] - 2026-07-24

### Added

- `stonewright/elementor-document-health` reports architecture, serialized
  size, V3/V4 counts, invalid setting paths, and bounded `e-paragraph` ids
  without exposing document content.

### Fixed

- Elementor writes invalidate only the target post's generated CSS instead of
  clearing Elementor's global CSS cache and slowing the next editor load.
- Mixed documents allow surgical V3 batch writes under an explicit V3 parent
  while ambiguous root adds and high-level full-document writes stay blocked.
- Elementor schema failures identify the first rejected setting path, expected
  shape, and received type without echoing user content.
- The V4 atomic-abilities checkbox now submits an explicit disabled value when
  unchecked; enable and disable persistence is covered bidirectionally.

## [1.0.0-alpha.82] - 2026-07-23

### Fixed

- REST mutation audit stores hashes and byte counts instead of free-form code,
  instruction, skill, and memory bodies.
- User-scoped Direct learning is stored globally across configured sites, and
  refreshed corrections move to the newest memory position.
- Elementor V3 production mutations enforce authorized breakpoint scope and
  roll back when readback detects non-target breakpoint drift.
- Compact task-start retains target binding fields while staying inside the
  enforced non-visual and visual token budgets.
