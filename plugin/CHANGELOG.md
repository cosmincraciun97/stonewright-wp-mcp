# Changelog

## [Unreleased]

### Added

- Add an OAuth sign-in panel to Setup. It shows whether OAuth sign-in is on,
  the transport, the MCP server URL, and a suggested server name; every reason
  sign-in is unavailable, each with its fix; and per-client setup: commands,
  configuration entries with their file locations, install links where a
  client documents them, how sign-in starts, and the limits of hosted clients
  and local sites. The Application Password route stays available.
- Add a **Connected OAuth clients** list to Setup with each client's approvers,
  connection date, and last use. Disconnect closes every live grant of that
  client at once, needs `manage_options` and a nonce, and is written to the
  Audit Log. It first deletes the client's pending consent requests and makes
  its unused authorization codes unusable, so nothing approved before the
  disconnect can create a new grant. The connected-apps admin address leads to
  this list.
- Accept Client ID Metadata Documents: a client may use an HTTPS URL as its
  `client_id`. The site fetches the document from a public address without
  following redirects, accepts only a public client, caches the result, and
  names the publishing host on the consent screen. The authorization server
  metadata declares `client_id_metadata_document_supported`.
- Add the `iss` parameter (RFC 9207) to authorization responses, including
  error redirects, and declare it in the authorization server metadata.
- Accept `localhost`, as well as `127.0.0.1` and `[::1]`, for HTTP loopback
  redirect URIs. The callback may use any port; its host must match the
  registered host.
- Add `refresh_token_expires_in`, the seconds until the refresh credential
  expires, to token responses.
- Add the built-in `how-to-write-skills` skill, covering trigger descriptions,
  body size, version constraints, exposure flags, import review, and testing a
  skill before it is enabled.

### Changed

- Return the grant's current refresh credential, with a new access credential,
  when a refresh credential is presented again within 60 seconds of its use,
  instead of failing. The window is filterable with
  `stonewright_oauth_refresh_reuse_window` (0 to 300 seconds; 0 restores strict
  single-use rotation).
- Prepare queued Gutenberg block changes in the block editor through the block
  change queue console, a hidden admin page that replaces the previous
  finalizer page. The page (`stonewright-block-finalizer`) has no menu entry
  and needs `edit_posts`; its REST routes under
  `/stonewright/v1/block-finalizer/` are unchanged. Queue requests need a REST
  nonce and a scoped queue token in the JSON body, and a request from another
  origin is refused.
- Move a skill to the trash when `DELETE /stonewright/v1/skills/{id}` is
  called. Built-in skills answer 403; permanent deletion stays a separate step
  in the Trash view.
- Answer HTTP 409 when an imported skill's slug already exists, including a
  reserved built-in slug, and refuse a save over a built-in skill's slug. A
  skill save that carries a stale revision, or the revision of a skill that no
  longer exists, also answers 409 and changes nothing. The skill editor sends
  the revision it read, and the `stonewright/skills-save` ability and
  `POST /stonewright/v1/skills` accept it.
- Let a skill be enabled while the plugin components it needs are missing; it
  stays hidden from agents until they are present.
- Require the server-issued review receipt to import a skill. The receipt is
  bound to the reviewing user and the reviewed file name and bytes, and the
  review stays valid for 30 minutes.
- Judge skill lint findings without reference to the language a skill is
  written in. Stale, retired, and trashed skills are reported as `stale_record`,
  and references to abilities that are not registered as
  `unavailable_tool:<ability>`.
- Import the skills of a knowledge bundle as disabled drafts that never replace
  a skill: a slug that already exists in any state, the trash included, or that
  a built-in skill reserves is skipped, and so is an entry the library refuses.
  The `stonewright/knowledge-import` result lists the skipped slugs in
  `skills_skipped` (at most 50), and the Memory page reports how many skills
  were added and skipped.
- Let `stonewright/learning-record` update only its own draft skill for a
  topic. Any other skill under the requested slug is left unchanged, and the
  result reports `stonewright_skill_slug_taken` in `skill_error`.
