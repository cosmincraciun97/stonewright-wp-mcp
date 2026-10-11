# Changelog

## [Unreleased]

### Added

- Refuse custom code in Direct writes. `stonewright-global-styles-update` refuses
  theme.json `styles.css` and any per-block `css`. `stonewright-elementor-data-update`
  refuses custom CSS setting keys (`custom_css`, `_custom_css`, `customcss`,
  `page_custom_css`, `kit_custom_css`, `additional_custom_css`, `custom_css_pro`, and
  any `custom_css` key with a suffix) that hold a value, HTML-type widgets
  (`html`, `raw-html`, `raw_html`, `text-path`, and any widget type containing
  `html`), and script, style, iframe, object, embed, link, or meta http-equiv markup in any setting. Content, template,
  template part, blueprint, and widget (`stonewright-widget-manage`) writes, and autosaves, refuse `<script>`,
  `<style>`, `<iframe>`, `<object>`, `<embed>`, `<link>`, `<meta http-equiv>`, inline `on*=` event handlers, and `javascript:` URLs in
  the title, content, description, widget instance, and meta (`_elementor_data` meta is checked as
  an Elementor tree). `stonewright-widget-manage` also refuses HTML-type widgets (`custom_html`). Element and attribute names are matched case-insensitively,
  entity-encoded and whitespace-split `javascript:` URLs are detected, and block
  comment attributes are decoded before they are checked. A refusal sends nothing,
  writes one blocked audit row, and returns the `stonewright-custom-css` envelope
  (`approval_required`, `agent_must_stop`) with the offending paths; custom code
  needs Plugin mode and its custom-code approval. The refusal text says that a page,
  template, or widget that already holds custom code (an HTML widget, custom CSS, or
  inline script) cannot be saved through Direct either.
- Save a pre-image before a whole-document write. `stonewright-template-update`,
  `stonewright-template-part-update`, `stonewright-global-styles-update`,
  `stonewright-content-update`, and `stonewright-blueprint-apply` with `post_id`
  read the current resource with `context=edit` and store it under
  `~/.stonewright/backups/<site alias>/<tool>/<resource id>/<timestamp>.json` (mode
  0600 where the platform supports it) before they write. The result returns
  `backup_path` and `backup_sha256` (SHA-256 of the stored file). When the resource
  cannot be read or the file cannot be stored, the write is refused with
  `preimage_unavailable` and nothing is sent. The last 20 backups per resource are
  kept; the oldest are deleted. The file holds the REST resource only, never
  request headers or credentials.

### Changed

- Refuse more WP-CLI commands in the agent-facing runners (`stonewright-wp-cli-run`,
  `-batch-run`, `-job-start` and their HTTP routes): `config`, `scaffold`, plugin
  and theme `install`, `update`, `delete`, `activate` (and plugin `uninstall`), `db`
  except its read-only subcommands, `user` changes, role and capability changes,
  `core download`, `update` and `install`, `cron event run`, `cli update`,
  `search-replace` without `--dry-run`, writes to sensitive options, and the
  `--config`, `--ssh` and `--http` flags, in addition to `eval`, `eval-file`,
  `shell`, `package`, `--exec`, `--require` and `--prompt`. The command is found
  the way WP-CLI reads it, so a leading `@alias`, global flags before or between
  the command words, a flag with a separate value, quotes, upper case, padding and
  a whole command in one token no longer hide it. Behavior change:
  `stonewright-wp-cli-batch-run` (and `companion_wp_cli_batch_run`) refuses those
  command groups and runs read-oriented commands only: reads of `post`, `term`,
  `option`, `plugin`, `theme`, `user`, `db check`, cron lists, and cache and
  rewrite flushes. A batch with one command off that list is refused before any
  command runs; a batch of writes runs through `stonewright-wp-cli-job-start`.
  Commands the companion builds for its own setup, rescue and approved command
  recipes keep a narrower list that refuses only the entry points that run
  arbitrary code.
