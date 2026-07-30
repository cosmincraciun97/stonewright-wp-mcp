# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file keeps a **5-release retention policy** for versioned sections (plus
Unreleased); older history lives in git tags and GitHub releases.

## [Unreleased]

## [1.0.0-alpha.92] - 2026-07-30

### Added

- A shared native-rule registry for Plugin and Direct mode, with explicit
  severity and runtime-enforcement classifications, digest caching, and the
  read-only `rules-get` surface.
- Bounded, cursor-based memory generalization with dry-run preview and guarded
  apply mode.
- Response field projection through `stonewright_fields`, plus `knownHash`
  short-circuit reads for Elementor page structures.
- Conservative, opt-in escaped-layout decoding for supported code payloads.

### Changed

- Native batching guidance is sourced from the shared rule registry.
- Direct mode validates and canonicalizes every packaged native-rule record
  before exposing it, matching the plugin loader's fail-closed behavior.
- The WordPress admin now ships one supported light theme.

### Fixed

- OAuth audit classification preserves protocol failures without storing
  request or response credentials echoed inside diagnostic fields.
- Memory generalization now returns an explicit partial-failure error when any
  planned database update fails, including the failed ids and continuation
  state.
- Elementor responsive control detection covers suffixed controls and
  standalone visibility switchers.
- Elementor structure hashes ignore associative JSON key order while preserving
  semantic element-list order.
- Projected integer-keyed maps retain their keys, projected list members retain
  source order, and the light-only browser matrix matches the shipped admin.

### Removed

- The obsolete admin theme toggle, stored dark preference, and duplicate dark
  token set.

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
