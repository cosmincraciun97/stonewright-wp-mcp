# Changelog

## [Unreleased]

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

## [1.0.0-beta.16.2] - 2026-10-13

### Added

- Add a **Show in admin menu** switch to **Stonewright → Setup → Settings**. It
  is on by default. Turned off, the Stonewright entry is hidden in the menu on
  the left of the dashboard; every Stonewright page keeps its address, and the
  **Overview** and **Setup** links on the Plugins screen open them.
- Say so on the Plugins screen and the Dashboard when Stonewright is installed
  twice, for example after uploading the ZIP when the installed copy sits in
  another folder: the notice names each folder, its version and the copy that
  runs.

### Fixed

- Let a second copy of Stonewright be deleted on the Plugins screen while the
  other copy runs; the delete stopped with a fatal error. Deleting one copy while
  another stays installed removes nothing: the list of registered widgets, the
  rescue helper and the data stay, also when the deleted copy is an older
  version.

## [1.0.0-beta.16.1] - 2026-10-13

### Added

- Add a **Show in admin bar** switch to **Stonewright → Setup → Settings**. It is
  on by default. Turned off, the Stonewright status badge and its menu are removed
  from the toolbar at the top of the dashboard and the site; the AI abilities keep
  their state and the **AI abilities** switch still turns them on and off.

## [1.0.0-beta.16] - 2026-10-12

### Added

- Refresh the Direct built-in skills when the companion updates. The companion
  package lists the hash of every text it has shipped for each built-in skill in
  `skills-builtin/manifest.json` (`npm run skills:manifest` rewrites it). On
  startup, a copy in `~/.stonewright/skills/_builtin/` that still holds an earlier
  shipped text is replaced with the current one; a copy you edited is left as it
  is, and a missing one is copied. Without a readable manifest nothing is replaced.
- Check the tool names in the bundled skills, the Direct built-in skills, the
  expertise packs and the specializations against the ability and Direct tool
  contracts in a unit test, and check that no skill asks an agent to activate the
  `full` profile.
- Show how long the change history is kept. The History tab of Changes has an
  always-visible line with the limits in force (days, changes and MB of stored
  copies), that older finished changes are removed automatically and that open
  incidents and linked Undo and Redo are kept, and the date and number of changes
  removed by the last cleanup, or "none yet". Administrators get a **Change
  limits** link to the setting. A warning notice says how many changes the next
  cleanups remove and by which date when the finished changes or the stored
  copies are at or over 80% of their limit, or finished changes pass the age limit
  within seven days. It is computed read-only with the plan the cleanup runs, so
  the number matches what would be removed.
- Setup → Settings has a **Change history** section with number fields for the
  three limits (days, changes, MB of stored copies). They write the existing
  options `stonewright_change_ledger_days`, `stonewright_change_ledger_max_changes`
  and `stonewright_change_ledger_max_bytes` through the settings form, with its
  nonce and the `manage_options` check; a refused request answers 403. The server
  checks the same bounds as the cleanup (3650 days, 100,000 changes, 10 GB): a
  value outside them is refused with a message and the stored value stays. The
  section says that a lower limit takes effect at the next daily cleanup and how
  many changes the limits remove then. There is no unlimited setting.

### Changed

- Say why a client metadata document was refused. The browser still shows the
  general page, but the reason (the host name did not resolve, a non-public address,
  the request failed with its WordPress error code, an HTTP status such as 403 from
  a firewall, a document that is too large, not JSON, with another `client_id`, with
  a shared secret, or an unacceptable metadata field) is logged as
  `oauth_client_document_refused`, and Troubleshoot has a new **OAuth client
  metadata documents** check that shows the latest reason per client host.
- Changes and Rescue are one entry in the sidebar and in the band. The Changes
  page has two tabs: History (the list, the drawer, Undo and Redo; the default,
  `page=stonewright-changes&tab=history`) and Rescue
  (`page=stonewright-changes&tab=rescue`). The number of changes that need
  attention is on the Changes entry and on the Rescue tab. Rescue keeps its own
  address, `page=stonewright-rescue`, which still works by itself (the health
  probe, the safe-boot redirect, approval links and old links go to it) and shows
  the same tab bar. The Rescue tab is chosen before the change history is read,
  so it opens when the history is missing or fails.
- Each change on the Rescue page shows the object that changed and what was done
  first. The ability, the client, the ids, the health check evidence and the
  rollback recipe are under Details. The list of recent changes shows only the
  changes the history cannot undo, with their Roll back; the others are on the
  History tab, and the page says how many.