- Keep `stonewright-php-execute` on the `full` profile only, as in the plugin. This changes behavior: the offline profile lists of every other profile (bootstrap, essential-static, essential, low-tools, elementor-design, content-model, gutenberg, wp-cli, site-admin) no longer include it, and those profiles and `discover-execute` no longer keep it registered when the site lists it. A client on one of those profiles no longer sees it; switch to `full` to use it. The setup profile (notes, agent tool list, refresh list, inventory notes), the MCP instructions and the WP-CLI tool descriptions of those profiles no longer tell the agent to call it.
- `stonewright-client-surface-check` without `expected_tool` checks `stonewright-php-execute` on the `full` profile and `stonewright-task-start` on every other profile, so a profile without php-execute is no longer reported as missing it.
- Limit `stonewright-media-upload`. A local `path` is resolved first (symlinks
  and `..` included) and must sit inside the working directory, the companion
  media folder (`<state dir>/media`) or a root listed in the new
  `STONEWRIGHT_DIRECT_MEDIA_ROOTS` (comma- or semicolon-separated); any other
  file is refused unless the call passes `confirm: true`. A `url` must be http
  or https and must resolve only to public addresses: private, loopback,
  link-local, multicast and reserved IPv4 and IPv6 ranges are refused, whether
  given as an address or reached through a name. Redirects are followed one hop
  at a time (at most five) and each hop gets the same check. The tool takes a
  new optional `confirm` argument.
- Limit what `stonewright-media-upload` sends, for a path and for a URL. Only
  image, audio, video, PDF, Office, OpenDocument, txt and csv extensions are
  accepted; SVG, HTML, XML, PHP, scripts, executables and archives are refused
  even with `confirm: true`. A file over 25 MiB (`STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES`
  changes it, up to 512 MiB) is refused: a local file is measured before it is
  read, and a download stops as soon as it passes the limit. For images and PDF
  the first bytes must match the extension, and no file may start with markup or
  contain a PHP open tag near its start. The upload name is reduced to one safe
  file name (no directories, control or direction-override characters, or
  characters WordPress drops; an inner `.php` is marked, as WordPress does), and
  a name with no extension takes the source extension or the one the response
  content type implies. A download now has a 60 second limit.
- `stonewright-template-update` and `stonewright-template-part-update` take
  `confirm` and need `confirm: true` when `STONEWRIGHT_DIRECT_WRITES=confirm` or the
  site is remote, as destructive Direct tools do. `stonewright-blueprint-apply` over
  an existing page (`post_id`) keeps that rule, and its schema and description now
  say so.
- Documentation: Direct mode docs state what it protects and what it does not do
  yet (no restore tool for pre-images, no after-write verification, partial
  custom-code screening, no DNS pinning for media URLs, unscreened
  `stonewright-wp-cli-run`). The rule that the companion never calls WordPress
  REST write endpoints now applies to Plugin mode, with Direct mode as the
  documented exception.

### Fixed

- Direct `stonewright-task-start` reports the write mode the site's tools use.
  `write_mode` (and `target_context.stonewright_mode`) was read from
  `STONEWRIGHT_DIRECT_WRITES` alone, so a `localhost`, `.local` or `.test` site
  showed `confirm` while its tools ran `on`. It now applies the same rule as the
  tools: the variable when set, otherwise `on` for those hosts and `confirm` for
  the rest. With no site resolved it is unchanged.
- Direct `stonewright-site-discover` lists the plugin-only capabilities
  accurately. `unavailable_without_plugin` (also shown in the setup profile and
  connection status) now includes Rescue, change sets, section reuse, Design
  Direction, site Context and Elementor V4 (Atomic) features, and its Elementor
  entry says that Direct reads and writes raw `_elementor_data` over WP-CLI on
  the local install or over core REST when the site exposes the meta, instead of
  "only on local WP-CLI sites".
- Stop Direct Elementor tools from using WP-CLI on the wrong site.
  `stonewright-elementor-status`, `-data-get` and `-data-update` now compare
  the `home` option (then `siteurl`) of the local WP-CLI install with the alias
  URL before using WP-CLI for an alias. When they differ, or the local address
  cannot be read, WP-CLI is not used: the tool answers through core REST and
  returns `wp_cli_refused` ("the local WordPress is not the target site"),
  without naming either host. The answer is cached per alias and local install
  for the life of the process. Calls with no alias are unchanged.
- Stop one tool error from blocking Direct task start for good.
  `stonewright-task-start` returned `required_actions:
  ["repair_open_incidents_first"]` for every incident, but Direct writes no
  change set or verifier row, so `stonewright-incident-repair-record` could not
  close it. Now only an open incident whose latest failure row carries a
  resource and a change set requires repair first. Other incidents stay in
  `incident_actions` as information, with a guidance line saying so. An open or
  observing incident with no new matching failure for seven days is closed as
  resolved at the end of that period and leaves `incident_actions`, unless its
  latest failure carries a resource and a change set (those wait for a verified
  repair, as write incidents do in Plugin mode); a later matching failure reopens
  a closed incident and counts the reopening.

