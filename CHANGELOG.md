# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

### Added

- Nothing yet.

## [1.0.0-beta.13.2] - 2026-08-26

### Fixed

- Bundle built-in skills and playbooks in the plugin ZIP and seed them from
  the plugin directory, so ZIP installs show the full Skills catalog and MCP
  task-start serves the built-in pack.
- Refetch GitHub Releases for Plugins → View details when the cached release
  is older than the installed version, and purge release caches after the
  plugin updates itself.
- Normalize Elementor 4.2+ Atomic props whose serialization nests empty
  object metadata, removing repeated "Prop descriptor unavailable"
  Troubleshoot diagnostics and restoring the Atomic widget inventory.

## [1.0.0-beta.13.1] - 2026-08-26

### Fixed

- Refetch GitHub Releases during WordPress plugin update checks so a newer
  supported beta appears on Dashboard → Updates instead of a stale cached
  "you are current" payload.
- Force at most one uncached GitHub lookup per request during an update cycle
  so a later transient write cannot rate-limit or wipe a successful discovery.

## [1.0.0-beta.13] - 2026-08-25

### Added

- Add `stonewright-elementor-css-regenerate` to rebuild one post or loop CSS
  file after an Elementor write; post-write verification is observation-only.
- Add one shared Setup client tablist for OAuth and Application Password,
  including a Grok Build / CLI catalog entry.
- Add dependency-aware Troubleshoot diagnostics that skip dependents when a
  prerequisite fails.
- Add connection status schema version 3 with truthful authentication state,
  including `reauth_required` and a model-visible `user_action`.

### Changed

- Close Elementor generated CSS only through the dedicated regenerator, then
  observation-only verify, then the browser recipe.
- Route provider-owned executable-code post types through the custom-code
  approval pipeline instead of generic content writers.
- Reconnect a degraded session once from `stonewright-task-start`, preserve the
  last good catalog, and never silently enable Direct writes from a plugin
  transport failure.
- Restrict automatic retry to handshake and allowlisted read-only bootstrap;
  mutations are never retried.
- Expose the generated **389**-ability Plugin and **101**-tool Direct contracts.

### Fixed

- Surface terminal OAuth reauthorization to clients instead of generic
  transport errors.
- Make Elementor CSS recovery target-aware for post and loop assets.
- Preserve OAuth session continuity across refresh rotation and recover
  degraded plugin sessions without dropping transport failure evidence.
- Normalize Elementor atomic runtime descriptors and keep php-execute `wpdb`
  guards type-compatible with the live handle.
- Verify custom-code provider runtime cache after save and roll back on
  verification failure.
- Group Elementor provider diagnostics so Status and Troubleshoot stay
  readable.

### Security

- Harden OAuth grant-family rotation and replay revocation.
- Reject generic content writes to executable-code surfaces; WPCode active PHP
  uses the provider save and cache path.
- Install php-execute write guards as a real `wpdb` subclass around the live
  handle.

## [1.0.0-beta.12] - 2026-08-24



### Added

- Add read-only Elementor provider discovery with ownership, trust, compatibility,
  certification, Status and Troubleshoot visibility, and certified
  native-preferred metadata for Elementor default styles; expose it in the
  normal Elementor design profile and resulting MCP tool catalog.

### Changed

- Show formatted, sanitized GitHub release notes in the WordPress Plugins
  View details modal.
- Keep audit history until an operator configures scheduled retention, and
  coalesce routine heartbeat and successful authentication activity.

### Fixed

- Retry Elementor post-lock renew when WordPress options compare-and-swap
  reports no row change while this writer still owns a live lease, instead of
  aborting a verified document write.
- Retry Elementor CSS directory lease renew when WordPress options
  compare-and-swap reports no row change while this writer still owns a live
  lease, instead of aborting CSS closure after a verified document write.
- Prevent single-post Elementor writes from clearing the global generated CSS
  directory. Normal writes now invalidate HTML cache only; post-write closure
  uses Elementor's official Post CSS API inside a bounded asset transaction
  with file-count/hash evidence, same-origin HTTP probes, collateral detection,
  and byte-for-byte rollback. Restore runs only while this writer still owns
  the CSS directory lease, including an expired-but-ours row. A vacant lease
  after another writer committed and released is skipped rather than reclaimed.
  Same-directory restore temps are ignored by capture and unlinked after
  restore. Post-lock renew failure after a successful CSS commit is
  non-fatal evidence (`lost_after_commit`), not a false write failure.
  Post CSS location
  checks accept Elementor 3.30 `?ver=` URLs and scheme-prefixed filesystem
  paths. The former `regenerate_css` input is removed;
  Direct mode now preserves CSS metadata and refuses global `flush-css`.
