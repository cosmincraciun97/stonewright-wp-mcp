# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

### Added

- Add typed query-loop, FSE navigation, and synced-pattern update/delete/category
  abilities, plus a child-theme theme.json handoff that stops at human approval.
- Add a read-only Elementor performance audit ability reporting bounded document,
  settings, backup, and revision size metrics without exposing content.
- Add a browser-assisted Gutenberg block finalizer so static and third-party
  blocks serialize in the live editor, then persist through Stonewright's
  existing backup, confirmation, and audit gates.

### Security

- Enforce php-execute read-only at runtime, block concatenated protected meta
  keys and direct database writes to core tables, and keep php-execute off the
  default compact MCP surfaces.
- Sign one-time admin login links, bind them to IP and User-Agent, rate-limit
  issuance, skip remember-me cookies, and require confirmation in production-safe
  mode.
- Restrict REST ability execution to the active tool profile unless confirmed,
  and require a confirmation token to toggle abilities over REST.

### Fixed

- Store backup snapshots and restores through a slash-safe post-meta boundary so
  Elementor documents containing escaped JSON round-trip byte-faithfully; the
  snapshot integrity hash check now passes for legitimate writes and still fails
  closed on genuine storage corruption.

### Changed

- Harden pattern creates and FSE global-style/template writes with the same
  backup, confirmation, sanitization, and audit envelope as the canonical
  write abilities. Keep the older update-* names as compatibility wrappers.
- Require an explicit, SemVer-compatible release channel and expose the
  supported public beta through one validated README download path.

## [1.0.0-beta.10] - 2026-08-12

### Added

- Add a typed verified-repair recorder, canonical incident lifecycle metadata,
  ranked task-start repair actions, stale-on-recurrence learning, and an
  isolated private Direct incident store.
- Add evidence-backed Elementor, custom-code, and repeated-failure use cases
  plus a generic API-bridge comparison and verified-learning guide.

### Changed

- Lead onboarding with the inspect-to-restore proof chain and reduce the
  default Plugin connection to four steps; advanced transports, profiles,
  Direct mode, and browser choices remain progressively disclosed.
- Expose the generated 361-ability Plugin and 101-tool Direct contracts.
- Replace the root license summary with the canonical GNU AGPL v3 text, add a
  component license map, and verify Plugin, Visual, and Companion metadata in
  CI and release gates.
- Select native Plugin and companion updates from the installed SemVer channel,
  with isolated caches and exact trusted assets; future prerelease tags are
  published as prereleases while stable tags alone become GitHub's latest
  release.

## [1.0.0-beta.9] - 2026-08-12

### Fixed

- Derive companion refresh diagnostics from the exact profile catalog resolved
  by the connected plugin, eliminating false stale-surface failures when the
  local fallback catalog is newer than the live profile.
- Reject bare or cross-device Elementor responsive visibility values and accept
  only each primary device's native switcher value or the empty off state.

### Changed

- Ship responsive visibility as a native global Elementor rule: prefer builder
  controls over CSS/JavaScript, treat editor-canvas visibility as non-proof,
  and require settings readback plus frontend-class verification.

## [1.0.0-beta.8] - 2026-08-12

### Fixed

- Accept `playwright` as the natural browser-provider value while preserving
  the existing recommended-provider registry contract.
- Make `connect verify` print content-safe runtime proof for companion version,
  active alias, task-start/status availability, and refresh-required tools;
  verification now fails while any refresh-required tool remains.

## [1.0.0-beta.7] - 2026-08-12

### Fixed

- Keep `connect add` and `connect repair` receipts content-free: client config
  contents, unrelated local settings, absolute config paths, and backup paths
  are withheld while the receipt retains the server name, change state, backup
  state, support tier, and browser consent metadata.

## [1.0.0-beta.6] - 2026-08-12

### Changed

- Make the installed-plugin setup prompt choose one transport, require a unique
  alias and `plugin-only` policy for companion stdio, and reuse an existing
  credential through `connect repair --mode` instead of creating a generic or
  duplicate server entry.
- Add a synthetic, privacy-safe Dashboard overview to the main documentation.

### Fixed

- Keep domain-lock recovery forms outside the Settings API form so **Save
  Settings** returns to Stonewright Setup instead of leaving the user on
  `/wp-admin/options.php`.
- Treat `STONEWRIGHT_SITE_ALIAS` as the startup routing authority, replacing
  stale inherited WordPress environment values before Plugin/Direct mode is
  selected and failing closed when its credential cannot be resolved.
- Collapse duplicate legacy aliases for the same canonical site/environment
  during secure v1 migration, and allow `connect repair --mode plugin-only` to
  update a named client entry without requesting the saved password again.

## Older releases

- [1.0.0-beta.5](docs/releases/1.0.0-beta.5.md)
- [1.0.0-beta.4](docs/releases/1.0.0-beta.4.md)
- [1.0.0-beta.3](docs/releases/1.0.0-beta.3.md)
- [1.0.0-beta.2](docs/releases/1.0.0-beta.2.md)
- [1.0.0-beta.1](docs/releases/1.0.0-beta.1.md)

### 1.0.0-beta.3 — 2026-07-31

### Added

- Add `stonewright/elementor-post-write-verify`, a bounded post-write closure
  ability that regenerates one post's CSS, warms Elementor's public frontend
  renderer, asserts requested element IDs or hashed content markers, and keeps
  browser verification explicitly required.
- Return maximal V3-only safe roots for mixed Elementor V3/V4 documents and
  publish a complete schema-evidence, cache, readback, measurement, and
  screenshot verification guide.

### Changed

- Define local stdio consistently in Setup and public docs: the AI client starts
  the companion locally; Direct mode and local WP-CLI require it, while Remote
  Streamable HTTP connects directly to the plugin.