- Keep plugin data when the plugin is deleted, so a reinstall or rollback finds
  OAuth grants, memory, skills, audit history, and settings as they were.
  Defining `STONEWRIGHT_REMOVE_ALL_DATA` as `true` before deleting removes
  every plugin table, option (the OAuth keys included), transient, and
  scheduled event, on every site of a network.

### Fixed

- Recover OAuth signing-key generation on PHP installations whose default
  OpenSSL configuration is unavailable by trying PHP's adjacent configuration
  and a bundled minimal configuration. Plugin activation can complete when key
  generation still fails, with an administrator notice and a protected retry
  action; the notice says that creating new keys signs every connected client
  out. Application Password authentication remains available.
- Verify packaged plugin activation on Linux and Windows, and reject an
  existing activation-smoke working directory before writing or removing files.
- Keep ordinary words readable in Audit Log free-text redaction. A value
  written as prose after a credential word ("the token is ...") is still
  masked, and messages such as "The refresh token is no longer valid." stay
  readable.
- Store successful audit rows without an error code, repair hint, or incident
  link, and show no error cause, repair hint, or incident link for successful
  rows stored earlier.
- Record read-only abilities as reads, and stop treating abilities whose names
  start with `blocks-` as lock errors.
- Give every failed, blocked, and retryable audit row a readable message; when
  the caller supplied none, it names the outcome and the error code.
- Open one incident per cause, identified by the error code, the ability
  family, and the kind of resource, instead of one per record, path, or
  category.
- Close incidents that do not involve writes, verification, or rollback after
  7 days without a new occurrence, reopen them when the cause recurs, and count
  reopenings. A daily run performs the sweep and stays scheduled whatever the
  retention setting; rows and incidents are still deleted only when a retention
  window is configured. Write incidents still close only through a verified
  repair.
- Treat generated Elementor CSS served behind a redirect to another page of the
  same site as protected delivery instead of a failure in
  `stonewright/elementor-css-regenerate`. A redirect that is still refused
  (another origin, an HTTP downgrade, a loop, or a disallowed target) reports
  the HTTP status and the target's origin only, never its path or query.
- List only refusals and failures in the Audit Log Auth view, count every
  incident in the incident totals, and drop recurring-error patterns that have
  not occurred for 30 days from the Recurring errors panel.
- Store Memory creation and update times in UTC and label them UTC on the
  Memory page. Recording that an entry was retrieved no longer changes its
  Updated time.
- Keep proposed Memory lessons as drafts until an administrator approves them.
  Approval records who approved the lesson and when (UTC), approved lessons are
  offered to agents like any other active reference entry, and a one-time
  repair returns proposed lessons that were active without a recorded approval
  to draft.
- Write one Audit Log row per call to the custom Elementor widget, Elementor
  atomic widget, skill-save, and block-queue abilities, instead of a second
  row. Defining, registering, and creating a custom Elementor widget, and
  defining an Elementor atomic widget, record the call's row only, including
  when the source guard rejects the widget. A skill saved through
  `stonewright/skills-save` adds the skill library's details (action, slug,
  revision, content hash) to the call's row; saves from REST and the admin
  screen keep their own row. Queueing a block change writes one row for the
  call, and sweeping stale entries out of the queue is recorded as a separate
  `gutenberg.queue_prune` event.
- Run a learned candidate's lint before withdrawing the skills it replaces when
  it is promoted, so a candidate that fails lint leaves the existing skills in
  service.
- Screen imported skill text for override and credential instructions in time
  that grows with its length only, so a long file no longer stalls the import
  review.
- Answer `invalid_target` instead of a server error when a resource is written
  without a scheme, such as `host:port`.
- Work through unused registered OAuth clients in the daily clean-up in batches
  of 200, up to 25 batches per run, instead of stopping after the first 200.
- Retry an OAuth table upgrade that the database refuses after an hour instead
  of on every request, and read the schema version from the autoloaded options.
- Give a site that installs the OAuth tables on its first request, such as a
  sub-site after a network activation, its OAuth keys and the daily clean-up
  event. A site that already holds OAuth state is never given new keys
  automatically.

### Security

- Revoke the grant created from an authorization code when that code is
  replayed.