## [1.0.0-beta.14] - 2026-10-10

### Added

- Accept `STONEWRIGHT_MCP_TOOL_PROFILE=inspect`, the read-only profile of
  discovery, read, and verify tools. It registers the permanent gateways and the
  local `stonewright-wp-cli-status` and `stonewright-wp-cli-discover` tools, and
  no `stonewright-php-execute`, WP-CLI run, batch, job, or install tool, and no
  command tool, even when the site's saved surface is `full`. Setup output
  names no runtime or WP-CLI run tool for it and does not ask a client to
  refresh for `php-execute`. In Direct (pluginless) mode the profile maps to the
  Direct bootstrap surface. Auto and unknown profile values never select it.
- `stonewright rescue status|rollback <incident>` runs the plugin's
  `wp stonewright rescue` command on a site with a local WordPress root, with
  every active plugin but Stonewright skipped (`--skip-plugins=<list>`) and the
  theme skipped (`--skip-themes`), so a rescue rollback still works when
  another plugin or the theme stops WordPress from loading. It starts every
  process through the tokenized WP-CLI runner (`execFile`, argv tokens only, no
  shell, eval, `--exec` or `--require`), validates the incident id, the user,
  the plugin names and the token before anything runs, passes the confirmation
  token through the child's environment instead of its arguments, and writes
  one audit row without arguments, token or output. Exit code 2 means a
  confirmation token is required.

### Changed

- Retry a token refresh once when no response arrived at all (a timeout or a
  reset connection). The retry sends the same refresh credential while the
  cross-process refresh lock is still held, starts at most 30 seconds after the
  first request, and is aborted 50 seconds after it, inside the server's
  60-second duplicate window. A received HTTP response is never retried. When
  the retry fails too, or the server answers `invalid_grant`, the connection
  asks for authorization again as before.
- Say why authorization is required again. The `user_action` for
  `invalid_client`, `refresh_token_expired`, `refresh_token_revoked`,
  `refresh_outcome_unknown`, and a bare `invalid_grant` starts with one fixed
  sentence, followed by the client-specific action. For `invalid_client` the
  action is to remove the Stonewright server from the AI client and add it again
  from **Stonewright > Setup**, because a sign-in alone cannot restore a
  registration the site no longer has. `reason_code` is unchanged.
- Update `smol-toml` to 1.9.0 and the locked `proxy-addr` to 2.0.8.
- Test runs on Windows remove their OAuth fixture folders at the drive root
  when the run ends, and remove folders of earlier runs that are older than 24
  hours.

### Fixed

- Correct the documented retry behaviour of the companion: it sends each
  WordPress MCP request once and does not repeat it after a timeout or network
  error; on OAuth connections an HTTP 401 refreshes the access token and sends
  that request once more, a tool call included.
- Refresh runtime dependency floors and security overrides, and use a patched
  test-runner version.
- Wait up to one second, instead of a quarter of a second, for a Windows OAuth
  token file that another program holds open for a moment, such as a virus
  scanner or an indexer, before the privacy check gives up. Privacy is still
  rechecked before every attempt, and permission, ownership and other errors
  still fail closed at once.

## [1.0.0-beta.13.3] - 2026-09-17

### Fixed

- Classify plugin MCP routes from GET evidence: HEAD 404 is not missing,
  WordPress `rest_no_route` JSON is missing, HTML/CDN 404 is inconclusive, and
  401/403 means the route exists. Plugin-only never falls back to Direct.
- Publish `endpoint_evidence` on connection status, keep `transport.mcp_url` as
  the configured plugin MCP URL, and set Direct `url` to the REST index.
- Reject invalid WP-CLI `--url` values before spawn with
  `stonewright_wp_cli_url_invalid`.

## [1.0.0-beta.13.2] - 2026-08-26

### Fixed

- Align companion package version with the plugin 1.0.0-beta.13.2 patch so
  WordPress and companion stay on the same SemVer after release.

## [1.0.0-beta.13.1] - 2026-08-26

### Fixed

- Align companion package version with the plugin updater-discovery patch so
  WordPress and companion stay on the same SemVer after release.
- Force at most one uncached GitHub lookup per request during an update cycle
  so a later transient write cannot rate-limit or wipe a successful discovery.