- Open in safe mode stays in the header of the Rescue page, as a row with its help
  text under the button. The Roll back, Check again and View diff actions of a change, and the
  Cancel and confirm buttons of a dialog, are one row of equal buttons that wraps
  on a narrow screen.
- The first press of Roll back on the Rescue page and of Undo or Redo on the
  Changes page opens a confirmation that changes nothing, with or without script.
  Cancel is a link. On the Rescue page the confirmation is a page of its own
  (`page=stonewright-rescue&review=<change set>`) that a dialog replaces when the
  browser supports it. In production-safe mode the server checks the typed phrase
  ROLL BACK as well as the confirmation token, before the token is used. The
  rollback engine decides when a rollback needs the person's confirmation (a later
  edit to the item): the review then has a box and the state it shows, and the page
  passes both on. A refusal says that nothing was changed and returns to the review
  with the current state filled in. A post returns to the page it came from, the
  Rescue tab or the page at its own address.
- The Rescue page says what a health check did in four different ways: it could
  not start, it got no answer it could judge, a check that passed before the
  change no longer answers, or it found the site failing. Not verified no longer
  reads as a broken site or a host problem; the code of the check is under
  Details. Check again reports each of the four.
- The compact `stonewright-task-start` response lists the next tools for the task in
  `fast_path.ordered_tools`, without `stonewright-context-bootstrap`,
  `stonewright-task-start` and `stonewright-tool-profile` for the goal it just
  started. An Elementor build names the live schema and the design evidence plan
  before the write and the result check after it; a text change reads one page or a
  bounded outline and does not load the design flow; skills loading and the
  production-safe confirmation token stay where they apply.
  `stonewright-task-start` stays in the list while the Elementor architecture blocks
  the write, and `stonewright-tool-profile` while a needed tool is not callable yet.
  Empty `matched_skills`, `memory_refs` and `elementor.status.native_hint` are left out
  of the compact response.
- `composer tokens:measure` checks the bootstrap profile: more than 12 tools or more
  than 3500 estimated tokens each fail the gate on their own. The budgets are
  unchanged.
- A refused Elementor batch names every rejected setting. For each one,
  `elementor-v3-batch-mutate` returns the operation, action, `op_id`, element or
  parent, widget type, setting and path, what was received (type and a short
  redacted preview), the live control (type, units, responsive keys, options,
  default), the allowed values, the settings that must be sent with it
  (`requires`) and an example, in `rejected_settings` (`rejected_total` counts
  them). The message names the first three, and a failed operation that is not
  about settings (an unknown element, a breakpoint outside the scope) says why in
  the message. A breakpoint refusal says to add the breakpoint to
  `responsive_scope`. The same detail comes back from the typed Elementor
  writers.
- The Audit Log row of a failed call keeps the message of the failure (up to 500
  characters, credentials redacted). A result that reports a failure without a
  message gets one built from its failed check, its statuses, its first warning
  and the first sentence of its repair. A refused Elementor batch adds the
  element, widget type, setting, expected and received types, `rejected_total`
  and a `rejected_summary` to the row.
- `stonewright-elementor-post-write-verify` finds Elementor V4 elements by the
  markers each kind renders (`data-id`, the `elementor-element-<id>` class,
  `data-interaction-id` for V4 leaf widgets), matching the whole id. Each missing
  element gets a `reason` (`not_in_document`, `not_rendered`, `render_empty`,
  `password_protected`, `not_built_with_elementor`, `no_elementor_data`,
  `cached_html_suspected`, `renderer_failed`), the result lists `missing_elements`
  and a `render_context`, and a failure carries a `message`. A post that is not
  published is still verified, with a note.
- `stonewright-elementor-css-regenerate` answers `ok: true` with a warning that
  names the cause (`delivery_cause`) and the next step when the stylesheet is
  written and verified on disk but an anonymous request to it gets a login
  redirect or page, HTTP 401 or 403, another HTML page, a redirect to another
  page, a 5xx, no answer or another 4xx. A stylesheet URL on another origin (a
  CDN or an offload plugin) is not requested and no longer refuses the call: it
  regenerates and reports `other_origin_not_probed`. A stylesheet that was
  delivered before the write and answers with a 404, a non-CSS body or a redirect
  to another page after it is still rolled back, and an unsafe redirect still
  refuses before any write, now with the kind of redirect and the next step.