- Expire refresh credentials after 30 days without use and end a grant at most
  90 days after it was authorized; grants that already exist keep their end
  date.
- Report a refresh credential as active in token introspection only while it
  is the current, unexpired credential of a live grant.
- Write refresh replays that revoke a grant, authorization-code replays,
  explicit revocations, and duplicate refresh deliveries to the Audit Log as
  their own security events every time. The rows hold no credential values;
  ordinary refusals stay grouped.
- Refuse a bearer credential on the protected MCP route unless it is shaped
  like a signed access credential, before it is inspected, so sealed refresh
  credentials, authorization codes, and look-alike values presented as bearers
  are never decrypted and never change a stored grant.
- Count an IPv6 address by its /64 prefix, and an IPv4-mapped address as its
  IPv4 address, in the OAuth request limits.
- Name a client in the Audit Log for token, revocation, and authorization
  requests only once the site knows that client, so made-up identifiers no
  longer create audit rows of their own.
- Store the address a client registered from as a keyed hash.

## [1.0.0-beta.13.3] - 2026-09-17

### Fixed

- Select one compatible MCP adapter runtime (`wordpress/mcp-adapter` ^0.6.1
  with Jetpack Autoloader ^5.0) instead of treating every installed copy as an
  active conflict, and delay adapter boot until `plugins_loaded` 99 so another
  plugin can load first.
- Report MCP server registration failures instead of swallowing `create_server`
  `WP_Error` results, and keep the default upstream MCP server distinct from
  Stonewright.
- Keep Setup/Troubleshoot `info` checks out of the successful-check count, and
  require a Stonewright `serverInfo` handshake (`initialize` →
  `notifications/initialized` → `tools/list` → `stonewright-task-start`).
- Check `/mcp/stonewright` and `/mcp/stonewright-oauth` separately, treat
  “configuration verified, connection not tested” as info, and keep the live
  handshake probe distinct from an OAuth HTTP 401 guard.
- Treat HTTP 200 `ok:false` ability results as audit failures, keep ACF writes
  idempotent when the stored raw value already matches, and separate Elementor
  CSS generation from HTTP delivery evidence.
- Treat only `text/css` HTTP probes as verified Elementor CSS delivery; JSON,
  PDF, HTML, and empty bodies stay unverified or failed.
- Reject invalid ACF values before a no-op, repair a missing or wrong field
  key reference through `field_*`, and compute `changed` from before/after
  raw value and reference rather than `update_field`'s return.
- Preserve unknown Elementor settings on mobile-only deltas, reject stale
  editor hashes, and keep Memory draft lessons from becoming global rules.
- Surface a Loop Grid compile failure after CPT/ACF/content writes instead of
  returning `ok:true`, keep partial effects visible, and refuse a full recreate.
- Block a stale Elementor editor save after a later MCP write, keep the local
  draft, and leave `post_status` unchanged.
- Hydrate Application Password Basic credentials into `PHP_AUTH_*` when Apache
  or php-fpm only expose `HTTP_AUTHORIZATION`.
- Refuse theme-file patches when a marker is missing or matches more than once.

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

- Add `stonewright/elementor-css-regenerate` to rebuild one post or loop CSS
  file through Elementor's official update API inside a guarded asset
  transaction; `stonewright/elementor-post-write-verify` is observation-only.
- Add one shared Setup client tablist for OAuth and Application Password,
  including a Grok Build / CLI catalog entry.
- Add dependency-aware Troubleshoot diagnostics that skip dependents when a
  prerequisite fails.

### Changed

- Close Elementor generated CSS only through the dedicated regenerator, then
  observation-only verify, then the browser recipe.
- Route provider-owned executable-code post types through the custom-code
  approval pipeline instead of generic content writers.
- Expose the generated **389**-ability Plugin contract.

### Fixed

- Make Elementor CSS recovery target-aware for post and loop assets.
- Normalize Elementor atomic runtime descriptors and keep php-execute `wpdb`
  guards type-compatible with the live handle.
- Verify custom-code provider runtime cache after save and roll back on
  verification failure.
- Group Elementor provider diagnostics so Status and Troubleshoot stay
  readable.
