# Changelog

## [Unreleased]

## [1.0.0-beta.1] - 2026-07-30

### Added

- Seventeen native WooCommerce abilities covering runtime status, products,
  variations, catalog terms, global attributes, orders, sales, and bounded
  catalog audits.
- Explicit runtime integration discovery for supported and discovery-only
  builders, themes, blocks, forms, field plugins, add-ons, dynamic-data
  plugins, code tools, and SEO suites.

### Changed

- WooCommerce catalog mutations now preview by default, use allowlisted native
  object APIs, enforce task context and permissions, require production
  confirmations where applicable, record audit entries, and verify readback.
- Production packages bootstrap through Jetpack's package-aware Composer
  loader and verify every generated manifest path.

### Fixed

- Clean production dependency builds remove stale Composer development
  manifests that could make activation fail beside WooCommerce.

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
