# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

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

## [1.0.0-beta.15] - 2026-10-12

### Added

- Direct mode checks Elementor whole-tree writes: `elementor-data-get` returns a
  tree `hash` and `architecture`, `elementor-data-update` takes `expected_hash` and
  refuses duplicate ids, a widget at the root or inside a widget, settings that are
  not objects, CSS in a colour setting, oversized trees and raw writes onto Atomic
  (V4) or mixed documents (`allow_atomic_raw` overrides). Destructive Direct calls
  are confirmed with a `confirmation_id` bound to the tool, site and exact
  arguments (10 minutes, one use). See the companion changelog.
- Direct mode lists and restores the copies it saves before it overwrites
  something (`stonewright-direct-backup-list`, `stonewright-direct-backup-restore`;
  hash-checked, `confirm:true`, undoable), reads every typed write back and
  reports a difference as `ok:false`, and can check the public page after a page,
  post or template write, restoring the saved copy only when the call asks for it.
  The Direct tool count is 105. See the companion changelog.
- Accept the plugin's argument names in the 37 Direct tools whose names also exist
  in Plugin mode, so a client that learned one schema works in both. `post_id`,
  `menu_id`, `alt`, `user_login`, `user_email`, `user_pass`, `role`,
  `display_name`, `number`, `blueprint_id`, `page_title`, `mode`, `date_from` and
  `date_to` fill the Direct arguments (`post`, `id`, `alt_text`, `username`,
  `email`, `password`, `roles`, `name`, `per_page`, `id`, `title`, `status`,
  `date_min`, `date_max`); Direct's own names are unchanged. `settings-update`
  takes `blogname`, `blogdescription` and `timezone_string`, and `plugin-*` tools
  take the plugin file with or without `.php`. A name and its alias given with
  different values are refused with `argument_alias_conflict` and nothing is sent;
  an argument only the plugin has and Direct cannot honour (`palette_override`,
  `brand_kit`, `base64`, `items`, `tags`, `statuses`, `max_orders`, the plugin-only
  learning fields) is refused with `plugin_only_argument` instead of being dropped.
  Comment status takes `approve` as well as `approved`. A required argument may
  come under either name.
- Add the plugin's output keys beside the Direct ones where the value is the
  same: `url`, `mime` and `alt` on media (and `parent`, `width`, `height` on
  `media-get`), `menu_id` on `menu-create`, `menus[]` on `menu-list`, `active` on
  plugin and theme items, `login` and `display_name` on users, `count` on
  `blueprint-list`, and `blogname`, `blogdescription`, `timezone_string` on
  settings. `media-list` takes `mime_type`, `user-list` takes `offset`,
  `blueprint-list` takes `industry` and `search`, `settings-get` takes `keys`,
  `content-create-page` and `-post` take `excerpt`, `slug` and `date` (and the post
  `categories`), `media-upload` takes `parent_post_id`, and `memory-list` takes
  `offset` and up to 500 entries.
- Take the plugin's `period` values (`last_7_days`, `last_30_days`,
  `year_to_date`, `custom` with `date_from` and `date_to`) in Direct
  `wc-sales-report` and turn them into the date range WooCommerce takes. The
  WooCommerce period values still pass through.
