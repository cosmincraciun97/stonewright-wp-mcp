# Changelog

## [Unreleased]

## [1.0.0-beta.11] - 2026-08-22

### Added

- Add motion capability discovery, a strict DesignSpec motion contract, seven
  bundled static-first presets, deterministic suggestions and signed plans,
  guarded Gutenberg/FSE and Elementor V3 apply, and motion/UI evidence in the
  persisted design-quality verdict.
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
- Add Codex in ChatGPT Desktop, Codex CLI, ChatGPT, Claude.ai, and Antigravity
  CLI to the shared OAuth and Application Password client choosers.
- Add read-only GenerateBlocks, Kadence Blocks, Spectra, and Blocksy library
  introspection, plus Blocksy/Kadence/GeneratePress theme chrome get/update.
- Add presence-gated Blocksy, Kadence, GenerateBlocks, and Spectra build-page
  skills that compose through the Gutenberg finalizer, plus builder operating
  manuals and ACF, SEO, and forms skills.
- Add typed query-loop, FSE navigation, and synced-pattern update/delete/category
  abilities, plus a child-theme theme.json handoff that stops at human approval.
- Add a read-only Elementor performance audit ability reporting bounded document,
  settings, backup, and revision size metrics without exposing content.
- Add a browser-assisted Gutenberg block finalizer that serializes queued
  `{name, attributes, innerBlocks}` specs in the live editor iframe, persists
  path-aware hashed HTML through a guarded finalize ability, and shows a Block
  Editor Queue with heartbeat status. Partial block schemas stay marked as
  live-editor validated.
- Add Elementor list-widgets summary mode and optional full Design Direction
  document loading from `stonewright/design-direction-brief`.
- Add reviewable draft lessons when the same recurring error reaches ten repeats.
- Add a Delete all logs control on the Audit Log page with inline DELETE confirmation, and a Block Editor Queue status strip with last-poll time and per-item status.

### Security

- Reject tampered or stale motion plans and require Elementor V3 motion
  evidence to match the planned capability plus the exact live schema,
  runtime fingerprint, and source identity.
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

### Fixed

- Boot the conditional motion loader, restore the link-underline effect, make
  load/stagger/duration/delay lowering executable, make played states visible,
  and fail open on runtime errors or page-cache restoration.
- Derive V4 interactions truth from the loaded schema and official mutation
  primitives, and return unsupported instead of compiling a write for a
  runtime without the complete native adapter stack.
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
- Encode Cursor MCP install deeplinks as base64url and keep them as clickable
  `cursor://` links on both OAuth and Application Password setup.
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

## [1.0.0-beta.10] - 2026-08-12

### Added

- Add `stonewright/incident-repair-record` for persisted, correlated repair
  receipts and one read-back verified reusable lesson.
- Add canonical incident repair, learning, reopen, and stale metadata plus
  ranked compact task-start actions.

### Changed

- Stop generic successful audit events from resolving incidents or promoting
  audit-derived learning. Explicit user corrections remain immediately
  recordable.
- Keep Plugin and companion update discovery on the installed stable or
  prerelease channel, using channel-specific caches and exact trusted release
  assets without cross-channel fallback.

## [1.0.0-beta.9] - 2026-08-12

### Added

- Add a site-independent Elementor responsive-visibility rule to the packaged
  native rule registry used by Plugin and Direct sessions.

### Fixed

- Reject bare `hidden` and cross-device values for the primary Elementor
  `hide_*` switches; accept only the matching native device value or an empty
  off state before any document write.

## [1.0.0-beta.8] - 2026-08-12

### Changed

- Align plugin metadata and generated companion package references with the
  final connection-verification release. Plugin abilities and safety gates are
  unchanged.

## [1.0.0-beta.7] - 2026-08-12

### Changed

- Align plugin metadata and generated companion package references with the
  privacy hardening release. No plugin ability, permission, backup,
  confirmation, validation, audit, or custom-code gate changed.

## Older releases

- [1.0.0-beta.6](../docs/releases/1.0.0-beta.6.md)
- [1.0.0-beta.5](../docs/releases/1.0.0-beta.5.md)
- [1.0.0-beta.4](../docs/releases/1.0.0-beta.4.md)
- [1.0.0-beta.3](../docs/releases/1.0.0-beta.3.md)
- [1.0.0-beta.2](../docs/releases/1.0.0-beta.2.md)
- [1.0.0-beta.1](../docs/releases/1.0.0-beta.1.md)

