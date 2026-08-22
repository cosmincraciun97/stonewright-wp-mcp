# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

## [1.0.0-beta.11] - 2026-08-22

### Added

- Add a renderer-aware motion train with capability discovery, strict
  DesignSpec motion semantics, seven bundled static-first presets, read-only
  suggestions and signed plans, plus guarded Gutenberg/FSE and Elementor V3
  apply abilities.
- Add optional motion and UI-excellence evidence to
  `stonewright/design-quality-check`, so accessibility, editor parity, and
  controlled performance findings participate in real and persisted verdicts.
- Add verified design-direction deactivation through the existing
  `stonewright/design-direction-activate` ability (`id: 0`), with a matching
  admin Active toggle that autosubmits and reports failures honestly.
- Add an explicit `stonewright/blocks-finalizer-cancel` ability that removes
  queued, serialized, or failed finalizer records by id with dry-run support,
  per-post permission checks, production-safe confirmation tokens, atomic
  single-save deletion, and verified readback.

- Add a Connect → Troubleshoot page that runs connection diagnostics in place
  with a loading state, result cards, a copyable support report, bot/WAF
  probes, OAuth registration checks, and a clipboard fallback.
- Add a discover-execute MCP profile with three protocol tools for catalog
  discovery and gated execution without exposing the full tool list.
- Default Gutenberg block parse, registered-block list, and theme.json reads to
  bounded summaries, with `responseMode=full` restoring the previous dumps.
- Add read-only GenerateBlocks, Kadence Blocks, Spectra, and Blocksy library
  introspection, plus Blocksy/Kadence/GeneratePress theme chrome get/update.
- Add presence-gated Blocksy, Kadence, GenerateBlocks, and Spectra build-page
  skills that compose through the Gutenberg finalizer, plus builder operating
  manuals and ACF, SEO, and forms skills.
- Add typed query-loop, FSE navigation, and synced-pattern update/delete/category
  abilities, plus a child-theme theme.json handoff that stops at human approval.
- Add a read-only Elementor performance audit ability reporting bounded document,
  settings, backup, and revision size metrics without exposing content.
- Add a browser-assisted Gutenberg block finalizer that serializes queued block
  specs in the live editor iframe, persists path-aware hashed HTML through
  existing backup, confirmation, and audit gates, and shows a Block Editor Queue
  with heartbeat status. Partial block schemas stay marked as live-editor
  validated.
- Add Elementor list-widgets summary mode and optional full Design Direction
  document loading from `stonewright/design-direction-brief`.
- Add reviewable draft lessons when the same recurring error reaches ten repeats.
- Add a Delete all logs control on the Audit Log page with inline DELETE confirmation, and a Block Editor Queue status strip with last-poll time and per-item status.

### Security

- Sign compiled motion plans with a site-bound HMAC and reject operation,
  preset, asset, renderer, capability-digest, or design-direction drift before
  dry-run and apply. Bind Elementor V3 motion evidence to the intended
  capability and exact live schema/runtime/source identity.
- Block Elementor `custom_css` / `_custom_css` writes and HTML widget `<style>` tags unless a consumed `custom_code_grant` accompanies the call, and allow `_css_classes` only from the `approved_css_classes` allowlist.
- Block php-execute from calling `wp_update_custom_css_post` or writing kit/page custom CSS; those writes must use `stonewright/theme-custom-css`.
- Enforce php-execute read-only at runtime, block concatenated protected meta
  keys and direct database writes to core tables, and keep php-execute off the
  default compact MCP surfaces.
- Sign one-time admin login links, bind them to IP and User-Agent, rate-limit
  issuance, skip remember-me cookies, and require confirmation in production-safe
  mode.
- Restrict REST ability execution to the active tool profile unless confirmed,
  and require a confirmation token to toggle abilities over REST.
- Require production-safe confirmation tokens on remaining content, Elementor,
  Gutenberg, site, and admin-domain writers.
- Require a human-issued `custom_code_grant` (and `allow_raw_html` for Gutenberg HTML) before raw `<style>` payloads, theme.json `css`, and all-raw-HTML block trees; do not strip the CSS.
- Isolate the Gutenberg finalizer queue per owner, session, and target post:
  state v2 records carry owner/session/post, tokens are HMAC-bound to all four
  values, REST/AJAX/MCP results are scoped, replay after terminal states is
  refused, legacy unbound records fail closed, and mutations run under a
  bounded mutex. Hard limits now bound batch size, open records, payload
  bytes, tree depth/nodes, serialized HTML, and total queue state, with
  retention pruning for terminal records.

### Fixed

- Keep every Stonewright admin navigation link visible and contained at 320px
  and 390px by allowing multi-link header groups to wrap inside the mobile
  shell.
- Register the conditional motion asset loader during plugin boot, distinguish
  load from viewport entrances, make stagger configuration executable, restore
  link-underline rendering, make played states outrank their initial hidden
  states, and make the runtime fail open with page-cache and live
  reduced-motion reinitialization.
- Report Elementor V4 interaction triggers, breakpoint support, schema
  fingerprint, and official write-primitives readiness from the loaded runtime;
  refuse native V4 motion plans while the required typed mutation stack is
  unavailable.
- Fix the security-audit assert-call detector to use token-based PHP parsing:
  method calls such as grant-gate assertions no longer produce false
  positives, while functional `assert()` calls on strings or variables stay
  blocked.
- Return `schema_requests` when Elementor `require_evidence` writes are incomplete, copy those requests (and custom-CSS grant keys) into the MCP-visible error message so agents are not stuck with a one-line rejection, accept active-direction provenance for token-derived settings, honor batch or per-operation `responsive_scope` so tablet and mobile keys can be written without weakening the desktop-only default, and evaluate merge-patch control conditions against live settings plus the patch so apply-time write-delta validation does not drop unchanged typography activators.
- Make design-direction unknown-field errors list accepted keys and a minimal `1.0` example, encode empty capture token maps as objects, compute ready from an empty issues list, and keep those validation rejections out of the incident queue.
- Keep architecture-preflight reasons honest for inspected empty documents, and list accepted evidence keys when native-plan or quality-check payloads fail.