- Report which store answered. `task-start` and `learning-record` carry a common
  core of keys and a `backend` field (`direct` or `plugin`) whichever store
  answered (the Plugin-mode `task-start` gateway adds `backend: plugin` to the plugin's answer), and `memory-list` says it read the companion's local files (`backend`,
  `memory_backend`) and, when the plugin is active on the site, that `task-start`
  and `learning-record` use the plugin's memory there. The table of differences
  for the shared tools is in `docs/direct-mode-e2e.md`.
- Purge the cached page of a post after `stonewright-elementor-css-regenerate`
  moved its stylesheet version. When the CSS file is written and the transaction
  is not rolled back, the ability calls the purge function or hook of the page
  cache plugins it finds, for that one post only: LiteSpeed Cache, WP Rocket,
  W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Optimizer and the
  Cloudflare plugin, then WordPress core's `clean_post_cache`. The answer lists
  the purgers that ran in `cache_purge.ran` (`skipped_reason` says why none did,
  `failed` and a warning name one that threw). A purge never fails the write.
  The `stonewright_css_regenerate_purge` filter turns it off or adds a site
  purger. The `repair` text now says which caches were already purged before it
  asks for a manual purge of any other host, CDN or page cache.
- Add the change history. A `stonewright_changes` table (created and upgraded
  with the other tables) records each change with its ability, user, resource,
  status and the hash and size of the content before and after it. The content
  is kept as gzip blobs in `uploads/stonewright-state/blobs/`, named by sha256,
  with deny files on every folder, size limits per blob and in total, and an
  integrity check on read. Passwords, keys and salts, OAuth tokens,
  `wp-config.php` and options on a list of secret names are never stored, and
  other credentials found in content are masked; a change whose content before
  or after was masked is not restorable. A daily event keeps 90 days, 500
  changes and 100 MB by default (options and filters change the limits) and
  never deletes an open change. Removing all plugin data also drops the table,
  the event and the blobs. A history that cannot record is logged and never
  stops or changes the write or its result.
- Record what abilities change in the change history, with the content before
  and after: posts (fields, slug, parent, featured image, terms, Elementor keys,
  page template, SEO keys and ACF values), for the abilities that write or
  create posts and for `content-bulk-upsert-posts`, which overwrote posts
  without a snapshot; code (theme file writes through patch, `theme.json` and
  backup restore, Customizer CSS updates with the first save, WPCode and Code
  Snippets saves with the title, language, active state and scope of a snippet,
  and sandbox write, edit, delete, activate and deactivate); options (site
  settings, the front page, custom instructions, custom post types, taxonomies,
  ACF field groups, the tool profile, theme chrome and the brand kit, each
  ability limited to a list of the options it may write, with names on the
  secret list never read or stored); menus with their items in order, parents
  and locations (a deleted menu keeps its full image) and sidebars with their
  widgets; users (account fields, roles and capabilities, never a password,
  session token, application password or other secret user data); comments;
  media (fields, alt text and metadata; an upload is a create); WooCommerce
  products, variations, terms and attributes, with the full content of what a
  delete removes; theme switches; memory deletes (the whole row); and skills
  and design directions as a link to the revision their own stores keep, with
  no second copy. A changed password, a created or revoked application
  password, a plugin delete, a `php-execute` run (only the hash of its code) and
  a settings write from an admin screen or route are recorded as events and
  marked not restorable, with the reason.
- Add the Changes page (**Stonewright > Activity > Changes**, marked EXP). It lists
  the changes recorded in the change history, newest first, with filters for
  family, resource, ability, user, date range and result, and a "restorable
  only" switch. **View diff** opens one change in a drawer: the content before
  against after as lines with line numbers, block changes, Elementor elements or
  changed fields, a note when the diff was cut or values were masked (values
  masked when the change was stored included), and the reason a change cannot
  be restored. A change that deleted a menu or a memory entry shows the removed
  content under a **Deleted** label. The History tab lists the rollbacks and
  redos that follow a change, the redo of a rollback under that rollback, to a
  fixed depth. Passwords, keys and tokens show as `[redacted]`, also when they
  sit inside a value shown as one piece, and every such value counts as masked
  in the note above the diff. A write that was taken back shows a note instead
  of a diff. Rescue links to the page and, for a change that has a history
  record, to its diff.
- Add **Undo** and **Redo** to the Changes page. **Undo this change** opens a
  dialog with the diff of what the undo would change, warnings for a newer
  change to the same item and for an item that was edited since the change
  (which needs a ticked box to overwrite), and the same nonce, administrator
  check and production-safe confirmation token as Rescue. The undo restores the
  item as it was, probes the site, puts the earlier state back if the site
  stops loading, and records a rollback row that links back to the change; a
  rolled-back change offers **Redo**. When the site fails after a restore the
  answer is the same for every kind, `stonewright_change_rollback_reverted`
  with the probe evidence, and the page says the earlier state was put back; a
  theme file restore is taken back by the theme file write itself, which keeps
  its one row and journal entry as rolled back, and nothing is restored
  twice. It covers posts and their kinds, options, theme switches, menus, widgets, theme files, snippets, the Customizer CSS,
  sandbox files, users (fields and roles, never a password), comments, media,
  WooCommerce items, site memory, skills and design directions; the redo of a
  restored memory entry deletes it again. Each undo writes the content back
  through the functions or the path the original write used, so its checks
  still apply; the undo of a created post moves it to the trash, of an upload
  deletes the attachment, and of a created theme file deletes the file. A
  rebuilt menu is checked by what identifies each item, not by item ids, which
  a rebuilt menu does not keep.
  Undoing or redoing code needs an administrator at the page: an agent or
  WP-CLI call gets the approval-required answer. Only a change whose Rescue
  entry is still open (armed, incident or rollback failed) is rolled back
  through Rescue, so incidents are rolled back as before; a verified change that
  Rescue still lists is settled there as rolled back once the undo is kept, and
  stays verified when the undo is taken back. A plugin delete, a password change
  and a `php-execute` run stay not restorable, with the reason.
- Add the change history to agents and to WP-CLI. `stonewright-change-history-list`
  lists changes newest first as short rows (id, time, family, resource, ability,
  user, status, summary, whether it can be restored and why not, the change a
  rollback follows and how many follow it), with the filters of the Changes page
  and paging. `stonewright-change-diff-get` returns the diff of one change, with
  secrets masked, the size capped and `deleted` for a change that removed its
  resource, and a summary of its undo: drift, newer changes, whether it can be
  restored and whether an administrator is needed.
  `stonewright-change-rollback` undoes a change or redoes a rollback, with
  `dry_run`, `force_drift`, `expected_current_sha256`, `permanent` and the
  confirmation token of production-safe mode (a dry run returns the arguments to
  issue it for); an undo of code is not run on a call and answers
  `stonewright_rescue_approval_required` with the address of the Changes page,
  and the text of the error an MCP client receives carries that code and the
  address. None of the three returns stored content. `wp stonewright changes
  list`, `diff <change>` and `rollback <change>` (`--dry-run`, `--force-drift`,
  `--yes`, `--issue-token`) do the same from the command line, with the same
  rules; the command line is not an administrator, so it cannot approve code.
- Add an optional `date` to `content-create-page`, `content-create-post`,
  `content-update-page`, `content-update-post`, `content-bulk-create` and, per
  item, `content-bulk-upsert-posts`. It is an ISO 8601 date or date-time, read
  in the site timezone unless it carries an offset, and a value that cannot be
  read is refused. The status `future` now needs a date that is still ahead (or,
  on an update, a post that already has one) and is refused otherwise, where it
  used to publish the post at once.
- Add `trashed` to the answer of `comment-delete` and `post_id`, `parent` and
  `status` to the answer of `comment-create`. Add `created`, `updated`,
  `persistent` and `persistence` to `cpt-register` and `taxonomy-register`, and
  `write_supported` to `seo-meta-get` and `seo-meta-update`. Add
  `post_type.persistent`, `post_type.persistence` and `acf.saved` to
  `content-model-loop-grid-flow`, and `slug_adjusted` to `wc-term-save` when
  WordPress made the slug unique.
- Add a size cap of 25 MB to `media-upload`, `media-upload-batch` and
  `stock-image-import` (the `stonewright_media_max_bytes` filter changes it), and
  check edit access to the parent post when a `parent_post_id` is given.
- Add the `add_inner_section` action to `stonewright-elementor-v3-batch-mutate`.
  It adds a nested container to a container, or an inner section with one column
  to a column, and registers `<op_id>.column` so a later operation of the same
  batch can add into that column. It is the replacement of the deprecated
  `stonewright-elementor-add-inner-section`; the `stonewright-elementor-add-widget`
  refusal for `inner-section` now points at the action.
- Watch Elementor's own write abilities when they run outside
  `stonewright-elementor-native-execute`, for example through Elementor's own MCP
  server. Before the call, the post or kit the input names is snapshotted (the
  active kit for default styles, classes and variables). After it, a change
  history row (family `elementor`, shown as "Not verified" because nothing probed
  it, with the content before so it can be undone from Stonewright > Activity >
  Changes) and an audit row marked `observed_native` are written. The call itself
  is never blocked, refused or changed, a failure of the recording is only logged,
  and a call that comes through `stonewright-elementor-native-execute` is recorded
  once, by that tool.
- Add the Pending approvals card to the Skills screen. An administrator sees
  each request to promote researched knowledge or an expertise pack there, with
  the evidence and a note field, and approves or rejects it with a nonce-checked
  form that needs `manage_options`.
- Refuse Direct writes on a site where the Stonewright plugin is active. Before a
  write, Direct reads the site's REST index (`/wp-json/`) for the plugin's
  `stonewright/v1` namespace. When it is listed, the write is refused with
  `stonewright_direct_write_blocked_plugin_present` and a message that points to
  Plugin mode, because a Direct write skips the plugin's context token,
  confirmation, snapshot, Rescue ledger and audit. The check covers every REST
  write, the media upload (before the file is read or downloaded) and the
  Elementor WP-CLI write, and the WP-CLI commands that change the site
  (`stonewright-wp-cli-run`, `stonewright-wp-cli-job-start`). The answer is cached per site for five minutes, shared
  by concurrent calls, and cleared on reconnect, so a write adds no request once
  it is known. When the index cannot be read twice, the write is refused with
  `stonewright_direct_plugin_check_failed`. Reads and the typed plugin bridge used
  by `stonewright-task-start` and `stonewright-learning-record` are not checked.
  For recovery only, `STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE=1` together with
  `confirm: true` on the call lets a write through; each use writes an audit row
  (`direct_write_plugin_override`) and adds a `warnings` entry to the result.
  `stonewright-content-create-page`, `-content-create-post`, `-content-create`,
  `-content-update`, `-media-update`, `-menu-create` and `-menu-update` gain an
  optional `confirm` argument for that override.
- Report a plugin that appears during a Direct session. When the Direct
  `stonewright-task-start` reaches the plugin, the result carries
  `plugin_detected: true`, `required_actions: ["switch_to_plugin_mode"]` and a
  `next_action` that says how to switch. An `auto` session reconnects in the same
  call and answers through Plugin mode when the MCP route responds. The connection
  status shows `mode_conflict` when Direct is the session mode and the plugin is
  active.
- Edit the blocks of a page or post by path in Direct mode
  (`stonewright-blocks-batch-mutate`, with a content hash, a saved copy, a
  readback and the custom-code guard), delete a lesson from Direct memory
  (`stonewright-memory-delete`), offer to record a lesson after a failed write is
  fixed, rank Direct memory by confidence and time since last confirmed, and parse
  the blocks in `stonewright-gutenberg-validate`. See the companion changelog.

### Changed

- On a site that is not local, a plain `confirm: true` no longer confirms a
  destructive Direct call; the refused call returns a `confirmation_id` to repeat
  it with (`STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on` restores the old behavior).
  `force_destructive` and `allow_widget_type_remap` on
  `stonewright-elementor-data-update` need a confirmation in every write mode and
  are audited. See the companion changelog.
- Key Direct memory by the site's canonical URL instead of the alias
  (`memory/<host>-<fingerprint>.jsonl`). Two aliases for one site share its memory,
  and an alias pointed at another site no longer inherits the old site's lessons.
  An alias-keyed file from an earlier companion is copied once, never moved or
  deleted, and is still read while the alias points at the same site.
- List only the site's own errors from the last 30 days as `recurring_errors` in
  Direct `task-start`. Audit rows for local work (`stonewright-command-*` and
  `stonewright rescue`) now carry `backend: "local"` and `mode: "local"`, open no
  Direct incident and are not counted as Direct errors.
- Make the managed block of the global `~/.stonewright/AGENTS.md` mode-aware: shared
  rules first, then a section for Direct mode and one for Plugin mode, so a Plugin
  session is not taught Direct-only tool names and the text no longer claims
  backups or readback that a tool does not do.
- Answer `blueprint-apply` with `engine: elementor` or `fse` with
  `engine_requires_plugin_mode` and a message that the engine needs Plugin mode
  (it was `elementor_requires_plugin`, which was wrong for `fse`); any other engine
  is `invalid_engine`. `blueprint-apply` takes `blueprint_id`, `page_title` and
  `mode`.
- Send `settings-update` the REST names core knows. `blogname`, `blogdescription`
  and `timezone_string` were passed on under those names and core REST ignored
  them; they are now sent as `title`, `description` and `timezone`, and
  `ignored_keys` lists an allowed key that core REST did not store.
- List comments with the status core's query knows. `comment-list` with
  `status: approved` is sent as `approve`.
- Say in the Elementor V3 migration guide and the editor adapter notes that the
  deprecated `elementor-add-*` abilities are removed in 2.0.0, the sunset
  version their metadata reports, and that the changelog announces the removal
  first. The guide generator reads the version from the abilities' metadata
  instead of naming a release in its text.
- Keep `php-execute` on the `full` profile only, everywhere. This changes
  behavior: the companion no longer lists `stonewright-php-execute` in the
  offline profile lists of bootstrap, essential-static, essential, low-tools,
  elementor-design, content-model, gutenberg, wp-cli or site-admin, and it no
  longer keeps the tool registered in those profiles or in `discover-execute`
  when the site lists it. A client on one of those profiles no
  longer sees it; switch to `full` to use it. The plugin's workflow and token
  rules from `tool-profile`, the `content-model` profile, the server
  instructions, the workflow preflight call sequence, the specialization and
  context hints, the Elementor capabilities summary and the bundled skills and
  docs name it for `full` only. See the companion changelog.
- `content-model-loop-grid-flow` now needs `manage_options` and
  `edit_theme_options`. It writes the `cptui_post_types` and
  `stonewright_acf_field_groups` options, creates an ACF field group and an
  Elementor loop template, and `edit_posts` was not enough for that.
- `comment-create` now needs edit access to the target post (`edit_post`), which
  is the capability core checks when an editor replies in wp-admin, instead of
  `edit_posts` for every post. The post must exist, must not be a draft, pending
  or trashed post, and must take comments (a user who can moderate comments may
  reply on a closed post, as in wp-admin). A parent comment must belong to the
  same post.
- `media-set-alt` now needs a confirmation token in production-safe mode, like
  the other writes.
- Comment status is one vocabulary: `comment-get`, `comment-list` and
  `comment-update` return `approve`, `hold`, `spam` or `trash`, not core's `1`
  and `0`. `comment-list` takes a status from that list or `all`, and its
  `total` counts every match, not only the page. `comment-delete` moves a live
  comment to the trash (`trashed: true`, `deleted: false`), deletes for good only
  with `force` (`deleted: true`), and refuses a comment that is already in the
  trash or spam unless `force` is set, where core would have deleted it.
  `content-bulk-create` now returns `ok`.
- `content-create-post`, `content-bulk-create` and `content-bulk-upsert-posts`
  refuse attachments, revisions, menu items and the other internal post types,
  and a post type that is not registered. `content-update-post` refuses the id of
  a revision, as `content-update-page` already did.
- `cpt-register`, `taxonomy-register` and the post type of
  `content-model-loop-grid-flow` refuse a slug that is too long (20 characters
  for a post type, 32 for a taxonomy), a name WordPress reserves, and a name that
  another plugin or the theme registered. They check what `register_post_type`
  and `register_taxonomy` return, store nothing when WordPress refuses, and
  merge into an existing CPT UI entry instead of replacing it: a setting the call
  does not send keeps its stored value. Their descriptions and answers now say
  that the entry is registered again on later requests only when the CPT UI
  plugin is active.
- `seo-meta-get` and `seo-meta-update` read and write "do not index" in the shape
  each plugin uses: an array of robots values for Rank Math, `yes` for SEOPress,
  and the existing values for Yoast. All in One SEO v4 keeps its data in its own
  table, so it is read and written through its post model when that is available;
  without it `noindex` is `null`, a write returns
  `stonewright_seo_write_unsupported` and nothing is written. A `noindex` that
  does not read back as sent is an error.
- `media-list` searches the whole library (title, caption, description, alt text
  and file name) instead of the newest 200 items.
- `stonewright-elementor-v4-migrate` now returns its error codes with one
  prefix (`stonewright_v4_migration_confirmation_required`,
  `stonewright_v4_migration_has_loss`, `stonewright_v4_migration_write_failed`,
  `stonewright_v4_migration_readback_failed`; they were
  `stonewright_stonewright_...`), takes a `confirmation_token` bound to the post
  and the other arguments in `production-safe` mode in addition to
  `confirm_migration=true`, and returns `before_hash` and `rollback_status`.
- The Elementor V4 feature-flag refusal of the V4 abilities and of
  `design-spec-to-elementor-v4` now has the code `stonewright_feature_disabled`
  (it was `feature_disabled`).
- `stonewright-elementor-v4-update-node` states in its description that
  `dry_run` defaults to false.
- `stonewright-elementor-v4-atomic-widget-define` follows the Elementor V4
  feature flag and `DISALLOW_FILE_MODS`, and its `slug`, `title` and `template`
  have length limits.
- The ability truth matrix shows `deprecated` as the status of the abilities
  that report themselves deprecated (the `stonewright-elementor-add-*` widget
  abilities), and its Backup column and the public API contract read the same
  detector, so `stonewright-elementor-native-execute` shows a backup in both.
  The `stonewright-elementor-add-*` abilities are annotated as not destructive.

- Make the writers of kit colors, kit typography and Theme Builder templates
  point to the CSS regeneration they need. `elementor-v3-update-kit-colors`,
  `elementor-v3-update-kit-typography`, `theme-builder-create-template`,
  `theme-builder-set-conditions` and `theme-builder-apply-template` return a
  `next_step` for `elementor-css-regenerate` and purge the cached page of the
  post they changed (`cache_purge`), as `elementor-css-regenerate` does.
  `theme-builder-delete-template` purges the cache of the trashed template.
  `elementor-v3-update-kit-colors` now says in its description that it changes
  the custom colors (`custom_colors`) and leaves the four system colors alone.

- Run the checks of the write in the dry run of `elementor-v3-update-element`,
  `elementor-v3-batch-mutate` and `elementor-v3-transaction-run`, so a dry run
  reports the same refusal the write would give. The dry run of
  `elementor-v3-build-page-from-spec` reports in `checkpoint.would_block`
  whether the design checkpoint would stop the write; it is still never refused
  for it.

- Bind the confirmation token of `elementor-build-tree` to the whole call,
  the tree included, so a token authorises the tree it was issued for. A dry run
  of `elementor-build-tree` needs no token, like the dry run of the other
  Elementor writers. It also refuses a document that holds Elementor V4 Atomic
  nodes, as `elementor-v3-build-page-from-spec` does.

- Make `stonewright/design-mirror-export` a read (it writes nothing, so it needs
  no confirmation token in production-safe mode) and
  `stonewright/elementor-v3-legacy-debt-migrate` an audited write for `apply`,
  with its confirmation check done by the common write wrapper. A dry run of
  `legacy-debt-migrate` is a read.

- Declare `custom_code_grant` in the input of the Elementor V3 writers that
  read it: add container, add widget, update element, batch mutate, kit batch
  mutate and update page settings.
- `blueprint-apply` keeps the status of an existing page unless `mode` is passed,
  and a new page is written as a draft and gets its final status (`mode`, draft
  by default) only after the render and the write have worked. If the write
  fails, the page the call created is removed. The result's `mode` names the
  status the page has. `wp_update_post` results are checked, and the `fse`
  engine needs `manage_options` and `edit_theme_options` and stores its template
  the way WordPress does (slug as `post_name`, `wp_theme` term).
- `design-spec-to-elementor-v3` writes through the full-tree transaction
  (snapshot, write, read back, restore on a mismatch) and, for an append, refuses
  the write when the page changed since it was read. The canvas template and the
  hidden theme title are set up by default only on a page that has no Elementor
  content yet; the new `page_shell` input forces (`true`) or skips (`false`) it,
  and the result's `page_shell` says whether it was stored.
- `design-direction-list`, `-get`, `-brief`, `design-implementation-contract`,
  `design-native-plan` and `widget-intent-resolve` need the design-view
  capability (`manage_options`) instead of `read`.
- `blocks-remove` is annotated as not idempotent, because it removes the block at
  an index path and a repeat removes the next block.
- `blocks-query-loop-build` takes term slugs, resolves them to the term ids the
  Query Loop block filters by, and refuses an unknown taxonomy or term. The
  unused `post_id` input is removed; insert the built spec with `blocks-insert`.
- `menu-create` returns `items_failed` with the position, title and reason of
  every seeded item that could not be stored.
- `patterns-delete` reports whether the pattern was trashed or deleted
  (`trashed`, `deleted`) and documents `force`.
- `theme-custom-css` update needs `css`; an update without it is refused instead
  of storing an empty stylesheet.
- `fse-update-global-styles` refuses a call that passes neither `settings` nor
  `styles`, and replaces a list (such as a color palette) with the list that was
  sent instead of merging two lists by position.
- `stonewright-elementor-native-execute` no longer depends on the experimental V4
  writer flag. It is available when the native Elementor state is `available` and
  at least one ability is certified; otherwise it answers
  `stonewright_native_unavailable` with the state, the issue codes (for example
  `elementor_version_out_of_range`) and the Stonewright writers to use instead.
  Reads and dry-run plans work in every mode without a token. A real write
  follows the rule of the other writers: a `confirmation_token` in
  production-safe mode, no token in the other modes.
- Put `stonewright-elementor-native-execute` where agents find it. When native
  Elementor is available it is on the essential surface and sits right after
  provider discovery in the `elementor-design` profile. The agent instructions,
  the `elementor-design` workflow rules and the task-start Elementor block say:
  for a V4 (Atomic) document prefer `stonewright-elementor-native-execute` for
  the certified abilities, fall back to the Stonewright V4 writers otherwise, keep
  V3 documents on the V3 writers, and never call `elementor/*` tools on
  Elementor's own MCP server because that skips the backup, the change history
  and the audit.
- Report the real native write state. `writes_enabled` and `routable_write` are
  true when a certified ability may be written natively, `routable_writes` lists
  them, and each ability and write primitive carries its own `routable` flag
  instead of a fixed false. `native_elementor` also carries `guidance`, and a
  `version_issue` (observed version, certified range, issue codes) when the
  Elementor version is outside the certified range or could not be read.
  `stonewright-elementor-provider-discovery` derives its `provider_policy` from
  the contract files and declares every key it returns in its output schema.
- Accept a `page` argument in `stonewright-search-query` and return `page`,
  `per_page` and `total_pages` next to `total`.
- Add the block insert, update and remove, finalizer and pattern tools to the
  `gutenberg` tool profile, the content, media and SEO tools to the
  `content-model` profile, and the menu and theme chrome tools to the
  `site-admin` profile. The `content-model` and `site-admin` profiles list more
  than the default client cap of 50 tools, so the cap trims the last tools of
  those two lists.
- Stop promoting a knowledge candidate by itself after two verified successes.
  The plugin now stages the candidate for an administrator instead. The
  `user_approved`, `approval_note` and `maintainer_approved` arguments of
  `stonewright-knowledge-candidate-record` and `stonewright-expertise-promote`
  are ignored.
- Record the live runtime fingerprint itself when a verified success is counted
  for a knowledge candidate, instead of taking it from the caller.
- Write `source_url` and titles of `stonewright-elementor-knowledge-refresh` as
  escaped frontmatter values.
- Bound the orphan postmeta check of `stonewright-site-pulse` to 1000 rows.
- Limit Direct-mode `stonewright-media-upload`: a local path must sit inside the
  working directory, the companion media folder or a root in
  `STONEWRIGHT_DIRECT_MEDIA_ROOTS`, or the call needs `confirm: true`; a URL must
  be http or https and resolve only to public addresses, on every redirect hop.
  The file must be an allowed image, audio, video, document, txt or csv type (no
  SVG, HTML or PHP), at most 25 MiB, and its first bytes must match its
  extension. See the companion changelog.
- Direct mode refuses custom code (theme.json CSS, Elementor custom CSS and HTML
  widgets, script, style, iframe, object, embed, link and meta http-equiv markup, inline event handlers and
  `javascript:` URLs), saves a pre-image before whole-document writes, and asks
  for `confirm: true` on template and template-part updates; see the companion
  changelog.
- Documentation: Direct mode docs and `AGENTS.md` rule 7 state that the companion
  writes through core REST in Direct mode only, list the protections it carries,
  and list what it does not do yet.
- Never fall back to Direct on a live plugin, and honor `fallback_policy`. In
  `auto` mode, a missing `/wp-json/mcp/stonewright` route used to register Direct
  even when the REST index listed the plugin (for example with the MCP Adapter
  inactive). The session now stays degraded with `plugin_route_missing` and tells
  you to enable the MCP Adapter and call `stonewright-reconnect`. The same applies
  when the route is confirmed missing during plugin registration. A site whose
  registry `fallback_policy` is `never` (selected by `STONEWRIGHT_SITE_ALIAS`) also
  stays degraded instead of using Direct; `always-direct` and
  `direct-when-plugin-unavailable` still allow it. `STONEWRIGHT_MODE=direct` and
  `direct-only` sites now read the REST index at startup: with the plugin active
  they still register Direct, with reads working and writes refused. The REST
  index check reads the namespace list even from a response cut at the size cap.
- Check what `stonewright-gutenberg-compose` returns, save a Direct skill as a
  disabled candidate (Direct cannot activate a skill), and keep an existing page's
  status in Direct `stonewright-blueprint-apply`. The Direct `full` budget is 105
  tools. See the companion changelog.
- `stonewright-tool-profile` in the companion does not switch to the `full` profile.
  `action=activate` with `profile=full` returns a refusal (`approval_required`,
  `agent_must_stop`, the settings link) as an error, whether or not a plugin answers,
  and no longer falls back to a local catalog that reports success. A profile hint in
  a tool result moves the companion onto `full` only when the site's tool surface is
  Full. `STONEWRIGHT_MCP_TOOL_PROFILE=full`, set by the person who runs the companion,
  is unchanged. The server instructions and `doctor` say who chooses `full`.
- The companion does not forward a request for the `full` profile to the plugin: a
  plugin older than the administrator-only rule would switch. `stonewright-tool-profile`
  refuses an activation of `full`, or a call with no `action`, before it reaches the
  plugin, with a settings link built from the configured site address, unless the
  companion already runs `full` or the site surface is Full. A read-only
  `action=resolve` still goes through. The companion itself only asks the plugin for
  `action=resolve`, never for `full`.

### Fixed

- Serve the Stonewright MCP routes (`/wp-json/mcp/stonewright` and
  `/wp-json/mcp/stonewright-oauth`) when another plugin loads its own copy of the
  WordPress MCP Adapter first. A copy of version 0.5.0 or newer that has the server
  API Stonewright calls (`instance()`, the 13-argument `create_server()` and the
  server, transport and handler classes) is now used as it is, with or without a
  Jetpack Autoloader manifest. Rank Math SEO 1.0.280, which bundles MCP Adapter
  0.5.0, no longer leaves the route missing; the other plugin's own MCP server is
  not touched. When a copy cannot be used, an error notice on the Stonewright
  screens, the Plugins screen and the Dashboard (administrators only; each user
  can dismiss it for 7 days and it returns when the owner or version changes),
  the Troubleshoot page and its **MCP runtime** check, and the new
  `mcp_runtime` field of `stonewright-site-capabilities` and
  `stonewright-task-start` name the plugin that owns it, its version and what to
  do, and both MCP routes answer HTTP 503 with the same explanation instead of a
  missing route.
- An undo or restore of an Elementor page no longer makes the next Elementor save
  of that page fail with `stonewright_elementor_stale_editor` when no editor is
  open. A restore from a backup, a `change-rollback` and a rescue rollback bring
  the saved editor baseline of every user up to the restored document. A call that
  is refused, or fails and leaves the page as it was, no longer adds a restorable
  row to the change history: the row says there is nothing to undo.
- Undoing a native default-style write works. Elementor 4.3 keeps default styles
  in posts of their own, which a kit snapshot never covered, so the history said
  "restorable" and the undo changed nothing. A kit snapshot and the kit's change
  image now take the default styles through Elementor's repository, and an undo
  writes them back and reads them back. `stonewright-elementor-native-execute`
  and the change history now agree: a default-style write names its kit snapshot
  as the rollback recipe (`rollback_available: true`) only when that snapshot
  holds the default styles.
- Calls to Elementor's own write abilities that Stonewright only watches no longer
  push real snapshots out of a page's backup history. The watcher keeps its
  snapshots in a pool of its own (the newest three per page), drops one when the
  call failed or changed nothing, and no longer leaves a snapshot id in the audit
  row of such a call.
- The watcher now sees kit-level edits (default styles, global classes through
  Elementor's repository, global variables) instead of logging them as
  `unchanged`, and records an edit of a published page that Elementor keeps in an
  autosave as `staged_in_autosave`.
- Elementor writes by a user without `unfiltered_html`, or on a site that sets
  `DISALLOW_UNFILTERED_HTML`, no longer fail with
  `stonewright_elementor_readback_failed_restored`. The tree is filtered the way
  Elementor's own editor filters it for that user before it is written, stored,
  read back and verified, and the write receipt carries `content_filtered: true`.
  The page and kit settings writers (`update-page-settings`, `update-kit-colors`,
  `update-kit-typography`, `kit-batch-mutate`, the Design Direction kit sync and the
  brand kit) do the same with the settings Elementor filters when it stores them,
  and their results carry `content_filtered: true` when the filtering changed
  something.
- The writers that compare the stored tree with their own tree
  (`stonewright-elementor-v3-batch-mutate`, `-build-page-from-spec`,
  `-v4-update-node`, `-v4-render-from-spec`, `-v4-migrate` and the whole-tree
  transaction) compare with the filtered tree that was really stored, so a string
  Elementor's filtering changed is no longer reported as a readback mismatch. Their
  results carry `content_filtered: true`, and the hashes they report are those of the
  stored tree.
- A Stonewright write of an Elementor document refreshes the writer's own saved
  editor baseline, so an Elementor save by the same user right after a V3 or V4
  Stonewright write is no longer refused as a stale editor. The baselines of other
  users, who may have the editor open, are left as they are.
- `stonewright-elementor-v3-get-kit-globals` and
  `stonewright-elementor-v3-kit-batch-mutate` use the kit keys Elementor has:
  `system_colors`, `custom_colors`, `system_typography` and `custom_typography`.
  The `global` bucket, which pointed at keys Elementor does not use, is gone from
  the read and from the `bucket` values of the batch (a `global` bucket is
  refused). A kit that was never saved reports the default system colors and
  fonts Elementor reports, and a system colors or fonts merge on such a kit keeps
  the rows it does not name.
- On the default tool surface, `stonewright-elementor-native-execute` is
  registered with the Abilities API whatever the live Elementor state is at that
  moment, so the MCP server no longer logs that the ability does not exist or
  raises a notice from the adapter on every in-process call. It is still listed
  as a tool only when native Elementor is available.
- `stonewright-elementor-provider-discovery` explains a group of
  `descriptor_unavailable` (and `schema_unavailable`, `schema_invalid`) issues:
  each group carries an `effect` of `inventory_only` and a `note` saying the
  types are listed without a native write and nothing else is affected.
- Line up the controls of the admin pages. A button beside a field whose label sits
  above it (the Skills search and "New skill", the Audit log and Changes filters)
  now meets the input instead of the middle of the field, whatever hint is under
  the field. Button, input and select sizes come from one set of control-size
  tokens in the shared admin layer, and a layout check measures every page of the
  admin menu in CI.
- `stonewright-custom-code-provider` accepts the arguments the theme-file
  provider reads: `start_marker`, `end_marker`, `marker`, `create_if_missing`,
  `theme`, `smoke_url`, `id` and `expected_active`, each typed and bounded, and
  `mode` lists its four values. `replace_between_markers` and `create_if_missing`
  now work through the tool instead of being refused as unknown properties.
- `stonewright-blueprint-apply` with `mode: publish` needs the publish capability
  of the post type (for a page, `publish_pages`), as the other content writers do;
  a role that can only edit pages can still apply a blueprint as a draft.
- `stonewright-comment-list` says when it stopped at its limit of 2000 checked
  comments: the answer carries `truncated: true` and a `hint` that names the
  filters to narrow the list with, instead of ending silently.
- `stonewright-theme-json-handoff` refuses with `stonewright_child_theme_required`
  when no child theme is active, so it never writes `theme.json` into a parent
  theme that an update replaces. The description already said child theme.
- Direct `stonewright-direct-backup-restore` lists the meta keys that were added
  after the backup as `meta_not_in_backup` (also in a dry run). A restore writes
  the saved meta back over the current meta and deletes no key, so
  `matches_backup` can be false after a correct restore; the tool text, the
  README and the Direct guide now say so.
- Name the right tool in `next_ability` when a generic content write is refused
  for an executable-code post type: `stonewright-custom-code-provider`.
- Show the real tool limit of the Bootstrap surface in the Setup surface menu
  (`Bootstrap (≤12 tools — progressive discovery)`); the label now takes the
  number from the budget constant the registry enforces.
- Say in the connect prompt that `--profile discover-execute` exposes the three
  protocol tools plus the startup and confirmation-token tools, instead of "a
  minimal 3-tool protocol surface".
- Make **Dashboard > Updates > Check again** find a release published in the
  last 12 hours. For an administrator who can update plugins, Check again now
  reads the release list from GitHub instead of the 12-hour cache, once per
  request. A lookup that fails (network error, rate limit, unexpected answer)
  keeps the release found earlier and the update stays offered.
- Give every site of a multisite network the mode its environment type calls
  for. After a network activation only the main site got an initial mode; every
  other site read `development` until an administrator saved one, so a sub-site
  of a production network ran without confirmation tokens. A site now gets the
  mode and, if the plugin never ran there, the `essential` tool surface on its
  first request, when a site is created on a network where the plugin is
  network-active, and on the first request after an update. A stored value is
  never replaced; the same function now sets them on activation. This changes
  behavior on production networks: a sub-site that has no stored mode, which ran
  as `development`, runs as `production-safe` after the update when the
  environment type is `production` (as `staging` for `staging`). Destructive
  abilities there then require a confirmation token. To keep the old behavior
  on a site, save `development` as its mode in **Setup > Settings**.
- Show a custom-code snippet rollback as not available once its provider
  snapshot has expired. Rescue read it as available for as long as the entry
  existed and the rollback then failed with `snapshot_missing`; the snapshot is
  kept for 24 hours, and the change history keeps the snippet body longer.
- Back up the active copy of a sandbox file before an activation replaces it
  (`<name>.active.<time>.bak` in the sandbox folder, ten kept per file). When
  that copy cannot be written, the activation stops and the active copy stays
  as it is.
- Give two backups made in the same second different names, so the second no
  longer replaces the first: sandbox draft backups and theme file backups
  (`.swbak`).
- Delete theme file backups that no entry of the backup index and no entry of
  the change journal refers to, once the index of 100 entries trims and the
  file is over an hour old. They were never removed before.
- Let `stonewright-custom-code-provider` apply the `theme-file` and `customizer-css`
  providers in `production-safe` mode. The confirmation token is verified once,
  for `custom-code-provider` and the exact request, and the delegated ability no
  longer reads the same single-use token again (it failed with
  `stonewright_confirmation_replayed`). A replayed token, a token for other
  arguments or another ability, and a missing token are still refused, the human
  `custom_code_grant` is still checked and single-use, and a dry run of these
  providers needs no token because it writes nothing.
- Fix `media-upload-batch` in production-safe mode: every item failed because
  each upload asked for a confirmation token again. The token of the batch now
  covers its items, which are still recorded one by one in the audit log and the
  change history.
- Fix `content-bulk-upsert-posts` matching a post of another type by slug (an
  attachment with the same slug was turned into a post). It also now checks every
  item before it writes any, so a bad item no longer leaves a partial write.
- Fix `comment-create` storing the text unfiltered. It now goes through core's
  comment pipeline (`wp_new_comment`), which filters the content as core does for
  a user without `unfiltered_html`, runs the duplicate and flood checks and
  decides the status.
- Fix `comment-update` ignoring failures: it now checks what `wp_update_comment`
  and `wp_set_comment_status` return, slashes the content as core expects, and
  returns the status that was stored instead of the one that was asked for.
- Fix `content-update-page` skipping the custom-code check that
  `content-update-post` applies, and `content-update-page`, `content-update-post`
  and `seo-meta-update` writing when the snapshot could not be stored; they now
  stop with `stonewright_backup_failed`.
- Fix `content-duplicate-page` and the `template` field of `content-create-page`
  and `content-update-page` losing protected meta. A copy now keeps
  `_elementor_data` and the other Elementor keys, `_wp_page_template` and
  `_thumbnail_id` from a fixed list, written after the edit check, and values
  keep their backslashes.
- Fix `acf-field-group-save` answering `ok` without saving when ACF cannot import
  a field group. It now returns `stonewright_acf_import_unavailable`, reads the
  group back after the import, and records the field group and field posts in the
  change history. `content-model-loop-grid-flow` saves its fields through the same
  import (`acf_update_field_group` stored only the group) and reports a failed
  ACF step as a failed step, with nothing written after it.
- Fix `wc-term-save` reporting a failed save when WordPress stores the name with
  entities (`Tom &amp; Jerry`) or makes the slug unique (`stone-2`); a retry could
  then create a duplicate term.
- Fix `stock-image-import` rejecting image URLs that have no file extension. It
  downloads the file, reads its type, and sideloads it with the type's extension;
  a download that is not a JPEG, PNG, GIF, WebP or AVIF image is refused.
- The snapshot returned by `stonewright-elementor-v4-create-class`,
  `-update-class`, `-create-variable` and `-update-variable` now covers what the
  write changes, so restoring it (`stonewright-change-restore`, the Changes page
  undo) brings back a class or variable change. Variables are covered through the
  kit meta that holds them. Classes are covered through Elementor's global
  classes repository (items, order and labels of the frontend and preview, read
  back after a restore), because from Elementor 4.3 a class is a post and not kit
  meta; the kit meta key of older Elementor is the fallback. A variable write whose readback does not match is now rolled
  back like a class write (the new variable is deleted, an updated one gets its
  previous values back) and `rollback_status` says whether that was confirmed.
- `stonewright-elementor-widget-define`, `-widget-register` and
  `-create-custom-widget` now write their files through the sandbox writer, so
  every write keeps a backup of the file it replaces, a change history entry
  and the size cap. `create-custom-widget` passes `icon` and `keywords` to the
  widget and returns the real class name; the registered-widgets list is saved
  without autoload, only registered widgets are loaded into Elementor, and
  uninstalling the plugin removes the list. The descriptions name the staged
  `.pending.draft` file and `stonewright-elementor-widget-register`.
- `stonewright-elementor-v4-migrate` takes the page write lock, refuses a page
  that changed since the plan (`stonewright_elementor_revision_conflict`) and
  reports the result of restoring its snapshot as `rollback_status`.
- `stonewright-elementor-v4-update-node` in single mode and the per-widget
  `stonewright-elementor-add-*` abilities read, change and write the page under
  its write lock. Single mode refuses a page that changed since the plan, and
  asks for the custom CSS approval the other Elementor writers ask for. The
  widget abilities look up the parent before they take the snapshot, so a
  refused call no longer stores one.
- The backup failure of `stonewright-elementor-v4-render-from-spec` and of the
  `stonewright-elementor-v4-update-node` batch carries the status 500 like the
  other writers.
- `stonewright-elementor-add-toggle` is no longer annotated as destructive: the
  verb in its name made the annotation say so.

- Take the write lease of the post before reading the document in
  `elementor-v3-add-container`, `elementor-v3-add-widget`,
  `elementor-v3-move-element`, `elementor-v3-remove-element`,
  `elementor-v3-repair-document`, `elementor-v3-build-page-from-spec` and
  `theme-builder-apply-template`, so two writers no longer read the same tree and
  the second one overwrites the first. While another writer holds the lease the
  call answers `stonewright_elementor_write_busy` before it snapshots or writes.
  `elementor-v3-update-element`, `elementor-v3-batch-mutate` and the other
  writers already did this.

- Set `isInner` from the destination when `elementor-v3-move-element` or the
  `move_element` operation of `elementor-v3-batch-mutate` moves a container or a
  section: one moved into another element becomes inner and one moved to the root
  stops being inner, as `elementor-v3-add-container` already did. Widgets keep
  their own value.

- Take the write lease of the post before reading the page in
  `elementor-v4-render-from-spec`, for an append and for a replace, so an element
  another writer saves in the meantime is no longer overwritten. While another
  writer holds the lease the call answers `stonewright_elementor_write_busy`
  before it snapshots or writes.

- Take the same lease before the read in the whole-tree writes behind
  `design-apply-to-post`, `design-spec-to-elementor-v3`, `blueprint-apply` and
  the `replace_tree` operation of `elementor-v3-transaction-run`, and check the
  tree the caller read under it, so the snapshot, the check and the write all
  see the same page. An operation batch run by `elementor-v3-transaction-run`
  now refuses with `stonewright_elementor_revision_conflict` when the page
  changed after the runner took its snapshot.

- Take the write lease of the kit before reading its settings in
  `elementor-v3-update-kit-colors` and `elementor-v3-update-kit-typography`, so a
  row another writer saves in the meantime is no longer overwritten. A busy kit
  answers `stonewright_elementor_write_busy` before it snapshots or writes.

- Take the same kit lease before reading the kit settings in
  `design-direction-sync-apply`, and check the plan's `base_hash` under it, so
  the hash check, the snapshot and the write see the same kit. A busy kit answers
  `stonewright_elementor_write_busy` before it snapshots or writes.

- Take the kit lease before the kit settings are read in `brand-kit-apply`. A
  busy kit is reported as `kit_busy` in the Elementor part of the result; the
  options and theme mods are applied as before.

- Write the page template and the hidden title of `design-spec-to-elementor-v3`
  (`page_shell`) under the write lease of the page, so a page setting another
  writer saves in the meantime is kept. When the page is busy the design is
  written and the result carries a warning.

- Take the kit lease before the snapshot and the write in
  `elementor-v4-create-class`, `elementor-v4-update-class`,
  `elementor-v4-create-variable` and `elementor-v4-update-variable`. A busy kit
  answers `stonewright_elementor_write_busy` before it snapshots or writes.

- Refuse a widget at the document root. `elementor-v3-move-element`, the
  `move_element` and `add_widget` operations of `elementor-v3-batch-mutate` and
  every document write answer `stonewright_widget_at_root` (or the structure
  error `widget_at_root`) instead of storing a widget that belongs inside a
  container, section or column. A document that already has one can still be
  edited and repaired.

- Make `elementor-v3-add-container` mark a container inside another element as
  inner (`isInner: true`) and return the index the container really got in
  `path`, not the largest integer, when no position was given.

- Restore the snapshot after a failed `elementor-v3-transaction-run` only when
  the write itself left the document changed and its own rollback did not
  work. A busy write lock, a conflict or an operation that failed before it
  wrote no longer overwrite what another writer saved.

- Merge the rows of `elementor-v3-update-kit-colors` and
  `elementor-v3-update-kit-typography` by their `_id`: a row for an id the kit
  has changes that row, in place, and the fields the call did not send stay; no
  id is added twice. Sending the colors or typography the kit already holds
  succeeds (`unchanged: true`) instead of failing with `write_failed`.

- Check the snapshot in `theme-builder-apply-template`, never repeat an element
  id in `append` mode, return the real error of a write that failed, and delete
  the template the call created when the call fails, so no empty published
  template is left behind.

- Return an error from `elementor-v3-backup-page` when the snapshot could not be
  stored, instead of an empty `snapshot_id`; the snapshot no longer arms a
  rescue, because it changes nothing.

- Declare `write_receipt` and `change_set` in the output of
  `elementor-v3-repair-document`, so the answer of a repair passes output
  validation.

- Ask for a confirmation token in `elementor-wire-loop` for every write that is
  not a dry run in production-safe mode, also when the loop uses an existing
  template.

- Make `elementor-schema` redirect a container, section or column to
  `elementor-v3-container-schema` with an input that ability accepts.

- Store a backslash in the settings sent to `elementor-v3-update-page-settings`
  and the kit writers unchanged.

- Make the structure hash of `elementor-v3-get-page-structure` the hash every
  Elementor writer takes as `expected_tree_hash`.
- Stop the write when its backup snapshot cannot be stored, in `blocks-insert`,
  `blocks-update`, `blocks-remove`, `gutenberg-apply-to-post`,
  `design-spec-to-gutenberg`, `patterns-create`, `patterns-update`,
  `patterns-delete`, `patterns-categories`, `fse-write-template` and
  `fse-write-template-part` (updating an existing one), `fse-update-template`,
  `fse-navigation` and `fse-write-global-styles` /
  `fse-update-global-styles`. They return `stonewright_backup_failed` and write
  nothing; a create that cannot be backed up removes the new post.
- `blocks-update` with `attrs` and `innerHTML` on a block that only the browser
  finalizer can write is refused with `stonewright_finalizer_inner_html_unsupported`
  instead of queueing the change and dropping `innerHTML`.
- `theme-activate` refuses a theme that is not installed and reads the switch
  back before it reports `active`.
- `menu-assign-location` refuses a location the active theme did not register
  (`stonewright_location_unregistered`) and lists `available_locations`.
- `fse-create-template-part` refuses a slug that already exists in the active
  theme and names the existing part, and stores the part the way WordPress does.
- `design-direction-save` without a `status` keeps the stored status instead of
  demoting a ready direction to draft, and activating a direction needs the
  `ready` status, so an archived direction cannot be activated.
- `design-direction-capture` with `save` refuses a slug that holds a direction
  that is not a draft unless `overwrite` is true.
- `widget-save` and `widget-delete` check the sidebar and widget ids they are
  given and change nothing for an unknown one.
- `blocks-library-check-setup` detects a plugin that is active for the whole
  network.
- `design-motion-apply-gutenberg` and `design-motion-apply-elementor-v3` write an
  audit row for every call, a dry run and a refusal included. A production-safe
  token issued for `design-motion-apply-gutenberg` now covers the batch write it
  runs.
- Correct the statement that `elementor/build-composition` is unsupported. Its
  contract is certifiable and allows a native write, so it is routable through
  `stonewright-elementor-native-execute`; a change to a published page is staged
  in an autosave. Only `elementor/manage-elements` stays unsupported
  (`upstream_global_clear_cache`, `staged_in_autosave`), and `manage-classes` and
  `manage-global-variable` stay certified with the native write refused. This
  line corrects the 1.0.0-beta.14 notes. The `site-capabilities` description no
  longer says that no native write is routable.
- Check the capability for the individual comment in `comment-get`,
  `comment-update` and `comment-delete` (`edit_comment`), and list and count only
  the comments the user may edit in `comment-list`.
- Save the attachment metadata before `media-optimize` rewrites it, merge the
  regenerated metadata into the existing metadata instead of replacing it, and
  return the snapshot as `snapshot_id`.
- Check read access for each attachment in `media-get` and `media-list`.
- Require the task context token for `change-restore`, `knowledge-import`,
  `elementor-knowledge-refresh`, `feedback-capture` and `site-backup-page`, as
  for the other write abilities.
- Record memory saves, knowledge imports, and plugin activation and
  deactivation in the change history. A memory save can be undone from it.
- Return "not found" from `memory-delete` for an id that does not exist, and
  limit `scope`, `key` and `name` of `memory-save` to the stored column widths.
- Write an audit row for `site-backup-page` and for
  `security-issue-confirmation-token`; the token is never stored in it.
- Say in the descriptions of `plugin-activate` and `plugin-deactivate` that a
  confirmation token is required in production-safe mode.
- Take the Backup, Token and read/write columns of the ability truth matrix from
  the same source facts as the public API contract, and cite in its Tests column
  only test files that name the ability (`none` when no test does).
  `security-audit-reconcile` and `security-issue-confirmation-token` are listed
  as writes, `security-audit-reconcile` as gated, and abilities that snapshot
  through a shared writer as backed up.
- Annotate `elementor-v3-save-template` as additive, since every call creates a
  new template.
- Direct `stonewright-task-start` reports the write mode the site's tools use
  (`on` for `localhost`, `.local` and `.test` hosts unless
  `STONEWRIGHT_DIRECT_WRITES` is set), and `stonewright-site-discover` lists
  every plugin-only capability, including Rescue, change sets, section reuse,
  Design Direction, site Context and Elementor V4 (Atomic) features; see the
  companion changelog.
- The "Fix my connection" prompt names `stonewright-site-info` for Plugin mode
  and `stonewright-site-discover` for Direct mode.
- Direct Elementor tools no longer use WP-CLI when the local WordPress install
  is not the configured site; they answer through core REST and report
  `wp_cli_refused`. See the companion changelog.
- Direct `stonewright-task-start` asks for `repair_open_incidents_first` only for
  an open incident that `stonewright-incident-repair-record` can close. Other
  incidents stay listed as information and close after seven days without a new
  failure. See the companion changelog.
- Make Direct tools act on the session's site. With `STONEWRIGHT_SITE_ALIAS` set,
  Direct tools took their site from the registry default instead of the session's
  alias, and its memory, incident record and task-start latch followed the default
  too. Every Direct tool now uses the session's site, and a `site` argument that
  names another alias is refused with `site_not_in_session`
  (`stonewright-task-start` returns the same refusal). With no alias set nothing
  changes.
- An error that waits for a person now carries its approval link to the MCP client.
  The promotion of a skill to guidance, a custom-code grant, a snippet rollback, an
  undo of code and a request for the full profile put their `code`,
  `approval_required`, `agent_must_stop` and `approval_url` into the error text the
  client receives; before, the link was only in error data that the MCP transport
  does not forward. The REST error envelope keeps the same fields.
- The error for changing the role of the last administrator says the administrator
  cannot be demoted; deleting the last administrator still says deleted.
- A user who may not approve gets HTTP 403, not 500, from the Skills page, its form
  handlers and the custom-code approval page.
- `wp-cli-batch-run` answers in the shape of its output schema (`count`,
  `succeeded`, `failed`, `stopped`, `results`) when the companion cannot be reached,
  instead of failing the schema check.
- Direct write checks accept the slug WordPress adds a numeric suffix to when the
  one sent is taken (`v4-test` stored as `v4-test-2`). The write is `verified` and
  the answer carries `verification.slug_adjusted` with the requested and the stored
  slug, instead of `ok:false` with `verification_mismatch`. Any other difference in
  the slug is still a mismatch.
- Direct `user-list` reads with `context=edit`, so each user carries its `roles`. An
  account that may not list users gets the public view, where core returns no roles,
  and the answer says `roles_available:false` instead of showing an empty `roles`.
- Direct `settings-get` names `default_role` and `users_can_register` in
  `not_available_in_direct`, with a note, because core REST does not expose them,
  instead of leaving them out or listing them as unknown keys.
- Every admin page and handler that refuses a user (no permission, a failed security
  check) answers HTTP 403, not the default 500, with the same message as before.

### Security

- Rolling back a WPCode or Code Snippets snippet through
  `stonewright-custom-code-provider` needs the human-issued one-time grant that an
  apply needs, bound to the code the snapshot restores. `rollback` with
  `dry_run: true` stages the restore for approval and writes nothing; a rollback
  without the grant answers `stonewright_custom_code_grant_required` with
  `approval_required`, `agent_must_stop` and the approval link, before a
  production-safe confirmation token is read. The restore that follows a failed
  apply is not changed.
- Memory text is screened for instructions that tell an agent to override the
  plugin's rules or safety gates, to disable confirmation tokens, or to send
  credentials elsewhere, with the screen that imported skills already go
  through. `memory-save`, `learning-record`, `memory-generalize`,
  `knowledge-import` (the memory entries of a bundle, whatever status the bundle
  gives them), `incident-repair-record` (the repair recipe; the incident is still
  resolved) and `POST /stonewright/v1/memory` (and Direct `learning-record` and
  `incident-repair-record`) store a match with the status `needs_review` and say
  so (`needs_review: true`, `review_rules`); it is not offered at task start or as
  site context, drafts no skill, and a later save under its key does not clear it.
  An administrator approves or rejects it on the Memory page; in Direct it is
  cleared by editing its status in the local memory file or deleting it. A
  prohibition ("never ignore the system prompt") is not flagged. The entry form
  of the Memory page is not screened, because an administrator types there.
- Direct mode holds a local WP-CLI command that changes the site to the Direct
  write gate (task-start, `confirm`, a matching local install, an audit row),
  refuses custom code in the remaining text-sending writes (ACF, media, menu,
  comment, taxonomy-term, user, settings), refuses to remove Stonewright, the MCP
  adapter, the signed-in account or the last administrator, limits
  `settings-update` to an allowlist, sends the Application Password only over
  https or to a local host, never retries a write, and connects media downloads to
  the address it checked. See the companion changelog.
- Check permissions per target in the user abilities. `stonewright-user-update`
  and `stonewright-user-delete` now require that the current user can edit or
  delete that specific user, and refuse an account that holds
  `manage_options`, `promote_users` or `edit_users` when the current user does
  not hold it. On multisite only a super admin can change or delete a
  super admin. Setting `role` in `stonewright-user-update` or
  `stonewright-user-create` now requires the permission to promote users and a
  role from the current user's editable roles, and refuses a role with
  `manage_options`, `promote_users` or `edit_users` when the current user lacks
  it. A refusal happens before a confirmation token is used.
- Refuse a role change in `stonewright-user-update` that would leave the site
  without an administrator, whether the last administrator demotes themselves or
  another user does. On multisite the rule applies per site; a super admin
  target or a super admin acting is not affected.
- Validate `reassign` in `stonewright-user-delete` (an existing user other than
  the one being deleted) and refuse to delete the last administrator.
  `stonewright-user-list` now returns the real `total` and takes `offset` or
  `page`; the answer also carries `offset`, `number` and `has_more`.
- Check Theme Builder templates per object. All `stonewright-theme-builder-*`
  abilities now require `edit_theme_options`, and the abilities that read or
  change one template require `edit_post` (`delete_post` for
  `stonewright-theme-builder-delete-template`) on that template.
  `stonewright-theme-builder-list-templates` lists a draft, private or other
  unpublished template only for a user who can edit it.
- Stop `stonewright-theme-builder-delete-template` when its snapshot cannot be
  stored, and refuse `force=true`: the snapshots are stored in the template's
  own meta, so a permanent delete would remove the way back. The template is
  moved to the trash instead.
- Take a snapshot before `stonewright-theme-builder-set-conditions` replaces
  the display rules, refuse when it cannot be stored, return the replaced rules
  as `previous_conditions`, and read the stored rules back. The call fails with
  `write_failed` when the stored rules differ from the requested ones.
- Check every key of `stonewright-settings-update` before writing any of them,
  so a call no longer writes only part of its settings. The answer lists
  `updated`, `unchanged` and `skipped` keys. `default_role` refuses
  `administrator`, a role that does not exist and any role with
  `manage_options`, `promote_users`, `edit_users`, `create_users`,
  `delete_users`, `edit_plugins` or `install_plugins`. A write that fails
  restores the keys written before it.
- Require, in `stonewright-user-app-passwords`, that application passwords are
  available for the target user and that the current user has the matching
  capability (`list_app_passwords`, `create_app_password` or
  `delete_app_password`). The `list` action is now recorded as a read in the
  audit log.
- Show `stonewright-change-log` rows without a `post_id` only for posts the
  current user can edit. `stonewright-change-restore` now requires the publish
  capability of the post type when the snapshot holds a published, scheduled or
  private status.
- Require `edit_post` for `stonewright-elementor-provider-discovery` when it is
  given a `post_id`, and `read_post` for `stonewright-capability-preflight`.
  `stonewright-memory-list`, `stonewright-memory-get` and
  `stonewright-system-instructions-get` now require `manage_options`;
  `stonewright-task-start` and `stonewright-context-bootstrap` do not call them
  and are not affected.
- Report `stonewright-plugin-delete` as deleted only when WordPress confirms
  the deletion; a delete that needs filesystem credentials or fails is now an
  error. `stonewright-plugin-activate`, `stonewright-plugin-deactivate` and
  `stonewright-plugin-delete` refuse a plugin that is not installed, and the
  Stonewright self-protection compares the plugin basename instead of looking
  for "stonewright" anywhere in the name. These checks run before a
  confirmation token is used.

- Stop `stonewright-tool-profile` `extras` from enabling code-running abilities.
  An extras list that names `php-execute`, a sandbox writer (`sandbox-write`,
  `-edit`, `-delete`, `-activate`, `-deactivate`, `-toggle`), a WP-CLI runner
  (`wp-cli-run`, `wp-cli-batch-run`, `wp-cli-job-start`), `custom-code-provider`
  or a custom widget definer is refused with
  `stonewright_tool_profile_extras_restricted`, and a stored extra that names one
  is ignored. Behavior change: an agent can no longer put `php-execute` on the
  essential surface through extras; it is on the `full` profile only.
- Make `stonewright-execute-ability` validate the arguments against the input
  schema of the ability it runs, with the REST schema validator, before that
  ability's permission and execute callbacks run, so bounds, enums, patterns and
  unknown properties count as they do on a direct call
  (`stonewright_ability_invalid_input`; the missing task context token still gets
  its own error). It refuses an ability the tool surface hides, and it runs an
  ability that runs or writes code (`php-execute`, the sandbox writers, the
  WP-CLI runners, `custom-code-provider`, the custom widget definers) only when
  the configured surface or the session profile lists it
  (`stonewright_ability_not_on_surface`). Behavior change: a `discover-execute`
  session can no longer run `php-execute` or the sandbox writers.
- Require `edit_plugins` and `manage_options` for `php-execute`, `wp-cli-run`,
  `wp-cli-batch-run` and `wp-cli-job-start`, the pair the sandbox needs, and a
  super admin on multisite. While `DISALLOW_FILE_MODS` is set, `php-execute`
  refuses snippets that call the plugin, theme and core installers and updaters
  (`stonewright_php_file_mods_disabled`) and the WP-CLI abilities refuse commands
  that write files (`stonewright_wp_cli_file_mods_disabled`). The `php-execute`
  description now says that its file-write guard is a best-effort scan of the
  source, not a sandbox.
- Refuse more WP-CLI commands. The companion's runners (run, batch, jobs and their
  HTTP routes) and the WP-CLI abilities now refuse `config`, `scaffold`, plugin
  and theme `install`, `update`, `delete`, `activate` (and plugin `uninstall`),
  `db` except its read-only subcommands, `user` changes (create, update, delete,
  `add-role`, `set-role`, `meta`, and the rest), role and capability changes,
  `core download`, `update` and `install`, `cron event run`, `cli update`,
  `search-replace` without `--dry-run`, writes to sensitive options (`default_role`,
  `users_can_register`, the user roles, `active_plugins`, `siteurl`, `home`,
  `admin_email`, any `stonewright_` option), and the `--config`, `--ssh` and
  `--http` flags, in addition to `eval`, `eval-file`, `shell`, `package`, `--exec`,
  `--require` and `--prompt`. The command is found the way WP-CLI reads it: a
  leading `@alias`, global flags before or between the command words (`--path=`,
  `--url=`, `--user=`, or a flag with a separate value), quotes, upper case,
  padding, and a whole command in one token no longer hide it. Behavior change:
  `wp-cli-batch-run` refuses those command groups and runs read-oriented commands
  only (reads of `post`, `term`, `option`, `plugin`, `theme`, `user`, `db check`,
  cron lists, plus cache and rewrite flushes); a batch with one command off that
  list is refused before any command runs. Writes go through the typed
  abilities, `wp-cli-run`, or a `wp-cli-job-start` batch. Commands the companion
  builds for its own setup, rescue and approved recipes keep a narrower list.
- Apply the HTML widget policy and the custom code gate to the whole tree in
  `stonewright-elementor-build-tree` and in `stonewright-elementor-v3-transaction-run`
  with `replace_tree`, as `add-widget` and `batch-mutate` already do per
  operation. An HTML widget needs the site option and an explicit
  `allow_html_widget`; custom CSS, a style tag in an HTML widget and unapproved
  CSS classes need what the custom code gate asks for, including a human-issued
  `custom_code_grant` (both abilities accept `allow_html_widget` and
  `custom_code_grant`). A dry run reports the refusal and leaves a grant unused.
- Make `stonewright-theme-builder-apply-template` request `verify_url` with
  `wp_safe_remote_get`, without following redirects, and only when the URL is on
  the site's own host (`home_url` or `site_url`, no credentials, same port when
  one is named). Any other URL is not requested and reports no status.
