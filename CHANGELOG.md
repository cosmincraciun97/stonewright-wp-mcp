# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

### Added

- `stonewright/theme-file-patch`, and the `theme-file` provider of
  `stonewright-custom-code-provider`, take `mode: replace_string` with an
  `old_string` that occurs exactly once in the file. That text is replaced
  byte for byte, so a change inside one line no longer needs markers.
  - A missing, empty or repeated `old_string` is refused
    (`stonewright_theme_file_old_string_missing`, `_required`, or `_ambiguous`
    with `matches`). Overlapping occurrences count as repeats.
  - `old_string` is never written to the audit log.
- `placement: exact` makes the other patch modes write the content byte for
  byte. Nothing is added after the marker or before `end_marker`, trailing
  spaces are kept, and line endings stay as sent. As a result:
  - an edit inside one line works with `replace_between_markers`;
  - sending the current block back reports no change and needs no approval;
  - the approval page shows only the lines that change, instead of every
    following line shifted.

  `placement: block`, the default, keeps the bytes it produced before, so
  proposals and grants already issued stay valid.
- `stonewright-acf-field-group-get` returns the post IDs of the group and of its
  fields (`group.ID`, `fields[].ID`), whether ACF also keeps the group in a JSON
  file or in PHP code (`group.local`), and the other posts that hold the same key
  (`group.duplicate_post_ids`). `include_settings: true` returns the full group
  array and the full field arrays (`parent`, `sub_fields`, `layouts` and every
  setting), capped at 200 fields or 256 KiB; above the cap the answer is
  `truncated: true` with the compact fields and `field_keys`, and `field_key`
  returns one field in full.
- `stonewright-acf-field-group-save` answers with `post_id`, `created`,
  `fields_created`, `fields_removed`, `created_post_ids`, `duplicate_post_ids`
  and `local`, and the contract mirror it keeps stores the payload with the
  resolved post IDs.

### Changed

- Removing fields from an ACF field group needs `allow_field_removal: true` on
  `stonewright-acf-field-group-save`. ACF deletes a field the payload leaves out
  permanently, so such a save is refused with
  `stonewright_acf_fields_would_be_removed` (the field keys in the error) and
  nothing is written until the call allows it. The input and output schemas of
  `stonewright-acf-field-group-save` and the input schema of
  `stonewright-acf-field-group-get` changed; names, permission, backup, token,
  validation and audit gates are unchanged.

### Fixed

- The `theme-file-patch` schema described `replace_between_markers` as
  "inclusive", but both markers have always stayed in the file. The schema now
  says so and describes where block placement puts the content: a newline after
  `start_marker`, the content with one trailing newline, and another newline
  before `end_marker`.
- A theme-file write on a slow host is no longer rolled back, and its grant spent,
  for a check the host could not pass anyway. The health check after a write asks a
  check that passed before the write once more when it gets no answer; that one retry
  was skipped whenever a check that never passed (a wp-admin screen that times out on
  every request) had used up the time budget. Only the checks that can be judged count
  against it now, so a probe and its retries take at most 45 seconds instead of 30. A
  page that took longer than 5 seconds to answer before the write is a slow witness:
  its silence after the write is not held against the write, the evidence says so
  (`witness: slow` with `baseline_ms`), it is not asked a second time, and Check again
  no longer requires it; a failing answer still fails at once. The threshold is the
  filter `stonewright_rescue_probe_witness_ms` (1 to 30 seconds). The result of a write
  that was kept adds `verification_reason` and `warnings` for the checks that reached no
  verdict; a rolled back write adds `verification_reason`, `post_rollback_reason` and
  `attribution` (`attributed`, `unattributed` or `unknown`) to its error and its
  evidence. When a theme-file or `theme.json` write was rolled back and the lost check
  is still lost with the original file back (`unattributed`), the custom-code grant it
  ran under is given back once (`custom_code_grant_status` `reusable_once`): the same
  bytes can be applied once more with the same grant before it expires. Every other
  rollback spends the grant as before, and the repair hint no longer asks for a new
  grant when the same one still applies.
- Save an ACF field group by key as an update of the posts that already hold it.
  `stonewright-acf-field-group-save` handed the payload to ACF's importer as it
  came, and without the post ID of the group ACF inserted a second
  `acf-field-group` post with the same key and a second copy of every field, so
  the edit screen showed two metaboxes and a field name resolved to either
  field. The save now resolves the group and its fields by key, so IDs are
  optional; a payload ID that belongs to another group or field is refused
  (`stonewright_acf_group_id_mismatch`, `stonewright_acf_field_id_mismatch`), a
  key that two posts hold is refused until the call names one with `group.ID`
  (`stonewright_acf_group_key_ambiguous`), and a group registered in PHP is
  refused (`stonewright_acf_group_registered_in_code`). A group that exists only
  in local JSON is created, and the answer says so (`local`).