### 1.0.0-beta.3 — 2026-07-31

### Added

- Add `stonewright/elementor-post-write-verify` for post-scoped CSS
  regeneration, official frontend warming, bounded HTML assertions, and an
  explicit desktop/tablet/mobile browser recipe.
- Expose V3-safe subtree roots in mixed V3/V4 document health responses.

### Changed

- Clarify local stdio versus Remote Streamable HTTP throughout Setup and public
  installation guidance.
- Approval-gate Customizer CSS with the same native-gap dry-run, human-issued
  one-time grant, backup, exact-hash binding, readback, and audit contract used
  by theme-file code writes.
- Return an explicit human handoff (`approval_url`, target path, byte counts,
  summary, and `agent_must_stop`) and forbid agents from opening or submitting
  the approval page without an explicit user request.
- Enforce one per-post Elementor write lease across typed writers and require
  live schema evidence, consolidated mutation, post-write closure, and browser
  proof through the native rule registry.

### Fixed

- Invalidate the official Elementor document-cache key, post CSS, WordPress
  object cache, and atomic styles after verified writes and restored snapshots.
- Remove a global Elementor CSS-clear fallback from post-scoped regeneration.
- Return exact batch-mutation repair hints, guarded escaped-layout decoding,
  and stable receipts for the new post-write closure.

### 1.0.0-beta.2 — 2026-07-30

### Added

- A maintained light-mode design system and page-by-page admin release
  checklist.

### Changed

- Replace oversized Dashboard metric cards with a compact grouped overview.
- Align all wp-admin surfaces on the same typography, spacing, border, focus,
  badge, and status language.

### Fixed

- Force explicit companion release checks past stale WordPress and browser
  caches without disabling background caching.
- Keep required output-schema fields in projected responses, including the
  compact task-start handshake.
- Center Domain Lock controls, restore Sandbox file-type contrast, remove an
  inline category-action click handler, and correct setup code contrast and
  Visual Workspace focus outlines.

### 1.0.0-beta.1 — 2026-07-30

### Added

- Seventeen native WooCommerce abilities covering runtime status, products,
  variations, catalog terms, global attributes, orders, sales, and bounded
  catalog audits.
- Explicit runtime integration discovery for supported and discovery-only
  builders, themes, blocks, forms, field plugins, add-ons, dynamic-data
  plugins, code tools, and SEO suites.
- Shared native rules, bounded memory generalization, response projection,
  Elementor unchanged-hash reads, and guarded escaped-layout decoding.
- Credential-free agent setup, mode-aware Prompt Library entries, and a
  step-by-step plugin/companion update guide in wp-admin.
- A trusted latest-release companion check with version status, direct package
  and checksum links, and a credential-free agent update prompt.
- Fresh-install and upgrade lifecycle tests for memory, skills, and audit data.

### Changed

- WooCommerce catalog mutations now preview by default, use allowlisted native
  object APIs, enforce task context and permissions, require production
  confirmations where applicable, record audit entries, and verify readback.
- Production packages bootstrap through Jetpack's package-aware Composer
  loader and verify every generated manifest path.
- Persistent memory and skill writes reject high-confidence credential
  material. Existing user state and audit history remain intact on upgrade.
- Site-independent Elementor, content-model, asset, query, custom-code, dynamic
  architecture, and visual-proof rules apply from the immutable native
  registry instead of customer memory.
- Audit incidents render on one responsive page with readable error causes,
  contained payloads, and copy actions.

### Fixed

- Clean production dependency builds keep root dev autoload metadata out of
  Jetpack's optimized classmap and remove development-only paths that could
  make activation fail beside WooCommerce.
- Packaged generic skills seed through a release-scanned trusted path, while
  user-created skills cannot bypass credential guards by claiming built-in
  provenance.
- CI stages the plugin with the real release exclusions and rejects any
  resulting Jetpack manifest path that is absent from that staged package.
- The WooCommerce runtime gate boots the extracted release archive, Sandbox
  primary buttons keep readable text, and custom-code approval tokens have a
  functional copy control.
