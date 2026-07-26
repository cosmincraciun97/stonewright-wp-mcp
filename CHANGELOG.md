# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

## [1.0.0-alpha.91] - 2026-07-26

### Added

- `stonewright/design-direction-brief`, a compact read-only ability that turns
  the active direction's variance, density, and motion dials into reusable
  Elementor guidance without returning the full stored contract for every
  section.
- Guided onboarding, contextual keyboard-accessible tooltips, and explicit
  readiness controls in Design Studio.

### Changed

- The Figma-to-Elementor skills now use one shallow document read, one
  token/style read, then per-section normalized DesignEvidence. They reuse the
  compact direction brief, enforce measured responsive targets, and avoid
  repetitive generic compositions and undeclared visual defaults.
- Visual Workspace now opens the real Elementor or block editor in a
  same-origin companion window and resolves adapters against that live runtime.

### Fixed

- Style the Visual Workspace post picker and editor connection controls with
  the Stonewright admin design system.
- Mount the workspace immediately in a truthful disconnected state, then replace
  that controller without races when the real editor runtime attaches.

## [1.0.0-alpha.90] - 2026-07-26

### Fixed

- ChatGPT can scan and refresh every MCP action without rejecting empty JSON
  Schema fragments encoded as arrays.

## [1.0.0-alpha.89] - 2026-07-26

### Fixed

- ChatGPT and other OAuth clients can now complete administrator consent instead
  of stopping on a generic authorization error.

## [1.0.0-alpha.88] - 2026-07-25

### Added

- A Visual Workspace page in wp-admin. Stonewright Visual could previously be
  driven by an AI client and by nothing else; now a person can open a post in it
  and watch the same steps. It names the editor it found, refuses to guess when
  an editor is present but cannot be driven, and walks read, preview, explicit
  confirmation, apply, and verify in that order. The confirmation panel states
  the target, the breakpoint, and the before and after text for every change,
  with the active design direction named beside it. Applying without evidence
  behind it is reported as unverified rather than as a success. The inspector
  collapses into a keyboard-operable drawer on narrow screens, and there are no
  browser confirm dialogs anywhere in the flow.
- A Design Studio page in wp-admin. It shows which direction is active, whether
  it is ready and whether it matches the Elementor kit, and where each token
  came from. Directions are edited in a structured form with a live specimen
  beside it, and unsaved work survives a reload. Saving, activating, restoring a
  revision, and pushing to Elementor each open a review drawer that lists what is
  about to change before anything runs. Quality reports are read on the same
  page, filtered by severity, viewport, or rule, and evidence that was never
  captured stays visible as unverified instead of being rounded up to a pass.
- A rebuilt Skills page in wp-admin with four views: catalog, editor, import,
  and trash. Every skill states where it came from — shipped with Stonewright,
  created on this site, or registered by another plugin — along with its state,
  revision, and how many times it has been verified. Search filters the list in
  place, and inspecting a skill opens a drawer with its body, lint findings,
  trust findings, and history.
- Skill import and export. Export produces normalized Markdown carrying
  provenance and a content hash. Import is two steps: the file is inspected
  first and the review lists lint errors and trust findings before anything is
  stored, the confirmation is bound to the content hash so the file cannot
  change between review and persistence, and an imported skill lands disabled as
  a draft. An import never overwrites an existing skill.
- Trash and restore for skills. Trashing disables a skill everywhere an agent
  could read it and offers an undo; trashed skills never match
  `stonewright-task-start`. Restore returns the skill as a disabled draft.
  Built-in skills can be disabled but not removed. Permanent deletion is a
  separate, irreversible action that lists exactly what will be destroyed and,
  in production-safe mode, needs a confirmation token.
- Skills published by other plugins. A plugin can register a read-only skill
  source; Stonewright never executes source code and never fetches URLs.
  Built-in ids are reserved and external ids must be source-qualified, so a
  source cannot silently shadow a built-in or a local skill — an attempt is
  reported as a visible conflict in the catalog instead.
- A `visual-direction` skill pack for work that decides how a site should look:
  direction capture, reviewed kit sync, the checkpoint after the first section,
  and rendered evidence before a build is called done. It is loaded for a
  rebrand or a new palette, type scale, or spacing rhythm, and not for work that
  stays inside the direction already in place.

### Fixed

- Design Studio history now explicitly requests revision versions, so saved
  revisions appear and can be reviewed or restored.

## [1.0.0-alpha.87] - 2026-07-25

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

- Evidence-based visual verification: `stonewright/design-quality-check` turns
  measured browser evidence for a rendered page into coverage, findings, and
  repair hints. There is no invented score. Every finding carries the numbers
  that produced it, evidence that was never captured is reported as
  `not_checked` and counted separately, and a report with nothing checked can
  never come back clean.
- A first-section checkpoint for builds that establish a new visual direction.
  The build stops once, after the first section, so the user can approve the
  rendered result before the rest of the page is written. Maintenance work is
  never interrupted: only `new_identity`, `replacement`, and `rebrand` are
  gated, while `preserve`, `repair`, `content_only`, and `responsive_fix` pass
  straight through.
- Ability `stonewright/design-checkpoint-record`, which records that approval
  and returns the token later section writes are checked against. It refuses
  any approval it cannot tie to live state, and the token binds the post, the
  section, the direction, and the render that was approved — so editing the
  approved section or switching direction stops it working.