- Record every post an ACF field group save inserts in the change ledger. The
  ledger compared ACF's answer before and after the import, which names one post
  per key, so a duplicate group and its fields had no created row and a rollback
  could not remove them. The save now listens to `wp_insert_post` during the
  import and records each group or field post it inserts.

## [1.0.0-beta.18.1] - 2026-11-06

### Fixed

- The companion sends `MCP-Protocol-Version` with the negotiated protocol
  version on every request after `initialize`, as MCP 2025-06-18 requires. On a
  site where another active plugin ships a newer `wordpress/mcp-adapter` (0.7
  or later, for example the copy in Gravity Forms 3.2.2), Jetpack Autoloader
  serves the Stonewright route with that copy, which refuses
  `notifications/initialized` without the header: the companion connected with
  no WordPress tools and reported `WordPress MCP HTTP 400 during initialize`.
- When the site answers HTTP 404 to a request that carried an `Mcp-Session-Id`
  (the session ended, for example after 24 hours without use or when one
  account holds more than 32 sessions), the companion opens a new session and
  sends that request once more, as the MCP Streamable HTTP transport requires.
  A WordPress `rest_no_route` 404 is not treated as an ended session, and a
  request is never sent a third time.
- The companion accepts the protocol version the site chooses at `initialize`
  when it speaks it (2025-06-18, 2025-11-25 or 2025-03-26) and otherwise stops
  with `protocol_version_unsupported` and an update hint.
- A plugin route that answers `initialize` but refuses the rest of the
  handshake is reported as `plugin_handshake_rejected` by
  `stonewright-wordpress-mcp-status` and `stonewright-connect-doctor`, with a
  next action that points to Stonewright > Troubleshoot > MCP runtime. It was
  reported as `plugin_unavailable`, and the doctor advised installing the
  plugin.

## [1.0.0-beta.18] - 2026-10-26

### Added

- Keep a masked, size-capped copy of the code `stonewright/php-execute` ran, so
  an administrator can see what ran after an incident. The audit row still holds
  `code_sha256`, the hash of the code as it ran, and now also `code_ref`,
  `code_bytes` and `code_truncated`. Passwords, tokens, API keys, authorization
  headers and WordPress keys and salts are masked, the copy is cut at 256 KiB, and
  a call a guard refused is kept too. Administrators read it under Audit log >
  View code. The daily audit retention job deletes copies older than the
  retention window, and a full data removal deletes the folder. The setting
  **Keep the code php-execute runs** (on by default) or the constant
  `STONEWRIGHT_PHP_EXECUTE_KEEP_CODE` set to `false` keeps only the hash, and
  only a person in wp-admin can turn the setting off.
- Add guard rails for busy production sites. They warn by default; a refusal is
  always a setting an administrator turns on. See `docs/busy-sites.md`.
  - A site pressure signal: one timed `SELECT 1`, with the machine load and the
    memory in use, read only when optional work is about to start and reused for
    30 seconds. While the level is `elevated` or `critical`, the lesson
    migrations and the rescue helper check wait, and the health probe asks for
    the front page alone. At `critical` schema repairs and the change journal
    rewrite wait as well, and any probe is one short request. The memory a
    request uses counts for that request only. Ability responses carry
    `site_pressure` with the level and a hint to batch writes. The thresholds
    are filterable (`stonewright_site_pressure_thresholds`).
  - A write freeze set in wp-admin under Setup > Settings > Busy sites: a time and a
    reason. While it is on, abilities that change the site answer
    `stonewright_write_freeze` (status 423) with the end time and the reason; reads,
    the task start, and the rollbacks and restores of the change history keep
    working. Only a person in wp-admin lifts the freeze or ends it sooner.
  - Elementor write pacing: saves of one document are counted in a ten-minute
    window, whoever makes them. The response of the third save carries
    `write_pacing` with a hint to use one `elementor-v3-batch-mutate` apply. The new
    setting `stonewright_elementor_write_pacing` is `warn` by default; `strict` also
    refuses the sixth save with `stonewright_write_pacing` (status 429, with
    `retry_after`), and `off` counts nothing. Editor autosaves are not counted.
    Only a person in wp-admin makes it looser.
  - Cache-purge advisories: with LiteSpeed Cache active and its Auto Purge Rules
    set to purge all pages on a save, or to purge everything on an update,
    `stonewright-task-start` (`site.cache_purge`), `stonewright-site-pulse`, the
    plugin and sandbox activate and deactivate abilities (`cache_purge`) and a notice
    after an Elementor, kit or template write tell the agent to batch its changes.
    Stonewright does not change the cache rules.