- Keep the confirmation token of `stonewright-sandbox-edit` out of the audit log
  and read its `old_string` and `new_string` through the code payload
  canonicalizer the way `sandbox-write` does (line endings only, so a fragment
  still matches its file exactly).
- Remove the audit log from the scopes of `stonewright-security-runtime-data-purge`.
  A call that names `audit` is refused with
  `stonewright_runtime_data_purge_audit_requires_administrator`, whatever the
  confirmation token or acknowledgement, and the default scopes are memory,
  incidents and error patterns. Erasing the audit log is left to an administrator.
- `blocks-batch-mutate` needs the production-safe confirmation token for every
  write that is not a dry run (update, insert, move, `insert_section` and the
  finalizer-queue branch too), like `blocks-insert`, `blocks-update` and
  `blocks-remove`.
- `design-direction-sync-plan` and `design-direction-sync-apply` accept a `kit_id`
  only when it is the active Elementor kit or an Elementor kit post, and need
  `edit_post` on it.
- `design-spec-to-elementor-v3` copies remote assets to the media library only for
  a user who may upload files and, in production-safe mode, with a confirmation
  token; otherwise it leaves them where they are and says so in `diagnostics`.
- `design-quality-check` with `persist` needs `edit_post` on the post the report
  belongs to, checked before a token is spent.
