# Changelog

## [Unreleased]

## [1.0.0-alpha.92] - 2026-07-30

### Added

- Shared native rule registry, shipped as data in `plugin/data/global-rules.json`
  and served by the read-only ability `stonewright/rules-get`. Each rule states
  what it is, why it exists, its severity (`hard` / `strong` / `advisory`), its
  scope, and whether a runtime guard blocks violations or the rule is
  instruction-only. Rules apply to every site instead of living in one site's
  memory, so they survive a memory reset. Task start carries only the registry
  digest; clients cache by digest and refetch bodies only when it changes.
- Runtime enforcement for the `hard` rules, so a violation fails instead of
  merely contradicting guidance. Rules that PHP cannot mechanically check are
  classified `strong` and never claim a runtime guard.
- Ability `stonewright/memory-generalize`, which reports — and on request applies
  — de-identification of stored memory in bounded batches. It defaults to a dry
  run, returns `next_cursor` rather than claiming one page covered everything,
  and in `production-safe` mode requires a confirmation token issued for the same
  `apply`, `limit`, and `cursor` values.
- Optional response field projection on every ability. Pass
  `stonewright_fields` — a list, or a comma-separated string, of dot-separated
  response paths — to receive only those branches. Unknown paths are ignored, the
  `ok` envelope is never removed, and errors are returned unprojected. The
  parameter is namespaced because `fields` is already a required input of
  `stonewright/content-model-loop-grid-flow`.
- Optional `knownHash` input on `stonewright/elementor-v3-get-page-structure`.
  When the hash a previous read returned still matches, the response is
  `{ post_id, active, hash, unchanged: true }` and neither the outline nor the
  tree is built. Both response modes report the same document hash.

### Changed

- Batching guidance in task start is now sourced from the rule registry instead
  of restated in PHP. `fast_path.batching_rule_id` names the rule in both
  response modes; compact mode carries that id alone and the bodies come from
  full mode or `stonewright/rules-get`.
- Inbound code payload formatting is opt-in and conservative. Abilities that
  accept code (`stonewright/php-execute`, `stonewright/sandbox-write`,
  `stonewright/theme-custom-css`, `stonewright/theme-file-patch`) accept
  `decode_escaped_layout`, default `false`, which decodes escaped layout only
  outside PHP strings and comments. Without the flag, payloads are passed through
  unchanged.
- PHP payload validation uses the PHP parser rather than a regex heuristic, so
  valid code is no longer rejected for looking unusual.
- Confirmation tokens for sandbox writes bind to canonical arguments, so a
  reformatted payload cannot reuse a token issued for different content.

### Removed

- Admin dark mode. The admin UI ships a single light theme; the toggle, its
  stored preference, and the duplicate dark token set are gone.

### Fixed

- Audit log entries now distinguish an OAuth protocol failure from a server
  error. Protocol codes defined by RFC 6749 / RFC 8628 keep their exact spelling
  when the failure originates in the auth surface; Stonewright's own error codes
  are namespaced so the two can no longer be confused.
- Memory generalization reports partial database failures explicitly instead of
  returning an apparently successful apply result.
- Elementor responsive control detection recognises responsive suffixes and
  standalone visibility switchers, so per-breakpoint edits target the control the
  caller meant.
- Unquoted HTML attribute escapes survive code canonicalization.
- One tooltip owner across admin pages, so Design Studio no longer shows two
  tooltips for the same control.
- The primary "Open workspace" button stays readable on hover.

## [1.0.0-alpha.91] - 2026-07-26

### Added

- Read-only ability `stonewright/design-direction-brief` and
  `Design\Direction\DialTranslator`, returning compact active direction tokens
  plus deterministic Elementor layout, density, and motion guidance.
- Design Studio onboarding, contextual accessible tooltips, and editable
  `ready` / `sync_ready` state with unresolved issue tracking.

### Changed

- Visual Workspace connects to the actual same-origin Elementor/Gutenberg
  editor window before resolving an adapter.

### Fixed

- Visual Workspace picker and connection buttons now use complete Stonewright
  button states instead of unstyled browser controls.
- Visual Workspace now paints before an editor is attached and safely replaces
  its disconnected controller when a live editor runtime becomes available.

## [1.0.0-alpha.90] - 2026-07-26

### Fixed

- Normalize every nested MCP input and output schema fragment as a JSON object
  or boolean, so strict clients such as ChatGPT can discover all abilities.

## [1.0.0-alpha.89] - 2026-07-26

### Fixed

- OAuth consent now autoloads its user entity through the normal PSR-4 path, so
  approved authorization-code requests reach the client callback.

## [1.0.0-alpha.88] - 2026-07-25

### Added