- Abilities whose output gained fields: every ability response may carry
  `site_pressure` and `write_pacing`; `stonewright-task-start` and
  `stonewright-workflow-preflight` carry `site.cache_purge`; `stonewright-site-pulse`,
  `stonewright-plugin-activate`, `stonewright-plugin-deactivate`,
  `stonewright-sandbox-activate` and `stonewright-sandbox-deactivate` carry
  `cache_purge`. The permission gate changed: write abilities are refused during a
  freeze (rollbacks and restores of the change history are exempt). Backup, token, validation and audit
  gates are unchanged, and a change of the freeze or the pacing setting is audited.
- `wp stonewright cleanup` removes the runtime state Stonewright rebuilds on its
  own (locks, back-offs, cached verdicts, the rescue helper's remembered state,
  the site pressure measurement, and Elementor editor baselines and save counts) from the command line, and is safe on a live
  site. With `--all-data --yes` it also removes every Stonewright table, option,
  transient, scheduled event and state file, as `STONEWRIGHT_REMOVE_ALL_DATA`
  does at uninstall. It deactivates the plugin first, so the plugin does not
  create its tables and keys again, and it does not delete the plugin files or
  the rescue helper. On a multisite network it acts on the selected site, or on
  every site with `--network`. It is for a host where deleting the plugin from
  wp-admin runs into a resource limit and `wp-config.php` cannot be edited.

### Fixed

- Stop checking Stonewright's database tables on every request. Each store
  looked at its table on each page load, which cost queries on every front-end,
  wp-admin, and REST request, and many requests at once could try to repair the
  same table together. The stored schema version is now trusted. A table is
  repaired under a lock, and a failed install is not tried again for ten
  minutes. A table that lost a column is still repaired on activation, on a
  version change, once a day, and on the first write that fails with a schema
  error.
- Keep the options Stonewright reads while it starts in WordPress's autoloaded
  set, so they arrive with the other autoloaded options in one query instead of
  one query each on a site without a persistent object cache. This covers the
  table schema versions, the dangerous-function notice, the registered widget
  slugs, the widget scan verdicts, the locked domain with its mismatch record,
  and the name of the change journal file. The mismatch record and the journal
  file name do not exist on a healthy site, and a missing option costs a query
  too, so both are now stored empty and clearing the mismatch record empties it
  instead of deleting it. An existing site is switched on its next version
  change or when the plugin is activated again.
- Run the upgrade steps that follow a plugin update in one request at a time,
  so concurrent requests on a busy site do not each repeat them, and keep a
  table repair that fails from stopping the update or an activation.
- Run the two lesson migrations once on upgrade and then once a day, with at
  most five attempts, instead of scanning the memory table on every request. An
  attempt that a resource limit killed counts as an attempt.
- Cache the MCP compatibility check for an hour per set of active plugins
  instead of repeating it on every request.
- Resolve the OpenSSL configuration for OAuth key generation only when a key is
  generated, and skip paths outside `open_basedir`. Before, every request
  probed those paths, and on a server with an `open_basedir` limit that logged
  the same warnings again each time.
- Scan a generated Elementor widget once per version of its file instead of on
  every request. A rejected widget is reported once, as one agent notice and
  one log line, not on each request, and a cached verdict is dropped when the
  plugin or its widget rules change.
- Import the change journal file once per change, under a short claim, so
  concurrent requests do not import it together. A request waits at most a
  quarter of a second for the file lock, and a file that stays over 1 MB is
  remembered by its size and modification time instead of being rewritten on
  every admin and REST request.
- Check the rescue helper's integrity every twelve hours, at once when its size
  or modification time changes, and when the plugin version changes or after an
  update, instead of reading and hashing it on every wp-admin request. A repair
  that failed is tried again after five minutes.