- `theme-file-read` and `theme-file-patch` need both `manage_options` and
  `edit_theme_options` for PHP files. `theme-file-patch` and `theme-json-handoff`
  refuse to write while `DISALLOW_FILE_EDIT` or `DISALLOW_FILE_MODS` is set in
  `wp-config.php`.

- Pass the tree of a whole-document replace (`replace_tree`) through the HTML
  widget policy and the custom code gate inside the transaction runner, so every
  caller of it is gated, not only `elementor-v3-transaction-run`.
- Require an administrator's approval in wp-admin before researched knowledge
  becomes an active site skill, or an expertise pack becomes verified or stable.
  The promotion call stages a request and returns an approval link. An approval
  works once, for that exact candidate or pack, and expires after an hour. A
  verified success counts only when it names a stored audit event of a call that
  succeeded with its effect verified.
- Reject URLs with control characters, spaces or quote characters in
  `stonewright-elementor-knowledge-refresh`.
- Run the static guard again when a disabled sandbox file is enabled.
- Choosing the `full` tool profile, which lists `php-execute` and the other abilities
  that run or write code, is now only possible for an administrator in wp-admin,
  under Stonewright > Setup > Settings > MCP tool surface. `stonewright-tool-profile`
  no longer activates it, in development, staging or production-safe mode and with
  or without a confirmation token: a request for `full` returns
  `stonewright_tool_profile_full_requires_admin` with `approval_required`,
  `agent_must_stop` and the settings link, and writes no session profile. The other
  profiles switch as before, `action=resolve` still describes `full`, and a site
  whose administrator already chose the Full surface has nothing to switch. The
  tool descriptions, AGENTS.md and the docs say the same.
