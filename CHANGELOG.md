# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

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

- Licensing: the plugin and Visual are GPL-2.0-or-later.
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
  the revision it read, and the `stonewright-skills-save` ability and
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
  The `stonewright-knowledge-import` result lists the skipped slugs in
  `skills_skipped` (at most 50), and the Memory page reports how many skills
  were added and skipped.
- Let `stonewright-learning-record` update only its own draft skill for a
  topic. Any other skill under the requested slug is left unchanged, and the
  result reports `stonewright_skill_slug_taken` in `skill_error`.
- Keep plugin data when the plugin is deleted, so a reinstall or rollback finds
  OAuth grants, memory, skills, audit history, and settings as they were.
  Defining `STONEWRIGHT_REMOVE_ALL_DATA` as `true` before deleting removes
  every plugin table, option (the OAuth keys included), transient, and
  scheduled event, on every site of a network.
- Rebuild the Visual editor workspace on a session router with an action
  ledger and one-use applying permits. A backend policy blocks execution unless
  the host allowlist and tool discovery agree. Editor tools are declared in a
  strict catalog: every tool has a closed schema, arguments are validated
  before any editor or backend call, and mutating tools must read back their
  result. Native block tools work through the block editor's own store. The
  Elementor V3 and V4 adapters declare closed schemas for every tool, including
  history, save, evidence, and page-structure reads, and V3 undo and redo
  re-read the live editor tree.
- Report the error class instead of the exception message when the block
  registry fails during a batch mutation; the message stays in the server log.

### Fixed

- Refresh Companion runtime dependency floors and security overrides, and use
  patched test-runner versions for Companion and Visual.

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
  `stonewright-elementor-css-regenerate`. A redirect that is still refused
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
  `stonewright-skills-save` adds the skill library's details (action, slug,
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
- Make the Visual workspace follow its confirmation state: Apply is enabled
  only after confirmation is requested and Cancel discards a pending preview.
  The evidence marker follows the verified state, warnings count as checked
  evidence, and a superseded editor connection leaves the shared workspace
  root alone.
- Keep the backslashes in block attributes, such as `\u0026`, when posts,
  pages, templates, patterns, navigation, global styles, media, and blueprints
  are written, so they survive the write and its readback.
- Register the categories of the expertise, diagnostics, and custom-code
  abilities, which WordPress did not register for lack of a category, and stop
  registering categories that WordPress already has, such as `site`, which
  raised a notice when debugging is on.
- Show success, error, warning, and info notices on Stonewright pages in their
  status colour, and widen the Audit Log user column so a login name stays on
  one line on a wide screen.
- Serve valid JSON Schema for every ability over MCP. An empty schema, such
  as the `items` of a permissive array or an empty `properties` map, is sent as
  `{}` in `tools/list` and in the bounded schemas of `get-ability-info`; it was
  sent as `[]`, which is not a schema. A test validates the input and output
  schema of every registered ability against a JSON Schema 2020-12 structure.
- Rename the Google Maps widget ability to `stonewright/elementor-add-google-maps`.
  The Abilities API accepts only lowercase letters, digits, and dashes in an
  ability name, so the underscore name could not be registered. The public API
  contract records the rename, and a test checks every ability name.
- Count tools from the abilities WordPress registered. The Setup connection
  test, the Troubleshoot report, the Dashboard, and the AI Abilities page showed
  the number of ability classes; an ability that WordPress refused was still
  counted and shown as enabled. The AI Abilities page now marks an enabled
  ability that is not registered.
- Return `profiles_available`, `workflow_rules`, and `token_rules` from
  `tool-profile` with `action: "resolve"`, and declare that `tools` holds tool
  names for `resolve` and tool objects for `activate`. `resolve` failed output
  validation for every profile.
- Make `page` optional in a design spec, as documented, in both schema
  versions: a spec without `page`, or with an empty one, is valid, and
  `page.title` is optional. Validation errors now fill in the message, name the
  failing path and the expected shape, and the first errors are part of the
  error message that MCP clients receive.
- Run the WordPress Site Health tests in `site-health` and `site-health-test`.
  `site-health` returns the direct tests; `site-health-test` runs the named test
  and returns its status, label, description, and badge.
- Report the number of matches in `total` of `search-query` instead of the
  number of results on the page. Matches the caller may not read stay excluded.
- Write the warning about PHP functions that can run commands when the set of
  enabled functions changes and at most once a day, not on every request. The
  list is returned as `dangerous_php_functions` by `site-environment`.
- Keep renames recorded in the public API contract allowlist when the contract
  is regenerated.

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
- Name a client in the Audit Log for token, revocation, introspection, and
  authorization requests only once the site knows that client, so made-up
  identifiers no longer create audit rows of their own. The OAuth recorder is
  the only audit path of the OAuth routes, so a refusal is counted once.
- Store the address a client registered from as a keyed hash.
- Hold the markup the block editor queue sends back to the queued change. It
  must be one root block whose name, inner blocks, and attributes match the
  queued spec, checked when the browser answers and again before the finalize
  ability writes it. Script, style, and iframe elements, inline event handlers,
  and `javascript:` URLs are refused unless the queued change was approved for
  that kind of custom code, and the raw HTML gate now checks every attribute
  string of a spec for all of them.
- Check the REST nonce and the capability of block queue requests before
  reading the request body, and stop auditing the browser's claim polling as a
  write. REST audit rows summarize `html` and any string longer than 512 bytes,
  and cut parameter names longer than 96 bytes.

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

## Older releases

- [1.0.0-beta.11.1](docs/releases/1.0.0-beta.11.1.md)
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