## [1.0.0-beta.13] - 2026-08-25

### Added

- Add connection status schema version 3 with truthful authentication state,
  including `reauth_required` and a model-visible `user_action`.
- Add one shared Setup client tablist for OAuth and Application Password,
  including a Grok Build / CLI catalog entry.

### Changed

- Reconnect a degraded session once from `stonewright-task-start`, preserve the
  last good catalog, and never silently enable Direct writes from a plugin
  transport failure.
- Restrict automatic retry to handshake and allowlisted read-only bootstrap;
  mutations are never retried.

### Fixed

- Surface terminal OAuth reauthorization to clients instead of generic
  transport errors.
- Preserve OAuth session continuity across refresh rotation and recover
  degraded plugin sessions without dropping transport failure evidence.
- Serialize OAuth refresh so concurrent clients cannot race grant rotation.

### Security

- Harden OAuth grant-family rotation and replay revocation.

## [1.0.0-beta.12] - 2026-08-24

### Fixed

- Contended Direct state-lock acquisition no longer re-resolves the lock owner's
  process start identity on every retry, which made lock waits slow on macOS.
- Kill leaked child writer processes after concurrent Direct audit and incident
  stress tests so one timed-out test cannot poison the rest of the suite.
- Preserve Elementor CSS metadata during Direct data writes and never run the
  site-wide Elementor `flush-css` command; report that guarded target-post CSS
  closure still requires Plugin mode.
- Audit Direct safety denials before tool execution with the same lifecycle and
  operation classifications as Plugin mode.
- Recover interrupted idempotency markers and rotate Direct audit logs under a
  process lock without losing appends or carrying legacy secrets into archives.
- Require the central Direct write gate as well as explicit confirmation for
  theme activation, plugin deletion, user deletion, Application Password
  revocation, and skill deletion.
- Bind terminal audit idempotency to canonical site identity, reject cross-site
  receipt replay, recover stale malformed locks without stealing live locks,
  and compact marker retention under the audit lock.
- Audit ordinary REST read failures exactly once from asynchronous dispatch
  metadata even when the thrown REST error has no explicit tool metadata.
- Bind the Direct task-start write latch to both alias and canonical target
  identity, invalidating it immediately when an alias is repointed.
- Use canonical target identity—not aliases—for terminal idempotency and
  incident storage, and quarantine stale locks with boot/process ownership
  checks before removal.
- Serialize stale-lock recovery behind an exclusive ownership-checked mutex so
  a replacement live lock cannot be renamed at the recovery boundary.
- Make Direct incident failure, resolution, and learning updates atomic across
  processes, and bound stale recovery/release quarantine cleanup.
- Cap Direct lock quarantine artifacts at 32 regardless of age, keeping the
  newest files and removing lock and recovery-mutex leftovers.
- Reject stale Direct repair resolution and learning when a newer failure
  changes the incident generation, update-time, or occurrence token.
- Return the authoritative terminal audit receipt even when incident storage
  fails, with one bounded secondary error and no duplicate fallback append.
- Validate any inspectable lock PID by its real process-start identity and bound
  unknown, remote-host, or decoy ownership with host, boot, age, and lease data.
- Honor browser-finalizer `retryable:true` response bodies even on HTTP 409 and
  resume bounded polling instead of treating the result as terminal.
- Reject duplicate or ambiguous Codex TOML command/args assignments and
  non-string argument members without mutating the file.
- Parse the entire Codex TOML document before and after package replacement,
  rejecting missing commas, duplicate definitions, and malformed unrelated
  sections without changing configuration or recording a restart receipt.
- Serialize client-config and registry-receipt updates under one lock, and only
  roll back a config whose current hash still matches the updater's own write.
- Add a per-config exclusive lock plus an immediate pre-rename hash recheck for
  Codex TOML and generic JSONC writes; snapshot rollback also rejects drift.
- Require explicit successful results from all four runtime verification calls;
  malformed results and fallback values cannot produce a valid receipt.
- Record a required active-host call as a sequence step when that gateway ran
  with schema version 2 and non-error MCP content. Status, relist, mismatch, and
  setup `ok` flags stay separate truthful signals and do not hide plugin
  validation failures.
- Print previous and new package/version plus prefix/suffix invariance hashes in
  `connect update` JSON, without backup paths, credentials, or config text.
- Generate client OAuth, default-profile, and relist semantics from the plugin's
  authoritative catalog and enforce parity in the end-to-end contract test.