- Page `tools/list` by byte budget with MCP cursors, so a site whose tool surface
  passes 1 MiB (for example the `full` profile with Elementor Pro and WooCommerce)
  still connects. The companion read at most 1 MiB of a response and reported a
  longer `tools/list` as an HTTP 200 failure, so `stonewright-reconnect` failed
  although the credentials worked. A page now holds at most 768 KiB of tools, and
  a client that does not send a cursor still gets the whole `essential` surface on
  one page. A stale cursor is answered with `-32602` and the message
  `Invalid cursor`. `STONEWRIGHT_MCP_TOOLS_LIST_PAGE_BYTES` or the
  `stonewright_mcp_tools_list_page_bytes` filter sets the budget, and `0` turns
  paging off. `composer tokens:measure` also reports each surface's
  `tools_list_bytes`, `largest_tool` and `pages_at_default_budget`.
- The companion follows `nextCursor` when it lists tools, names a response body over
  its 1 MiB read cap instead of reporting an HTTP 200 failure, and
  `stonewright doctor` prints `tools_list_pages` and `tools_list_bytes` and the fix
  when a body passes the cap.
- Stop the change journal import, the rescue helper check and the audit, change
  history and OAuth clean-up events from failing on a file that disappears while
  their request runs, as when the plugin folder is deleted, updated or renamed.
  These hooks now check that the classes they need are still on disk and return
  when they are not. Before, the autoloader failed on the missing file and
  WordPress mailed a fatal error for each request that had started before the
  deletion finished. Other late hooks of the plugin still load their classes
  when they run, so deactivate the plugin before deleting it.
- Remove the plugin's runtime state on deactivation and on every uninstall, with
  or without `STONEWRIGHT_REMOVE_ALL_DATA`: lock rows, schema back-offs, the
  cached compatibility and widget verdicts, the rescue helper's remembered
  state, the loopback probe cool-down, the site pressure measurement, and
  Elementor editor baselines and save counts. The
  scheduled events are unscheduled on every uninstall too, on every site of a
  network. Settings, tables, OAuth clients and keys, memory, skills and the
  audit history stay unless the constant asks for their removal.
- Keep Elementor editor baselines as transients that expire seven days after
  they were last written, instead of options that stayed in `wp_options` for
  every post and user who ever opened the editor. A baseline that expired counts
  as missing, so an editor tab left open for more than a week is no longer
  refused as stale. Baselines stored by an earlier version are read once and
  moved.

### Security

- Companion: update the shipped dependencies that `npm audit` flagged
  (body-parser 2.4.0, fast-uri 3.1.9, ip-address 10.7.5, zod 4.6.7; the
  advisory ids are in the companion changelog). The tool contracts and token
  budgets are unchanged.

## [1.0.0-beta.17.2] - 2026-10-22

### Fixed

- Let a custom-code apply change an active WPCode PHP snippet on a site that also
  has active CSS, JavaScript, HTML, universal, or text snippets. The check before
  the write parses the active PHP snippets together, and it took every snippet
  for PHP: the snippet list read the code type from a post meta WPCode does not
  write. The apply then stopped with a syntax error in a snippet it did not
  change. The list now takes each code type from WPCode, so the `list` action of
  `custom-code-provider` reports CSS, JavaScript, and HTML snippets as such, and
  only snippets of WPCode's `php` type are parsed.
- Run that check in the custom-code dry run of a WPCode snippet too. A change the
  apply would refuse is now refused before an approval is asked for, not after
  the grant is issued.
- Keep the backslashes in the code of a WPCode snippet when Stonewright saves it
  or rolls it back. The code reached WordPress unslashed, so escape sequences
  such as `\n` and `\u{00A0}`, regular expressions, and namespaced names lost
  their backslashes. The readback then did not match and the write was rolled
  back, and when the previous code had backslashes, the rollback lost them too.
- Keep the success response of a write valid when `stonewright_fields` names a
  path inside an object, such as `change_set.change_set_id`. The projection kept
  only the named keys of that object and dropped the ones the output schema
  requires, so WordPress answered "invalid output" for a write it had already
  saved, and a client that retried could apply the change twice. Every object a
  path passes through now keeps the keys its schema requires, and a projection
  that would still not match the output schema returns the full response.
- Store complete Custom Post Type UI entries from `cpt-register`,
  `taxonomy-register` and `content-model-loop-grid-flow`. An entry held only the
  few settings the call named, and CPT UI reads most settings without checking
  that they exist: every request logged PHP warnings (`Undefined array key …`),
  enough to fill a site's `error_log` with gigabytes, and each missing setting
  counted as false, so a type registered as public came back on later requests
  hidden from wp-admin, without permalinks and without a query var. A stored
  entry now holds every setting CPT UI reads, with the values WordPress gives
  the type the ability registers, and keeps the settings it already had. An
  incomplete entry from an earlier version is completed the next time one of
  these abilities writes it.