- Recover degraded plugin sessions without dropping transport failure evidence.

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
- Disable automatic audit retention by default and run deletion only through
  an explicitly configured daily policy.
- Coalesce successful authentication polling and omit finalizer heartbeats from
  the mutation audit stream.

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
- Persist canonical audit lifecycle identity and bind terminal idempotency to
  the operation, resource, payload, status, and ability.
- Stop the browser finalizer on terminal HTTP responses and page shutdown,
  retry only transient failures, and count only accepted result submissions.
- Persist exactly one blocked security event for terminal finalizer heartbeat
  denials while keeping successful heartbeats outside the mutation stream.
- Treat incident-retention delete failures as failed audit retention runs so
  the daily success transient cannot suppress a retry.
- Stop finalizer retries for malformed successful responses and unexpected
  runtime failures; keep queued `ok:false` receipts pending without counting
  them as applied or failed.
- Record thrown ability callbacks and structured `ok:false` results as one
  failed audit event and incident, never as `SUCCESS`.
- Reject oversized serialized finalizer results only after validating the
  active lease and before changing persistent queue state.
- Retry incident observation against the latest generation when a concurrent
  failure lands, and refuse automatic resolution that would close over that
  failure.
- Mark verified-repair learning stale when linking it to the incident fails.
- Resolve and promote incident repairs only while their generation,
  update-time, and occurrence token remains unchanged; install the added
  incident schema columns during normal version upgrades.
- Preserve the authoritative terminal audit receipt when incident persistence
  fails and expose that failure only as bounded secondary receipt metadata.
- Require explicit `retryable:false` on terminal browser-finalizer receipts.
- Leave wordpress.org and other non-Stonewright plugin downloads unchanged at
  the pre-download gate. Fail closed only for official Stonewright packages or
  the Stonewright plugin basename.
- Add schema-v2 authoritative saved/effective WordPress mode fields to full
  and compact WorkflowPreflight/task-start responses.
- Show one authoritative four-step post-update verification flow in Setup and
  the copied update prompt: task start, profile setup, status, then a
  process-bound client surface check.
- Require complete authenticated configured-package evidence in companion
  health responses and include `stonewright-client-surface-check` in the
  post-update verification prompt.
- Keep ChatGPT Desktop aligned with the Codex TOML catalog alias and replace the
  admin OAuth browser test's stale client count with tab/panel parity checks.
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
- Fetch a bounded `SHA256SUMS.txt` manifest and verify the exact release ZIP at
  WordPress's pre-download install/update gate. Missing, malformed, forged,
  unavailable, empty, and mismatched checksums now fail closed with typed
  errors.
- Persist the exact release/version/manifest binding when WordPress queues a
  Stonewright ZIP, and refuse pre-install when that binding is unavailable or
  mismatched instead of returning an unverified prior downloader result.
- Resolve official Stonewright ZIP identity and release binding before trusting
  upgrader plugin context, and stop with a typed error when that context names
  a foreign plugin.
- Require an exact `SHA256SUMS.txt` release asset before accepting updater
  metadata or injecting an update transient, with a typed
  `missing_checksum_asset` recovery reason.
- Redact nested private keys, PEM certificates, and credential blobs from audit
  payloads without removing surrounding safe text, and keep encoded output bounded.
- Redact credential assignments, authorization carriers, Application Password
  shapes, credentialed URLs, and private-key bodies recursively from every
  free-text audit value before persistence.
- Coalesce identical permission and safety denials by site, ability, and error,
  retaining the first event plus bounded count summaries and severity under a
  stale-recoverable option mutex with compare-and-delete ownership.

## Older releases

- [1.0.0-beta.11.1](../docs/releases/1.0.0-beta.11.1.md)
- [1.0.0-beta.11](../docs/releases/1.0.0-beta.11.md)
- [1.0.0-beta.10](../docs/releases/1.0.0-beta.10.md)
- [1.0.0-beta.9](../docs/releases/1.0.0-beta.9.md)
- [1.0.0-beta.8](../docs/releases/1.0.0-beta.8.md)
- [1.0.0-beta.7](../docs/releases/1.0.0-beta.7.md)
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