- `POST /stonewright/v1/settings` with `essential_tools_mode:false`, which puts the Full
  tool surface in effect, is accepted only from a cookie-authenticated wp-admin request
  with a valid REST nonce. An Application Password, an OAuth bearer or any other sign-in
  gets `stonewright_full_surface_requires_admin` (403, `approval_required`,
  `agent_must_stop`, the settings link) and nothing in the request is written; the other
  settings keep working with an Application Password. Writing the Full surface back from
  the change ledger (an undo or redo of a tool-profile row) needs an admin screen and is
  refused for an ability call or a REST request.

### Documentation

- Add "MCP adapter conflicts with other plugins" to the Troubleshoot guide, and
  describe in the architecture and security guides how a copy of the MCP adapter
  loaded by another plugin is checked.
- Correct the Direct Elementor write description in the write-verification guide
  and the companion README: `elementor-data-update` refuses duplicate element
  ids, has an optional base hash (`expected_hash`), reads `_elementor_data` back,
  and its backup is restored by `stonewright-direct-backup-restore`. There is still
  no per-post lock or lease.
- Describe Client ID Metadata Documents and the `localhost` callback host in
  the OAuth part of the connect-clients guide.
- Document in the Design Spec reference the two versions the validator accepts,
  `1.0.0` and `2.0.0`, what a 2.0.0 spec adds, and the native policy and motion
  rules the validator enforces.
- Correct `docs/abilities.md`: Search is WordPress content search and oEmbed
  resolution, not OpenSearch, and Comments lists get and create.
- Update `docs/architecture.md`, the Elementor V4 engine page and the V4
  experimental page for the native route: its availability rule, the
  production-safe rule, the observer, and the certified range.
- Correct the documents that said Direct has no restore tool or after-write
  verification: Direct lists and restores its saved copies, reads every typed
  write back and can check the public page after a page, post or template write.

## Older releases

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