- Treat 127.0.0.1 and ::1 as the same bind for one-time admin login links so a
  local MCP client and a local browser can redeem the same token.
- Bind one-time admin login links to an explicit browser `user_agent` when
  issued for Playwright or other external automation, instead of the MCP
  client UA that those browsers cannot match.
- Keep Gutenberg static block queue items queued when the browser serializer
  cannot produce HTML, instead of auto-failing them with an empty roundtrip.
- Expose finalizer queue errors, failed counts, per-target editor/queue URLs,
  and heartbeat-only online status so agents can drain the queue without
  inspecting private storage.
- Persist nested innerBlocks on dynamic Gutenberg inserts, or queue the whole
  tree when any child needs the browser finalizer.
- Emit query-loop specs with an `attributes` object (`{}` when empty, never a
  JSON array) that inserts can consume directly, keep attribute-validation
  errors structured, and preserve supplied heading, button, and group
  attributes when serializing.

- Stop Run diagnostics from refreshing the whole admin page when JavaScript is
  available, and keep the no-JS form as a fallback.
- Detect the skills table through wrapped `$wpdb` handles so packaged
  landing-page playbooks actually retire on seed.
- Keep local-site Connect tabs actionable: ChatGPT and Claude.ai now show the
  Desktop/mcp-remote bridge instead of an empty notice.
- Hide inactive OAuth client panels so tab switches actually change the visible
  config.
- Allow `cursor://` one-click install links through WordPress URL sanitization
  and add a copy-link fallback.
- Stop Setup diagnostics from claiming a companion contract mismatch when plugin
  SemVer and the HTTP contract share major 1.
- Reseed packaged skills when the plugin version changes so file copies without
  reactivation still refresh the catalog.
- Treat an Elementor elements manager that cannot list types as offline instead
  of failing closed on a stub or half-booted runtime.
- Store backup snapshots and restores through a slash-safe post-meta boundary so
  Elementor documents containing escaped JSON round-trip byte-faithfully; the
  snapshot integrity hash check now passes for legitimate writes and still fails
  closed on genuine storage corruption.
- Separate Codex Desktop from Codex CLI in Connect and dedupe generated MCP
  server names.
- Hide Elementor V4 tools when the atomic toggle is off, persist the MCP surface
  from Apply now, and report when a session has widened beyond compact.
- Surface audit-row error code, message, target, and repair, and make occurrence
  links filter correctly.
- Harden memory save failures, checkbox uncheck, matching window, and
  `body_tool` routing.
- Split paste-to-agent `--profile` from `--wp-surface` so compact versus full
  surfaces stay explicit.
- Normalize sparse container settings on the standalone Elementor add-container
  path.
- Restore editor iframe state after finalizer serialize, reject mixed finalizer
  batches, and honor block path, position, and update semantics on persist.
- Keep required `auth_guidance` on compact task-start so MCP schema validation
  and the Troubleshoot loopback probe succeed when there is no extra auth copy.
- List WordPress core pattern categories such as featured even before a user
  pattern is assigned to them.
- Treat `audit_write` as the write audit gate in the public API contract, and
  look up draft-lesson memory rows with a SQL string plus scalar binds.
- Stop the Block Editor Queue iframe from autosaving queued blocks into the live
  post before `blocks-finalize-batch`.

### Changed

- Document Gutenberg output-quality rules: one H1 (section titles as H2 when
  the theme already prints the page title), styled query loops (grid, cropped
  featured images, card supports), and one finalizer change per post.
- Polish the Audit Log: key-value details with a View JSON toggle, distinct incident badges, wider ability names, a More filters disclosure, and a clearer empty state.
- Style Application Password client pickers with the same button tabs as OAuth.
- Keep compact `stonewright-task-start` under the anti-slop byte budget with
  truncated Context, `design_direction_ref`, and the visual quality floor, and
  expose `stonewright/design-direction-brief` on the essential MCP surface.
- Rebuild the Context admin page into side-by-side system and user sections,
  with collapsible generated instructions and an on/off inject toggle.
- Route ambiguous design, landing-page, and section tasks to Gutenberg when
  Elementor is inactive or a block theme such as Blocksy is the stronger signal.
- Discover skills as slug plus one-line description; load playbook bodies only
  through `stonewright/skills-get`, presence-gate skill bodies as well as the
  catalog, and hide skills whose required integrations are absent.
- Harden pattern creates and FSE global-style/template writes with the same
  backup, confirmation, sanitization, and audit envelope as the canonical
  write abilities. Keep the older update-* names as compatibility wrappers.
- Require an explicit, SemVer-compatible release channel and expose the
  supported public beta through one validated README download path.
- Clear the admin header of mode and version, mark Troubleshoot, Context,
  Design, and Block Editor Queue as Experimental, and move Memory, Skills, and
  Context under Workflows.
- Expose the generated **387**-ability Plugin and **101**-tool Direct contracts.
- Mark Troubleshoot, Context, Design, and Block Editor Queue with a compact
  white EXP marker that stays on the first line while long labels wrap. Hover
  shows that the feature is experimental.
- Restyle the Block Editor Queue as a status card with idle and busy states.

### Removed

- Remove packaged industry landing-page playbooks from the skills catalog.
  Already-seeded playbook rows are retired on seed and on plugin upgrade.

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

## Older releases

- [1.0.0-beta.6](docs/releases/1.0.0-beta.6.md)
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