- Require a human-issued, exact-candidate one-time grant for Customizer CSS as
  well as theme-file code writes. Dry-runs now return the approval URL, path,
  byte counts, summary, and an explicit stop signal.
- Block custom-code writes in pluginless Direct mode, which has no authenticated
  wp-admin approval boundary.
- Tell agents never to open or submit the code-approval page unless the user
  explicitly asks them to perform that approval step.
- Serialize Elementor writes per post, make the native write-closure rule
  immutable in Plugin and Direct modes, and require one reviewed dry-run/apply
  batch followed by frontend and browser verification.
- Keep Direct mode first-class: local Elementor writes invalidate target-post
  element/CSS metadata and report browser verification as required; remote
  Direct reports unavailable PHP cache/render checks as `not_checked`.

### Fixed

- Invalidate Elementor's official document cache, post-scoped CSS state,
  WordPress post cache, and atomic styles only after verified readback, and
  repeat invalidation after a successful snapshot restore.
- Remove the site-wide CSS-clear fallback from single-document writes.
- Accept documented batch-operation aliases while returning exact repair
  guidance instead of encouraging guessed Elementor controls.
- Clarify escaped-layout PHP parsing and refuse ambiguous heredoc, nowdoc,
  script, style, and interpolation candidates instead of corrupting snippets.

### 1.0.0-beta.2 — 2026-07-30

### Added

- A maintained `DESIGN.md` system and page-by-page admin surface checklist for
  the supported light interface.

### Changed

- The Dashboard uses one compact summary band and balanced evidence panels
  instead of a wall of oversized metric cards.
- Setup, Sandbox, Audit, Abilities, Design Studio, Visual Workspace, Blueprints,
  Memory, and Skills now share tighter typography, spacing, focus, status,
  border, and contrast contracts.

### Fixed

- Make an explicit **Check latest companion** action bypass the 12-hour release
  cache and browser caches, while retaining the cache for automatic background
  checks.
- Preserve every top-level field required by an ability output schema when
  `stonewright_fields` projects a smaller response. Compact `task-start`
  responses can no longer lose required handshake fields.
- Center the complete Domain Lock control group, keep Sandbox file-type badges
  readable, remove inline click handling from category actions, and correct
  low-contrast setup code blocks and focus rings.

### 1.0.0-beta.1 — 2026-07-30

### Added

- Native WooCommerce catalog abilities for status, product and variation
  reads/writes, catalog terms, global attributes, safe deletes, and bounded
  catalog audits. Plugin mode now exposes 346 abilities; Direct mode remains
  read-only for WooCommerce.
- Runtime discovery for common builders, themes, block libraries, forms, field
  plugins, add-ons, dynamic-data plugins, code tools, and SEO suites. Detected
  integrations without typed adapters are reported as discovery-only.
- Public-repository hygiene checks for private project identifiers in source,
  staged release archives, and optionally commit history.
- A credential-free paste-to-agent prompt, Plugin/Direct badges in the Prompt
  Library, and an in-product update guide for the plugin and companion.
- A release-aware companion check in Setup with trusted package/checksum links
  and a credential-free update prompt for the AI client.
- Persistent-data lifecycle contracts proving that fresh installs start with
  no user memory, user-created skills, or audit events.

### Changed

- WordPress release archives now come from a clean production Composer install.
  The Jetpack Autoloader is a production dependency, its package loader is the
  primary bootstrap, and every manifest path is verified before publication.
- WooCommerce catalog writes use native WooCommerce objects and allowlisted
  setters, preview by default, enforce permissions and production
  confirmations, record audit entries, and verify readback.
- Storefront guidance routes Elementor through live Woo widget schemas and
  Gutenberg/FSE through registered `woocommerce/*` block schemas.
- Direct mode remains first-class: it accepts the canonical `appPassword`
  sites key plus the legacy key, writes private state with restrictive file
  permissions, prints a secret-free client configuration, starts with
  `stonewright-task-start`, and no longer labels local memory as plugin-only.
- Setup diagnostics never return a supplied password or authorization value;
  copyable environment blocks use private placeholders.
- Plugin and Direct memory/skill writes reject high-confidence credential
  material. Direct audit errors redact authorization headers, tokens, and
  Application Passwords before persistence.
- Updates preserve existing plugin memory, user skills, audit history, admin
  settings, and Direct state under `~/.stonewright/`.
- The native rule registry, bounded memory generalization, response field
  projection, Elementor `knownHash` reads, and conservative escaped-layout
  decoding are part of the first supported beta baseline.
- Eight site-independent operating rules now cover Elementor transaction
  discipline, responsive semantic widgets, verified content models, source
  asset integrity, non-reentrant queries, temporary-code lifecycle, dynamic
  architecture preservation, and rendered proof in both Plugin and Direct
  modes.
- Audit incidents use a single responsive page with readable causes, contained
  payloads, and copy controls. Legacy Sandbox audit links point to that page.

### Fixed

- Prevent activation fatals when WooCommerce and Stonewright share Jetpack
  Autoloader by keeping root dev autoload metadata out of Jetpack's optimized
  classmap and eliminating development-only paths from release archives.
- Release checks now test WooCommerce co-activation and reject archives with
  missing Composer-manifest targets, including after release-only exclusions
  are applied.
- The WooCommerce compatibility gate now installs the extracted release archive
  rather than the source tree.
- Preserve every packaged generic skill during fresh-install seeding without
  weakening the credential guard for user-created skills.
- Keep Sandbox primary-action labels readable and expose a working copy action
  for one-time custom-code approval tokens.
- Publish the supported public beta as GitHub's latest release so repository
  release links and the native update checker can discover it.