- Mark upstream `elementor/manage-elements` non-routable while its implementation
  clears Elementor's global files cache.
- Recognize Jetpack classmap manifests used by WooCommerce 10.9 during MCP
  compatibility preflight, while requiring their classmap or PSR-4 entry to
  resolve to the canonical adapter target.
- Normalize two-component WordPress core versions such as 6.9 so guarded
  Abilities API fallbacks cannot falsely block the MCP server.
- Accept WordPress `init` hook arguments in audit retention scheduling so an
  empty string from `WP_Hook::do_action()` cannot TypeError the admin screen.
- Skip audit and incident table `dbDelta` after a healthy schema is installed,
  so admin requests do not re-reconcile unique indexes on every `init`.
- Make Plugin and Direct audit events share lifecycle identities, safe
  idempotency, operation classifications, redaction, and crash-safe rotation.
- Stop the block finalizer after terminal client errors or browser shutdown,
  retry only transient failures, and count only accepted results as applied.
- Scope Direct idempotency receipts to a canonical site fingerprint, recover
  only stale malformed locks, and compact retained terminal markers under the
  interprocess audit lock.
- Record ordinary Direct REST failures once from dispatch context, persist one
  blocked event for terminal finalizer heartbeat denials, and fail audit
  retention when incident retention cannot delete its batch.
- Invalidate the Direct task-start write latch when an alias resolves to a
  different canonical target, including Application Password operations.
- Partition Direct terminal receipts and incidents by canonical target identity
  so retargeting an alias cannot replay or suppress another site's event.
- Convert thrown ability callbacks and structured `ok:false` results into one
  failed Plugin audit event and incident instead of a success or uncaught exit.
- Serialize Direct stale-lock recovery and incident updates with ownership-safe
  locks, and clean bounded recovery/release quarantine artifacts.
- Reject oversized browser-finalizer results after validating the active lease
  and before changing queue state.
- Bind Plugin and Direct repair validation to generation, update-time, and
  occurrence CAS tokens so a newer failure blocks stale resolution or learning.
- Establish terminal receipts immediately after the authoritative audit row,
  reporting incident persistence failures as bounded secondary errors without
  fallback duplicates.
- Honor browser-finalizer `retryable:true` payloads independently of HTTP 409,
  and accept terminal result receipts only when `retryable:false` is explicit.
- Reject ambiguous Codex TOML, make config and receipt updates share one
  transactional lock, and use compare-and-swap rollback so a failed update
  cannot overwrite newer configuration or lose a concurrent receipt.
- Parse the complete Codex TOML document before and after package updates, so
  malformed target arrays or unrelated sections cannot be mutated or receive
  a restart receipt while comments and untouched bytes remain unchanged.
- Give each TOML/JSONC config its own exclusive write lock and recheck the
  exact read hash immediately before rename; cross-resource rollback now uses
  the same compare-and-swap rule.
- Generate companion client semantics from the plugin's authoritative catalog,
  keeping OAuth support, default profiles, and relist behavior in parity.
- Enforce the restart-verification order `task-start` → `setup-profile` →
  `wordpress-mcp-status` → `client-surface-check`, and use a process-bound
  catalog observation instead of caller-supplied tool names.
- Record restart attestation for schema-v2 non-error MCP results. Status,
  relist, mismatch, and setup `ok` flags stay separate truthful signals and
  do not hide plugin validation failures.
- Preserve plugin task-start failures, stop forwarding the companion-only site
  alias into the plugin schema, and reconcile authoritative saved/effective
  WordPress mode and surface against client hints and client-visible tools.
  Empty refresh lists no longer override a failed visibility check.
- Version WorkflowPreflight mode fields and treat the plugin's saved/effective
  WordPress mode as authoritative; malformed schemas and mode mismatches block
  startup.
- Make the real companion health payload report running and expected package
  truth, with configured package evidence available only from an authenticated,
  validated source; include `client-surface-check` in the update prompt.
- Resolve ChatGPT Desktop consistently through the Codex TOML adapter, and make
  the OAuth UI browser check assert matching unique client tabs and panels
  instead of a stale hard-coded count.
- Match Elementor's `elementor/manage-default-styles` contract at commit
  `3afafe33b7499b4e8fcb4c684e55111721bb0c96`, including non-idempotent write
  annotations, exact input/output schemas, CSS/tag/mode semantics, runtime
  constants, and an exact 20-operation runtime limit; reject added schema
  keywords and every annotation or contract mismatch.
- Treat PHP `self` and `parent` return types as the declaring class so MCP ABI
  preflight accepts adapters on PHP 8.1–8.4, not only 8.5.