- Admin page `stonewright-visual-workspace` (`Admin\Pages\VisualWorkspacePage`),
  gated on `edit_posts` and, for a targeted post, on
  `Permissions::can_edit_post()`. PHP renders the chrome and hands the browser
  bundle three slots: adapter chip, canvas body, inspector body. The boot payload
  carries only the REST base, a `wp_rest` nonce, the post id, the requested
  editor, and the active direction row (identity, revision, contract hash) — the
  contract itself stays on the server. A missing bundle is stated on the page
  with the command that builds it. No abilities were added, changed, or removed.
- Visual Workspace front end (`assets/admin/visual-workspace.js`,
  `assets/admin/visual-workspace.css`) plus the `visual/` workspace UI it boots:
  adapter detection that stops on an undrivable editor instead of falling
  through, a controller-enforced read → preview → confirm → apply → verify
  ladder with a single private write dispatch, a confirmation panel carrying both
  the human diff and the exact adapter arguments, and an evidence panel that
  keeps failed and unchecked rules visible as unverified.
- `plugin/assets/visual/` is staged at packaging time and is not committed.
  `scripts/package-verify.mjs` warns on a missing bundle in a source checkout and
  fails under `--require-visual-bundle`; CI and the release workflow pass that
  flag after staging, and the release job asserts the built zip contains the
  bundle.
- Admin page `stonewright-design-studio` with four views (overview, editor,
  quality, history) and REST routes under `stonewright/v1/design-studio`. Every
  route delegates to the typed design-direction and quality abilities, so
  permission checks, task context tokens, confirmation tokens, backups,
  validation, audit records, and effect readback are inherited rather than
  reimplemented. Reads require `can_read_design()`; writes require
  `can_manage_design()` and a `wp_rest` nonce.
- Design Studio front end (`assets/admin/design-studio.js`,
  `assets/admin/design-studio.css`): no framework, no jQuery, no native browser
  dialogs, and no markup built from stored content — every value reaches the DOM
  through `textContent`. Keyboard-operable tablist with URL-restored views,
  session-scoped draft recovery, a focus-trapping review drawer that returns
  focus on close, a polite live region, token-only theming for light and dark,
  and a reduced-motion path.
- `SkillSource` and `SkillSourceRegistry`: a read-only registry for skills
  published by other plugins through the `stonewright_skill_sources` filter.
  Resolution order is built-in, then this site's database, then registered
  sources. Built-in ids are reserved and external ids must be source-qualified;
  a source that tries to claim a taken id is surfaced as a conflict rather than
  applied. The registry never executes source callables for their side effects
  and never performs HTTP requests.
- `SkillImportSanitizer`, `SkillImporter`, and `SkillExporter`. Import is split
  into inspect and confirm: inspection enforces a 1 MiB ceiling, valid UTF-8,
  and required `name`/`description` front matter, then returns lint and trust
  findings plus a SHA-256 content hash that the confirm step must echo back.
  Imported skills are stored as `uploaded`, disabled, `draft`, with server-side
  values only — nothing the file claims about its own source, status, or enabled
  flags is honoured. An import never overwrites an existing slug. Export emits
  normalized Markdown with provenance front matter and the content hash.
- Skill trash lifecycle: `SkillsTable` schema `1.3` adds `trashed_at`, and
  `Skills::trash()`, `Skills::restore()`, and `Skills::destroy()` implement it.
  Trashed rows are excluded from every agent-facing read, so they cannot match
  context bootstrap. Restore returns the row as a disabled draft.
  `Skills::destroy()` refuses built-ins and, in production-safe mode, verifies a
  confirmation token bound to the skill id. `Skills::delete()` remains as a bool
  wrapper for existing callers.
- Admin page `stonewright-skills` rebuilt on the shared admin shell, with REST
  routes under `stonewright/v1/skills-studio`: `/catalog`, `/import/inspect`,
  `/import`, and `/skills/(?P<id>\d+)` plus its `/export`, `/trash`, and
  `/restore` sub-routes. Every route requires `Permissions::manage_options()`;
  anything that is not a plain read additionally requires a `wp_rest` nonce and
  records an audit entry.
- Skills front end (`assets/admin/skills.js`): keyboard-operable tablist,
  in-place search, a focus-trapping review drawer that returns focus on close,
  an undo affordance after trashing, and no native browser dialogs. Titles,
  descriptions, and imported Markdown reach the DOM through `textContent`.
  The editor remains a nonce-checked form that works without JavaScript.
- `SkillsSeeder` now forwards `topic` and `version_constraints` from a skill
  pack's front matter, the latter written as a single-line JSON object. Only
  declared keys are forwarded, so a reseed of a pack that declares neither
  leaves whatever the site recorded for that slug untouched.
- Skill pack `skills/visual-direction/` with three references
  (direction contract, composition checklist, rendered quality loop), declaring
  `topic: elementor-visual-direction` and `{"elementor": ">=3.16"}`.
  `skills/elementor-v3-builder/` cross-references it instead of duplicating its
  rules and keeps sole ownership of Elementor writes.
- `AdminShell` theme toggle now uses inline SVG icons instead of text
  dingbats, so the control renders identically across platform fonts.