- Record restart-verification calls only after successful handlers and enforce
  `task-start` → `setup-profile` → `wordpress-mcp-status` →
  `client-surface-check`; caller-provided tool-name lists no longer attest a
  client catalog.
- Preserve plugin task-start failures, remove the invalid site-alias
  translation, prefer authoritative plugin mode/surface state, and fail startup
  while the active client catalog is stale even when the refresh list is empty.
- Consume schema-v2 WorkflowPreflight saved/effective mode fields from the real
  plugin payload and block startup on unsupported schemas or mode mismatch.
- Serve running and expected package truth from the real health endpoint and
  expose configured-package truth only to an authenticated request backed by a
  validated source.
- Resolve `chatgpt-desktop` through the Codex TOML adapter and catalog metadata.

### Security

- Redact nested private keys, PEM certificates, and credential blobs from
  rotated Direct archives without removing surrounding safe text, and keep
  encoded archive output bounded.
- Clear inherited WordPress credential variables before an explicit site alias
  is resolved, and fail startup when that alias is unknown.
- Allow `env://STONEWRIGHT_WP_APP_PASSWORD` for the selected alias by resolving
  it from a protected pre-clear snapshot without retaining unrelated stale
  credentials.
- Replace self-signed restart proofs with one-time, expiring active-client
  attestations bound to private registry key material, exact package
  provenance/version, config hashes, restarted process, and a process-bound
  catalog observation.
- Validate installer-managed TOML and JSONC entries as exact official
  `npx`/`npx.cmd --package <Stonewright package> stonewright-mcp` commands before
  updating them, while preserving unrelated bytes.

## [1.0.0-beta.11.1] - 2026-08-24

### Changed

- Align release-consumed package metadata with the beta.11.1 updater migration
  bridge; companion behavior is unchanged.

## [1.0.0-beta.11] - 2026-08-22

### Added

- Add semantic DesignSpec motion validation for target resolution, global
  motion IDs, hover/focus parity, loop controls, provider identity, and stagger
  arithmetic so companion and plugin contracts reject the same invalid plans.
- Add local WP-CLI command recipes v1: save parameterized recipes per site,
  plan them, and run them through tokenized `execFile` argv with stop-on-error,
  bounded redacted output, expectation verification, and one-use plan approval
  for write recipes. Exposed as exactly three MCP tools
  (`stonewright-command-list`, `stonewright-command-get`,
  `stonewright-command-run`) on the wp-cli, site-admin, full, and
  discover-execute profiles only.
- Add the `stonewright command` CLI subcommands add/list/show/remove/plan/run
  with exit codes 0 (verified success), 1 (failure), and 2 (write plan valid,
  approval required).
- Add `stonewright connect add|repair --wp-root <path>` to bind a canonical
  local WordPress root (real directory containing wp-config.php) used by
  command recipes; the runner revalidates it before every run.

- Add Gutenberg block-finalizer tools to the companion gutenberg profile catalog.
- Add a `discover-execute` companion profile catalog for the three protocol tools.

### Changed

- Alias `codex-cli` to the existing Codex TOML adapter so CLI connect commands
  keep working after the ChatGPT Desktop vs Codex CLI split.
- Split paste-to-agent `--profile` from `--wp-surface` so compact versus full
  surfaces stay explicit.
- Persist the MCP surface from Apply now and report when a session has widened
  beyond compact.
- Align the companion package version with plugin 1.0.0-beta.11.

### Fixed

- Separate Codex Desktop from Codex CLI in Connect and dedupe generated MCP
  server names.

## [1.0.0-beta.10] - 2026-08-12

### Added

- Add a private per-site Direct incident store and
  `stonewright-incident-repair-record` with strict correlated evidence,
  readback verification, recurrence handling, and compact task-start actions.

### Changed

- Keep audit-derived learning guidance-only until a successful repair is
  proven; stale a promoted lesson when the same failure recurs.

## [1.0.0-beta.9] - 2026-08-12

### Fixed

- Use the plugin-resolved profile catalog for expected counts, missing-tool
  diagnostics, and `refresh_required_tool_names` instead of comparing a live
  connection with a potentially newer companion fallback list.

## [1.0.0-beta.8] - 2026-08-12

### Fixed

- Accept `playwright` as the CLI alias for the recommended external browser.
- Emit safe runtime verification fields and fail closed when status reports
  non-empty `refresh_required_tool_names`.

## [1.0.0-beta.7] - 2026-08-12