- `stonewright-section-reuse-extract` says why a source is refused. A post that
  does not exist and a post the user may not edit get the same answer; a user who
  may edit the post learns `status_not_offered` or `post_type_not_offered` with the
  offered statuses. A locator that matches nothing returns `valid_locators` (up to
  20), `locators_total`, `locators_truncated` and, for a near miss, `closest`.
- A rollback from Rescue, from the `stonewright-rescue-rollback` ability or from
  Changes stops with `stonewright_change_drift` when the item was edited after the
  change, whether the change was verified, never verified or recorded as an
  incident earlier. Nothing is saved, written or claimed, and a confirmation token
  is not used up. Run it again with `force_drift` and the `current_sha256` of the
  dry run as `expected_current_sha256` (both are new inputs of
  `stonewright-rescue-rollback`) to overwrite the edit. `force_drift` without
  `expected_current_sha256` is refused with `stonewright_change_drift_hash_required`
  and changes nothing, in the ability, Changes, Rescue and WP-CLI alike. A change with an incident
  or a failed rollback recorded against it is still recovered without that
  confirmation while a health check fails, and without the change history.
- A rollback of a change that was never verified saves the current state first,
  probes the site before and after, and puts the state back when the rollback
  breaks a site that worked, as the rollback of a verified change does.
- A rollback from Rescue records a rollback row under the change in the change
  history, the same row Undo writes, so Changes lists it and offers Redo; Changes
  no longer writes a second row for an open change. The answer of
  `stonewright-rescue-rollback` carries `mode`, `drift_forced`,
  `rollback_change_id`, `restorable_again`, `history` and `verification_reason`;
  its dry run carries `drift`, `drift_known`, `requires_force`, `current_sha256`,
  `expected_sha256` and `redo_available`.
- Check again closes an incident only when every check that passed before the
  change passes again, with the same single retry of a silent check that a probe
  after a write gets. The answer carries `verification_reason`: `verified`,
  `not_started`, `transport_unavailable`, `check_lost` or `site_failed`.
- `wp stonewright rescue rollback` and `wp stonewright changes rollback` take
  `--force-drift` and `--expected-sha256=<hash>` (a drift override needs both). A
  refused rollback prints the current hash and the flags to use; the command line
  still does not approve a change to code.
- Bring the bundled skills, playbooks and Direct built-in skills up to date with
  the current abilities and rules. They name the change history and Rescue steps
  (`stonewright-change-history-list`, `-change-diff-get`, `-change-rollback`
  with `force_drift` and `expected_current_sha256`), the `approval_url` stop for
  code approvals and code undo, the Direct `confirmation_id`, and the refusal of
  Direct writes on a site where the plugin is active. They state that
  `stonewright/php-execute` is on the `full` profile only, which an
  administrator chooses in wp-admin. The Elementor V3 skill and its examples use
  the kit lists `system_colors`, `custom_colors`, `system_typography` and
  `custom_typography` and `stonewright/elementor-v3-kit-batch-mutate` for the
  system lists. The V4 skill names the Stonewright > Setup > Settings switch and
  the `elementor_mcp_enabled` option. The block-builder skills use the Block queue
  console and its `url`. The skill pack README lists the playbooks and the Claude
  Code install path. The WooCommerce skill lists the typed `wc-*` abilities and
  the delete behaviour of each.
- Remove the instructions for `stonewright-acf-options-page`,
  `stonewright-forms-save`, `stonewright-cf7-update`,
  `stonewright-seo-schema-update`, `stonewright/elementor-add` and
  `stonewright/backups`, which are not abilities, and the descriptions of one
  inspected site from the block-builder skills.
- Offer the expertise packs for V3 documents (`elementor-v3`, `theme-builder`,
  `elementor-pro-dynamic`) on Elementor 3.20 up to 5.0 instead of below 4.0, and
  require WordPress 6.7 or later in every bundled pack. The scoped-snippet pack
  lists `stonewright/custom-code-provider` instead of `stonewright/php-execute`,
  and the write-recovery pack lists `stonewright/change-history-list` and
  `stonewright/change-rollback`.
- Name the typed `wc-*` abilities in the WooCommerce specialization and the typed
  `acf-field-group-*`, `acf-values-get` and `acf-value-update` abilities in the
  ACF specialization before REST or WP-CLI.
- Call the block finalizer page "Block queue" in the connect prompt and in the
  description of `stonewright/blocks-finalizer-runtime`.

### Fixed