## [1.0.0-beta.17.1] - 2026-10-19

### Added

- Show the suggested server name and the MCP server URL inside the setup steps
  of each AI client on **Stonewright → Setup** as highlighted values with a
  small copy button next to them. The hosted apps (Claude.ai, Claude Desktop,
  ChatGPT), Claude Code, Codex, Zed, Gemini CLI, Other MCP clients and clients
  without published steps get them; Claude Code also gets a copyable command for
  signing in over SSH.
- Add a closed **Work account managed by an admin?** section to the OAuth guides
  on **Stonewright → Setup**. It says who can add a custom MCP server on
  organization plans, lists the admin steps with copyable values, shows a
  message for your admin with the server name and URL next to a **Copy message
  for your admin** button, and, where the client allows it, shows how to connect
  from your own computer instead, for example Claude Desktop with an Application
  Password or Claude Code.

## [1.0.0-beta.17] - 2026-10-18

### Security

- Check the tool surface on every way an ability can be run. An ability that runs or
  writes code is refused unless an administrator chose the Full tool surface in
  wp-admin, whether it is called as an MCP tool, through `execute-ability`, the
  REST runner or WordPress's own Abilities API route, and a confirmation token no
  longer lifts that refusal. `security-issue-confirmation-token` issues no token for
  such an ability.
- Validate the input of `POST /stonewright/v1/abilities/run` against the ability's
  schema, as `execute-ability` does.
- Make the Direct task-start and learning-record routes respect the master toggle
  and the list of disabled abilities.
- Read the site mode through one accessor. A missing mode follows the
  environment, as it does on activation, and a damaged or unknown value counts as
  production-safe, so neither can loosen a gate.
- Require an administrator in wp-admin to make the mode less strict, switch a
  disabled ability back on or change feature flags. Requests signed in with an
  Application Password or OAuth can still make the mode stricter and switch
  abilities off. The admin Abilities routes also need a person in wp-admin, and
  feature flags are stored as a short list of on/off values.
- Check custom instructions the way memory is checked before they are saved
  through REST, `system-instructions-set` or a knowledge import.
- Require `edit_posts` to use the MCP servers, on the Application Password route
  and the OAuth route alike. Sites can change it with the
  `stonewright_mcp_required_capability` filter. Subscribers and shop customers
  can no longer connect.
- Return site memory, custom instructions, recurring errors and incident actions
  from `task-start` and `context-bootstrap` only to administrators, as the memory
  and instructions abilities already do.
- `search-query` needs `edit_posts` and lists a post type the caller cannot edit
  only when the type is public. `oembed-resolve` needs `edit_posts`.
  `stock-image-search` needs `upload_files` and encodes the search terms it sends
  to the provider.
- Sign releases. This version ships the release public keys, an active key and
  an offline backup key, and installs an update only when the release's
  `SHA256SUMS.txt.sig`, an Ed25519 signature of `SHA256SUMS.txt`, verifies
  against one of them. The release workflow signs every release and stops when
  it cannot, so each release now has this fourth asset. A site on an earlier
  version installs this one with the checksum check only; every update after it
  is verified.
- Record a GitHub build provenance attestation for the plugin ZIP and the
  companion package of each release. It adds no release asset.

### Changed

- Describe the update package check accurately: the ZIP is compared with the
  SHA-256 in its release's `SHA256SUMS.txt`.
- Leave the PHPStan stub folder out of the plugin release ZIP.

### Removed

- Remove the `/stonewright/v1/sandbox/files` REST routes. Sandbox files are managed
  on the Sandbox Library admin page and with the sandbox abilities on the Full
  surface.

## Older releases

- [1.0.0-beta.16.2](docs/releases/1.0.0-beta.16.2.md)
- [1.0.0-beta.16.1](docs/releases/1.0.0-beta.16.1.md)
- [1.0.0-beta.16](docs/releases/1.0.0-beta.16.md)
- [1.0.0-beta.15](docs/releases/1.0.0-beta.15.md)
- [1.0.0-beta.14](docs/releases/1.0.0-beta.14.md)
- [1.0.0-beta.13.3](docs/releases/1.0.0-beta.13.3.md)
- [1.0.0-beta.13.2](docs/releases/1.0.0-beta.13.2.md)
- [1.0.0-beta.13.1](docs/releases/1.0.0-beta.13.1.md)
- [1.0.0-beta.13](docs/releases/1.0.0-beta.13.md)
- [1.0.0-beta.12](docs/releases/1.0.0-beta.12.md)
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