### Fixed

- Replace line-by-line client configuration diffs with content-free change
  summaries and omit private config and backup paths from connect receipts.

## [1.0.0-beta.6] - 2026-08-12

### Changed

- Add `connect repair <alias> --mode direct-only|plugin-only|auto` so an
  existing site can change policy and refresh its named client entry while
  reusing the stored credential.

### Fixed

- Make an explicit `STONEWRIGHT_SITE_ALIAS` override stale inherited WordPress
  URL, username, and password values before runtime mode selection.
- Deduplicate legacy v1 aliases by canonical URL and environment during secure
  migration, preserving the configured default or first stable alias and never
  persisting the read-only v1 projection as schema v2.

## [1.0.0-beta.5] - 2026-08-12

### Added

- Add `stonewright connect add/list/use/verify/repair/remove/migrate` with a
  secret-free schema-v2 site registry, OS credential stores, unique aliases,
  client adapters, and spawned-runtime verification.
- Store per-site mode/Step 1 expectations and per-client browser provider,
  scan-consent, and install-consent state without silently scanning or installing.
- Add the short `stonewright` CLI binary while retaining the existing
  `stonewright-companion` and `stonewright-mcp` entry points.

### Changed

- Make site, credential, and client-config changes transactional: duplicate
  endpoints fail before secret writes and registry failures restore the exact
  client config and credential state.

### Fixed

- Make task-start profile expansion register callable tools before notifying
  clients, and make repeated Plugin/Direct reconnects reuse existing tool and
  prompt handles instead of failing on duplicate registration.
- Report authentication as configured only when complete credential evidence
  exists and leave unprobed Direct WordPress reachability unknown.

## [1.0.0-beta.4] - 2026-08-05

### Added

- Add atomic OAuth token storage, refresh-token rotation, single-flight
  refreshes, terminal reauthorization handling, bounded transient retries,
  `Retry-After`, jitter, and circuit breaking.

### Fixed

- Treat a successful refresh response that omits a new token or replays the
  previous refresh token as terminal reauthorization instead of reusing a
  credential the server has already rotated.
- Send the canonical MCP `resource` during token refreshes, route OAuth traffic
  through the dedicated resource endpoint, and update vulnerable transitive
  dependencies to patched releases.

## [1.0.0-beta.3] - 2026-07-31

### Changed

- Clarify that local stdio starts the companion on the user's computer, while
  Remote Streamable HTTP connects directly to the plugin.
- Keep Direct mode custom CSS readable where core exposes it, but block writes
  because pluginless mode has no authenticated wp-admin one-time-grant
  boundary.
- Mirror the human custom-code handoff rule from Plugin mode.
- Mirror the immutable Elementor write-closure rule and align the companion
  package with the beta.3 plugin release.

### Fixed

- Local WP-CLI Elementor writes now remove the target post's element and CSS
  cache metadata after verified readback and report browser verification as
  still required.
- Remote Direct writes report Elementor PHP cache/frontend checks as
  `not_checked` instead of claiming closure they cannot perform.

## [1.0.0-beta.2] - 2026-07-30

### Changed

- Align the companion package version with the beta.2 plugin release. Direct
  mode behavior, private state, rules, and update-preservation guarantees remain
  unchanged.

## [1.0.0-beta.1] - 2026-07-30

### Changed

- Add plugin-mode profile routing for all native WooCommerce catalog abilities.
- Keep Direct mode WooCommerce read-only and state that boundary in the
  capability matrix.
- Align package version and public-hygiene release gates with the first beta.
- Keep the shared native-rule registry and digest-based reads on the Direct
  bootstrap surface.
- Accept canonical `appPassword` Direct config while preserving the legacy key.
- Write `~/.stonewright` state with restrictive permissions and emit a
  credential-free MCP config from `stonewright-companion init`.
- Make `stonewright-task-start` the first Direct call, emit mode-aware
  handshake guidance, and stop reporting Direct memory as plugin-only.
- Replace supplied passwords and authorization values with private
  placeholders in setup-profile output.
- Redact credential material from Direct audit messages and reject secrets in
  locally persisted memory and user-created skills.
- Preserve Direct memory, user skills, and audit state across restarts and
  companion updates; fresh state starts without user records.
- Mirror the expanded immutable Plugin rule registry in Direct mode so
  site-independent Elementor, content-model, asset, query, custom-code,
  architecture, and rendered-proof guidance never depends on site memory.