- Fix Claude failing to connect over OAuth with "This application is not registered
  with this site, or its client information could not be verified." Claude's
  client metadata document now lists the `urn:ietf:params:oauth:grant-type:jwt-bearer`
  grant, and the document was refused for listing a grant type Stonewright does not
  offer. A client metadata document and a dynamic client registration now keep
  only the grant and response types Stonewright supports (`authorization_code`,
  `refresh_token`, `code`) and ignore the rest; the document must still list
  `authorization_code`, and its `client_id` must still equal its address. The
  stored client and the registration answer list only what is supported.
- Closing the diff drawer on the Changes page keeps the From and To dates, the
  status, the family, the search, Restorable only and the page of the list in the
  address. The change an Undo acted on is named in `source_change`; `from` is the
  start date again, and an old address that holds a change id in `from` is read
  as that change.
- The Undo and Redo dialog of the Changes page works without script: its confirm
  button is not disabled in production-safe mode, the server checks the typed
  phrase, and a refusal that ran nothing returns to the confirmation.
- `stonewright-elementor-css-regenerate` accepts a Windows drive path that Elementor
  reports behind a scheme (`file:///C:/...`) when the target is on the same drive.
  Another drive, a host and path, a drive-relative path and a UNC path are still
  refused.
- Contributor tests: the symlink fixtures skip with a named reason on a Windows
  account that cannot create symbolic links, the `CLAUDE.md` symlink check reads the
  git index when the working tree holds a plain file, and the Direct audit and
  incident privacy checks read the Windows ACL instead of POSIX modes.
- The Elementor settings check accepts valid values it refused: a control that
  hides itself at its default (the heading `size` set to `default`); a font weight
  sent as a number; the empty string, `null` or an empty list for an unset
  control, including a select whose options do not list the empty string; a
  slider or dimensions object with the `custom` unit holding `calc()`, `clamp()`,
  `min()`, `max()` or `var()`; a slider object with only a `unit`; links to
  `tel:`, `mailto:`, app schemes, relative paths, queries and addresses with
  non-ASCII characters, and link objects with only the options being changed;
  media values with an empty `id` or `url`; and `__globals__` and `__dynamic__`
  inside a repeater row. A section copied with `insert_section` is no longer
  refused for those settings.
- A control that is inactive for the settings sent says which settings to send
  with it (`typography_typography` set to `custom` for a font size, `content_width`
  set to `full` for a container width, `_element_width` set to `initial` for a
  custom widget width).
- The state saved before an undo of a kit change covers the same surfaces as the
  snapshot being restored: the V4 global variables, the legacy class list, the
  global classes and default styles read through Elementor's repositories, and
  keys that did not exist when the snapshot was taken. The undo is refused before
  it writes when a surface cannot be read, and a put-back counts as done only when
  the whole saved state reads back.
- The health probe asks for a page at the root of the site, whose address has no
  path, with a token bound to `/`. A page address that is not on the site's own
  origin is skipped before a token is issued for it.
- A restore of options and theme mods reads every value back and is reported as
  failed when it does not match the restore point; a rollback that depends on it
  is not marked rolled back.
- A restore of an Elementor page regenerates the generated CSS of that page. After a
  snapshot restore (`stonewright-change-restore`), a rollback from Rescue, an
  automatic rollback of a write that broke the site, and an undo or redo from the
  Changes page or `stonewright-change-rollback`, the post CSS file is rebuilt from
  the restored data through the guarded path of `stonewright-elementor-css-regenerate`
  (post write lease, CSS directory lease, check of the other CSS files, readback),
  for that post only, and only when the restore changed the document or its page
  settings. When the regeneration does not complete, the content stays restored and
  `css_regeneration` (`status` `not_regenerated`, `reason`, `next_step`
  `stonewright/elementor-css-regenerate`) and `warnings` in the result say so, as do
  the Changes and Rescue pages.

## Older releases

- [1.0.0-beta.15](../docs/releases/1.0.0-beta.15.md)
- [1.0.0-beta.14](../docs/releases/1.0.0-beta.14.md)
- [1.0.0-beta.13.3](../docs/releases/1.0.0-beta.13.3.md)
- [1.0.0-beta.13.2](../docs/releases/1.0.0-beta.13.2.md)
- [1.0.0-beta.13.1](../docs/releases/1.0.0-beta.13.1.md)
- [1.0.0-beta.13](../docs/releases/1.0.0-beta.13.md)
- [1.0.0-beta.12](../docs/releases/1.0.0-beta.12.md)
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