- Keep MCP adapter boot when two active plugins vendor the same
  `wordpress/mcp-adapter` version, so WooCommerce 10.9 can sit beside Stonewright
  without dropping `/mcp/stonewright`.
- Isolate Elementor provider discovery failures so Status and Troubleshoot
  retain surviving providers and expose at most 20 diagnostics with full
  blocker and warning counts, per-severity truncation, and reserved visibility
  for critical blockers.
- Bound provider discovery to 50 providers and 200 capabilities, report full
  totals and truncation state, and replace rejected or untrusted schemas with
  depth/key/byte summaries; canonicalize schema fingerprints and cap rejected
  default-style actions with truthful totals.
- Keep third-party `pro-elements/*` runtimes distinct from official Elementor
  Pro and read-only without exact Stonewright-owned certification.
- Abort Elementor V4 spec rendering before mutation when the required backup
  snapshot cannot be verified.
- Resolve runtime ownership from active plugin main files and safe plugin
  headers even when the main filename differs from its folder in REST/MCP
  requests.

### Security

- Block MCP startup before adapter creation when required MCP Adapter or
  Abilities API symbols are missing, conflicting, or ABI-incompatible, repeat
  that preflight against the exact runtime adapter class at registration, and
  keep the canonical Ability and Registry targets plus discovered ownership
  candidates immutable so filters cannot authorize compatible decoys or hide
  an active owner.
- Treat WordPress 6.9 core Abilities plus Stonewright's guarded compatibility
  fallback as one compatible owner, ignore inactive plugin manifests, validate
  the exact loaded ABI before invocation, and report every blocked symbol with
  its owner, version, reason, and safe remediation.
- Keep third-party Atomic schemas discoverable but read-only, and admit only
  schemas identical to Stonewright's immutable bundled or verified-official
  authority into renderers and mutators.
- Derive upstream Elementor provider identity from the registered callback's
  verified class and active-plugin file boundary, never self-declared metadata.
- Verify the downloaded plugin ZIP against its exact entry in the bounded
  `SHA256SUMS.txt` release manifest before WordPress may install it, with
  typed fail-closed errors for missing, malformed, forged, unavailable, or
  mismatched checksums.
- Bind each queued plugin ZIP to its exact release version, package URL, and
  checksum manifest. Pre-install verification now fails closed when that
  binding is missing or mismatched, even if release metadata changed or the
  queued URL carries a download query.
- Identify official Stonewright ZIPs from their release binding before reading
  upgrader context, and fail closed when a foreign plugin context conflicts
  with that verified package.
- Clear inherited WordPress credentials before resolving an explicit site
  alias and refuse startup when that alias is unknown, preventing a stale
  environment from selecting the wrong site.
- Resolve an explicitly selected `env://STONEWRIGHT_WP_APP_PASSWORD`
  credential from a protected pre-clear snapshot while still discarding every
  unrelated inherited WordPress credential.
- Bind active-client update attestation to a private registry key and one-time
  expiring receipt, the owning MCP client, exact official package provenance
  and version, config hashes, restarted process, and process-bound catalog
  observation. Forged, replayed, expired, stale, cross-client, and drifted
  attestations now fail closed without exposing key material.
- Require exact official `npx`/`npx.cmd --package <Stonewright package>
  stonewright-mcp` client entries, and refuse updater metadata or transient
  injection when the release omits `SHA256SUMS.txt`.
- Route Direct theme activation, plugin deletion, user deletion, Application
  Password revocation, and skill deletion through the central write gate in
  addition to their explicit confirmation checks.
- Recursively redact credential patterns from every audit free-text value
  before sanitized arguments or error metadata are persisted.
- Recover abandoned Direct audit locks with boot/process-start ownership and
  an exclusive recovery mutex so PID reuse or a replacement lock cannot be
  renamed or deleted.
- Coalesce repeated identical Plugin permission and safety denials by site,
  ability, and error under a stale-recoverable CAS option lock while retaining
  the first event and bounded count summaries.
- Validate Direct lock owners with available host, boot, and per-PID process-start
  identity plus a bounded lease so a live decoy or reused PID cannot block forever.
## [1.0.0-beta.11.1] - 2026-08-24

### Fixed

- Add a preview migration bridge so the native plugin updater validates
  declared supported, preview, and stable release channels before selecting an
  eligible update.

## Older releases

- [1.0.0-beta.11](docs/releases/1.0.0-beta.11.md)
- [1.0.0-beta.10](docs/releases/1.0.0-beta.10.md)
- [1.0.0-beta.9](docs/releases/1.0.0-beta.9.md)
- [1.0.0-beta.8](docs/releases/1.0.0-beta.8.md)
- [1.0.0-beta.7](docs/releases/1.0.0-beta.7.md)
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
