# Changelog

## [Unreleased]

### Added

- Nothing yet.

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
