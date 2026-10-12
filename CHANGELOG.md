# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

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

### Changed

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

## [1.0.0-beta.14] - 2026-10-10

### Added

- Add undo of a verified change. A change that passed its health check can be
  rolled back from **Stonewright > Activity > Rescue**, which lists every change
  the journal keeps (the last 50) with a **Roll back** button, and through
  `stonewright-rescue-rollback`. The rollback keeps the claim that stops a double
  run, the health check afterwards, the warning about newer changes to the same
  item, the confirmation token in production-safe mode, the audit row and the
  receipt. The change is recorded as rolled back, with the user who undid it
  and the way (page, ability or WP-CLI). A dry run shows the plan. A verified
  change to a theme file, custom code, a sandbox file or the Customizer CSS is
  undone only from the Rescue page: the ability, the REST route and WP-CLI
  answer `stonewright_rescue_approval_required` with the approval URL and stop.
  An incident or an unverified change to code is rolled back as before.
- Guard the undo of a verified change. It saves the current state first (a
  snapshot of the post, an options restore point, a backup of the theme file,
  the snapshot of the custom-code snippet, a copy of the active sandbox file, the
  activation state of the plugin) and probes the site before and after. When the
  undo makes a site that worked fail to load, the saved state is put back, the
  change stays verified with the note `undo_reverted`, and the call answers
  `stonewright_rescue_undo_reverted` with the probe that failed; the Rescue page
  shows the same message. When the current state cannot be saved, the undo is
  refused with `stonewright_rescue_undo_capture_failed` before anything changes.
  An incident or an unverified change is rolled back as before.
- Add `pre_restore_snapshot_id` to the result of `change-restore`: the snapshot
  of the state before the restore, which undoes it.
- Add section reuse. `stonewright/section-reuse-find` lists sections the
  current user can read and edit (published and draft pages and posts,
  Elementor saved section and container templates, Gutenberg patterns) for the
  roles a new page needs, with source, locator, role guess, layout summary,
  layout-only similarity, outline, and reuse warnings; it scans the 200 most
  recent sources and reports truncation. `stonewright/section-reuse-extract`
  returns one section as a portable payload in its own builder format, with
  Elementor element ids and V4 local style ids replaced by placeholders,
  Gutenberg anchors listed, and every reference reported with whether it
  exists. Both are read-only and never change the source page.
- Add the `insert_section` operation to `elementor-v3-batch-mutate`,
  `blocks-batch-mutate`, and, through a new `operations` input,
  `elementor-v4-update-node`. The copy and the adaptations share one dry run
  and one apply with the usual snapshot, write lock, readback, post-scoped CSS
  through `elementor-css-regenerate`, change set, and audit. After a verified `elementor-v4-update-node`
  write, single node or batch, Elementor is asked to drop the cached local styles of the page. Elements get fresh
  ids, V4 local style ids are remapped, existing global references are kept and
  a missing one fails with the exact reference, dynamic tags are kept and
  flagged, widget types and unknown settings are never changed, duplicate
  Gutenberg anchors are renamed, and a synced pattern stays a reference unless
  `detach_patterns` asks for a local copy.
- Add the option `stonewright_section_reuse` (`ask` by default, or `off`) as
  the **Reuse saved sections** row in Stonewright > Setup > Settings. A change
  is audited. Agents read the value as `agent_preferences.section_reuse` in
  `stonewright-task-start` and in the connect-time instructions; while it is
  `off` the two read abilities leave the tool lists and profiles, every reuse
  call fails with `stonewright_section_reuse_off`, and for fifteen minutes
  after a change each response carries one `notices` line.
- Add the `reuse_source` field to ChangeSetV1 (the first declared extension)
  and the `stonewright-section-reuse` skill, with a one-line pointer in the
  Elementor V3, Elementor V4, and Gutenberg skills.
- Add customized `wp_template` and `wp_template_part` posts of the active
  block theme to the sources of `section-reuse-find` (marked with their post
  type and the kind `site-template`) for users who may edit them; a template
  that exists only as a theme file is not a source. Add the warnings
  `draft_source` and `password_protected_source` to candidates and extract
  results, and `legacy_attributes` to extract: for the blocks whose WordPress
  deprecations do so, the older `textAlign` attribute is moved to
  `style.typography.textAlign` so a section saved by an older WordPress is not
  refused whole. The insert stays strict and names any other attribute the
  block does not declare.
- Name the rejected settings when an `insert_section` copy in
  `elementor-v3-batch-mutate` is refused with
  `stonewright_section_settings_not_reusable`. The message lists up to five key
  paths with the element placeholder and type (names only, never values), the
  data lists every rejection (up to 25, each with the path, the violation code
  and the control type when the key is a control), the batch answer repeats it
  as `rejected_settings` so a client that reads only the message receives it,
  and the audit row keeps the key paths. A repeated identical call answers with
  the same detail. The repair text names three ways out (choose another
  section, activate the plugin that provides the settings, or copy without them
  with the user's agreement) and no longer sends the agent to re-read element
  ids.
- Add `drop_settings` to the `insert_section` operation of
  `elementor-v3-batch-mutate`: the user's explicit approval to copy a section
  without exactly the settings the live schema rejects. The list must equal the
  rejected settings; a missing or an extra entry is refused with
  `stonewright_section_drop_settings_mismatch` and removes nothing, and without
  the option nothing is removed. A CSS class the site has not approved and
  custom CSS count as rejected settings and are dropped only when listed. The
  removed settings are listed in the answer, the write receipt and the audit
  row, and in production-safe mode the confirmation token covers the list. An
  `insert_section` of `elementor-v4-update-node` or `blocks-batch-mutate` that
  carries `drop_settings` is refused with
  `stonewright_section_drop_settings_unsupported`, which says the option applies
  only to Elementor V3 sections, and nothing is written.
- Add private, pending and scheduled pages, posts, Elementor templates and
  Gutenberg patterns to the sources of `section-reuse-find` and
  `section-reuse-extract`, besides published and draft ones, for users who may
  read and edit them. `source.status` marks the status, and the warnings
  `private_source`, `pending_source` and `scheduled_source` say the text is not
  public. A post the user may not edit answers `stonewright_not_found`, the same
  answer as a post that does not exist, and a source the user may not use does
  not count towards the 200-source limit.
- Add nested Elementor containers as sections. `section-reuse-extract` takes the
  element id of a container nested at any depth: a V3 container or section, or
  a V4 div block or flexbox, with its children and with everything under it in
  one builder family. `section-reuse-find` lists up to six nested containers of
  each Elementor candidate under `inner`, with `inner_truncated` when more
  exist. A nested container is inserted like any section, with placeholders,
  fresh ids, id attribute renaming and the element cap.

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
- Name the active Design Direction on one line in the MCP server instructions
  that clients read on connect: its name, slug, id, a 12-character prefix of the
  contract hash, and the `stonewright-design-direction-brief` tool.
- Add the `stonewright_agent_preferences` filter. Preferences it returns appear
  as `context.agent_preferences`, next to `context.design_direction_ref`, in
  `stonewright-task-start` and `stonewright-context-bootstrap`, and as one line
  in the connect-time instructions. Keys are lower snake case, values are
  booleans, integers, or text of at most 48 characters, and at most eight
  entries are kept. The plugin registers no preference.
- Add typed-tool routing hints. A `stonewright-php-execute` response carries a
  short `routing_hint` when the snippet uses post meta, option, Elementor data,
  or menu patterns, naming the typed tool; the call is never blocked and the
  hint never repeats the snippet. `stonewright-task-start` returns
  `fast_path.routing_hint` for the patterns the task mentions, only while the
  compact payload stays inside its size cap.
- Add the opt-in read-only `inspect` tool profile: the startup set plus
  discovery, read, and verify tools, with no `php-execute`, `execute-ability`,
  blueprint, or other write tool. Auto routing never selects it, and activating
  it does not widen the saved MCP surface. The companion accepts
  `STONEWRIGHT_MCP_TOOL_PROFILE=inspect`; see the companion changelog.
- Report Elementor's own MCP module as `native_elementor` in
  `stonewright-site-capabilities`, `stonewright-elementor-v3-status`, and
  `stonewright-elementor-provider-discovery`, and as a one-word state in
  `stonewright-task-start`. The report gives the Elementor version, which
  module requirements are met (the WordPress Abilities API, the WordPress MCP
  Adapter, Elementor's MCP Composer, the site switch, and the Atomic Editor),
  the registered `elementor/*` abilities, their ownership, schema
  fingerprints, and the certification result per ability. Reading it calls no
  Elementor ability.
- Check Elementor's `manage-default-styles`, `manage-classes`,
  `manage-global-variable`, and `get-page-structure` abilities against one
  contract file each under `plugin/data/elementor-native-contracts/`: exact
  input, output, and description fingerprints per verified Elementor version
  range, provider, runtime class, annotations, runtime constants, and known
  side effects. A mismatch rejects the ability and lists each exact reason.
  `manage-elements` and `build-composition` are reported as unsupported with
  the reasons `upstream_global_clear_cache` and `staged_in_autosave`.
- Add an optional `requires_provider` skill front-matter key. The accepted
  value is `elementor-native`; it compiles into a `provider:elementor-native`
  visibility constraint, hides the skill from agents and prompts while Elementor's
  abilities are absent, and never blocks enabling the skill. The codec and skill
  lint validate it.
- Document how Stonewright coexists with Elementor's own MCP server.
- Add `stonewright-elementor-native-execute`, which runs a certified Elementor
  ability (default styles, element composition, or the structure read) in-process
  inside Stonewright's closure: route, gates, Atomic type exposure, snapshot,
  write lock, execute, independent readback compared recursively, rollback on any
  mismatch, post-scoped CSS regeneration only through
  `stonewright-elementor-css-regenerate`, a ChangeSetV1, and the audit row. It
  plans first by default and offers only the certified abilities, with their
  certified input schemas. A V3 document or subtree stays on the V3 writers and a
  mixed document is routed per subtree, never converted. An edit to a published
  page lands in an autosave and is reported as `staged_in_autosave`, never as
  applied, and nothing is published. Writes that clear generated CSS site-wide
  (global classes, global variables, `manage-elements`) are refused with
  `upstream_global_clear_cache`.
- Add a nested readback to the Atomic writers: `elementor-v4-update-node` and
  `elementor-v4-render-from-spec` compare the stored document with the tree they
  wrote and restore the snapshot on a mismatch, and the class and variable
  adapters compare the stored class or variable with what was written. A dropped
  child is an error, never a success.
- List every native Elementor ability, with its result, selection, and reasons, on
  the Troubleshoot page.
- Return one `change_set` (`ChangeSetV1`) from every write that returns a
  receipt: `elementor-v3-batch-mutate`, `elementor-v4-update-node`,
  `blocks-batch-mutate`, `theme-file-patch`, `theme-backup-restore`,
  `theme-custom-css`, `custom-code-provider` (dry-run, apply, rollback), and
  `theme-chrome-update`. It lists the planned, applied, missing, and
  unexpected changes, the hashes before and after, the verification status
  with bounded evidence, the rollback recipe, `repair_of`, `supersedes`, and
  the approval the write ran under. A failed write returns it in its error
  data. It is built from the receipt the write already returns; the schema is
  `docs/contracts/change-set-v1.schema.json`, and optional extension fields
  are declared in one place in the builder and in the schema.
- Accept `repair_of` and `supersedes` on those write abilities. A write that
  repairs a failed change passes that change's `change_set_id`; when the repair
  verifies, the incident the repaired change opened moves to `resolved` and the
  repair's audit row becomes its resolution event. Successful rows still carry
  no `incident_id`.
- Accept the `change_set` a write returned in `elementor-post-write-verify`.
  The verification is recorded under the same `change_set_id`, lists the
  checked elements and markers the render shows or lacks, and keeps the lineage.
- Show the change set on each Audit Log row as a short identifier with a copy
  button and a link to its rows. While the log is filtered to a change set, a
  chip names it and **Remove change set filter** takes off only that filter. A
  change set with relatives opens a lineage drawer: the failed change, its
  failed verification, the repair, and the repair verified, as a nested list
  in which every node reads as text (state, operation, time, duration, ability,
  the change it repairs, and the state of its incident). A branch with more
  than five repairs folds, the drawer draws at most 50 nodes before **Show N
  more**, Escape closes it and returns focus to the button that opened it, and
  it fills the screen at 782 px and below. The page mounts it through the
  `stonewright_audit_log_toolbar` and `stonewright_audit_log_change_set_cell`
  hooks.
- Declare MCP tool annotations on every ability: `readonly`, `destructive`,
  `idempotent`, and `openWorldHint` in `meta.annotations`, which MCP clients
  read as `readOnlyHint`, `destructiveHint`, `idempotentHint`, and
  `openWorldHint`. Read abilities are read-only and idempotent; a write is
  destructive unless its name only adds something; an ability that makes web
  requests is open-world. An ability whose nature differs states its own hints
  in `meta()`. The ability truth matrix gains **External** and **Hints**
  columns, `plugin/data/ability-traits.php` records the facts the plugin reads
  when it registers abilities, and the public API contract records the hints of
  each ability.
- Register `meta.public`, the exposure flag of WordPress 7.1, on every ability
  next to `meta.mcp.public` and `meta.show_in_rest`.
- Add Stonewright Rescue. Before a risky write, Stonewright records a change set
  in a change journal: the ability, what it touches, and the recipe that undoes
  it. Before the write the site is asked the same questions, and when the
  ability returns a health probe asks whether the home page, a wp-admin screen,
  the REST index, or the written post still loads. A check that answered before
  the write and cannot be reached after it counts as a failure.
  When the site fails, the recorded rollback runs, the site is probed again,
  and the result becomes `stonewright_rescue_write_rolled_back` or
  `stonewright_rescue_rollback_failed` with the evidence. A probe that cannot
  reach the site is reported as unavailable and never as healthy. Rescue
  covers post writes, option and theme-setting writes, theme files, plugin
  activation and deactivation, sandbox activation, and custom-code snippets
  saved through WPCode or Code Snippets.
- Add the abilities `stonewright/rescue-status` and `stonewright/rescue-rollback`.
  The rollback needs `manage_options` and, in production-safe mode, a
  confirmation token. It supports `dry_run` and a `recheck` action, probes the
  site afterwards, and records its outcome on the change set and in the Audit
  Log.
- Add **Stonewright > Rescue**. The page lists the changes that need attention
  with their health check evidence, rolls one back after a confirmation (a
  typed phrase in production-safe mode), checks the site again, and copies a
  prompt for an agent. Every action is a form post with a nonce, so the page
  works without scripts, and it is usable at 400 px.
- Add `pending_incident` and `notices` to ability responses. While a rescue
  incident is open, every response names it and the ability that rolls it
  back. Responses whose output schema forbids extra properties declare both
  fields.
- Add the rescue helper, a must-use plugin (`mu/stonewright-rescue.php`) that
  Stonewright installs into `wp-content/mu-plugins/` on activation and after an
  update, compares with its own copy on every admin page load, and writes again
  when it is missing or changed. It records a fatal error that follows a
  Stonewright change on the change set in the journal file. An admin notice
  says so when the folder cannot be written.
- Add safe mode. A one-time rescue link (15 minutes, one administrator, hashed at
  rest) starts a browser session in which, after the administrator signs in on
  the normal sign-in page, wp-admin and that administrator's requests load with
  only Stonewright and the default theme. The sign-in page itself always loads
  with the site's plugins. The link is added to the WordPress recovery mode
  email when the fatal error belongs to a change.
- Add the setting `stonewright_rescue_mcp_safe_boot` (Settings > General, off by
  default): while a rescue incident is open, REST requests to the Stonewright
  MCP routes that carry credentials load in safe mode.
- Add `wp stonewright rescue status` and `wp stonewright rescue rollback
  <incident>`. In production-safe mode the rollback needs a confirmation token
  (`--issue-token`).
- Add a shared admin UI layer. `assets/admin/sw-ui.css` and `sw-ui.js` hold the
  design tokens, the components (buttons, badges, tags, notices, callouts,
  toasts, tables, tabs, hub navigation, dialogs, drawers, copy fields, switches,
  forms, empty states, skeletons, disclosures, and a lineage list), and their
  behaviour. Everything applies inside an element with the class `sw-ui`, so a
  page that has not adopted the layer looks as it did. The accent follows the
  user's WordPress admin colour scheme, with a darker text colour for schemes
  whose accent is too light to read on white; animations change only opacity
  and transform for 100 to 240 ms and stop when the user asks for reduced
  motion. The Stonewright admin styles and scripts depend on the layer, so it
  loads first.
- Add PHP helpers under `includes/Admin/Ui/` that print the markup of those
  components with escaped text: buttons, badges, notices, copy fields, fact
  lists, tables, empty states, cards, page headers, and icons.
- Add end-to-end checks for the admin interface: an axe scan of every
  Stonewright admin page at 1440 and 390 px, and a UI contract that measures
  text and target size, accessible names, duplicate ids, notices in the drawer,
  primary button colour, motion, forced colours, and the nine WordPress admin
  colour schemes, on the pages and on a component sheet.
- Add **Overview** as the page the Stonewright menu opens. It shows whether
  abilities are on, the mode, the tool surface counted from the abilities
  WordPress registered, the last activity and the state of the optional
  bridge; a **Needs attention** table of open incidents, changes that need a
  rollback or were not confirmed, failed or waiting block changes and an
  unverified connection, each with a state word and one action; **Finish
  setup** with the next step as the one primary button; recent activity with a
  14-day sparkline; and the Site Pulse, Elementor, skill and memory facts the
  Dashboard had. The address `page=stonewright-status` is unchanged.
- Add a menu registry (`MenuRegistry`) and a sidebar order pass (`MenuOrder`).
  Pages register their hub, tab, title and order once; the sidebar, the band,
  the tab bars, the page headers and the Help tabs read the same list. Rescue and the
  block queue console register through it. The sidebar is ordered by hub:
  Overview, Setup, AI Abilities, Knowledge, Custom code, Activity.
- Group the pages in six hubs: Knowledge holds Skills, Memory, Context, Design
  and Prompt library; Custom code holds Custom code and Code approval; Activity
  holds Audit log, Block queue and Rescue; Setup holds Setup and Troubleshoot.
  A link in the band shows a number for open incidents, queued or failed block
  changes and changes needing a rollback, and a user sees only the pages they
  can open. A page with tabs of its own (Custom code) has a tab bar under the
  page header.
- Add a skip link to the shell, and two Help tabs on every Stonewright page:
  "What is this page?" and "Glossary".
- Add **Overview** and **Setup** links and a **Docs** link to the Stonewright row
  on the Plugins screen, and open the Overview once after the first activation
  (not after a bulk activation, in the network admin, or on a site that has
  already chosen whether Stonewright is on).
- Add a trusted-proxy setting for OAuth rate limiting, off by default. List the
  proxies in front of the site as IP addresses or CIDR ranges (IPv4 and IPv6)
  in the `STONEWRIGHT_TRUSTED_PROXIES` constant or the
  `stonewright_trusted_proxies` filter; when the connection comes from one of
  them, the client address is the right-most `X-Forwarded-For` address that is
  not a trusted proxy, so clients behind one reverse proxy no longer share a
  single budget. Without the setting the header is never read. The same
  address keys every rate limit and the registering-address hash of a client.

- Add `Admin\Ui\Tabs` (tabs that are links work without script; with script they
  switch in place, keep the choice in the address and open a view that holds a
  link target), `Admin\Ui\CodeBlock` (a command or config with a titled head, a
  named copy button and a focusable body) and the choice component
  (`sw-ui-choice`) to the admin UI layer.

### Changed

- Mark Rescue as experimental with the EXP marker in the band and the sidebar,
  like Troubleshoot, Context, Design and Block queue.
- Lay the Prompt library out as one full-width section per outcome with a grid
  of equal cards. Cards in a row share one height, and their descriptions,
  requirement lists, tool lists and copy buttons sit on the same lines. Every
  card shows its modes in the footer beside the copy action.
- Show the Setup verification results as a checklist with the status, the name
  and the detail in their own columns, a next step under the detail and a
  tinted row for a failure. The two verification buttons share one height.
- Move the domain lock action to the footer of its card as a secondary button
  of the default size, with the reason it is disabled beside it.
- Give the older Rescue buttons the height, text size and corner of the
  layer's buttons, and make the Filter and Reset filters buttons of the Audit
  log as tall as the fields beside them.
- Style the file field of the Skills import as a layer control: a button of the
  default size and the name of the chosen file beside it. The field stays the
  labelled, keyboard-operable native input.
- Centre the "Stonewright ON" pill of the admin bar with equal space above and
  below in the 32px and the 46px bar.
- Keep a dynamically registered OAuth client that completed a grant for 180
  days after its last use. The daily clean-up removes such a client only when
  its last use is more than 180 days old and it has no live grant. A
  registration that never completed a grant is removed 30 days after it
  registered, and clients an administrator created stay. A refresh credential
  expires after 30 days without use and a grant ends at most 90 days after
  authorization, so an AI client that returns after a lapse signs in again with
  its stored client identifier.
- Update the Prompt library: every starter names only tools and admin pages that
  exist in the mode it is tagged for, and eight new starters cover Rescue and
  rollback, snapshot restore, repair lineage, section reuse, the native Elementor
  V4 bridge, the inspect profile and the Design Direction. A test fails when a
  prompt names something that does not exist or a removed feature.
- Add two Prompt library starters, one for looking up Elementor documentation in
  the site's knowledge store (and filling it with `elementor-knowledge-refresh`)
  and one for bringing a design's images into the media library with
  `design-normalize-assets`. Every starter that names a write which needs a
  confirmation token in production-safe mode (`elementor-v3-batch-mutate`,
  `elementor-v3-build-page-from-spec`, `elementor-v3-transaction-run`,
  `blocks-finalize-batch`, the template writers and others) says how the token
  is issued, and the Elementor starters that write name the retryable
  `stonewright_elementor_write_busy` error. Page paths follow the hubs, for
  example Stonewright > Activity > Rescue and Stonewright > Setup > Troubleshoot.
  The guard test checks the token wording, and that the Block queue, which has
  no sidebar entry, is written with its hub.
- The help text of **Reuse saved sections** in production-safe mode says that an
  Elementor V3 copy needs a confirmation token like any other write, that a
  Gutenberg copy needs one only when the same change removes a block, and that
  Elementor V4 copies stay blocked.
- Build the Knowledge pages (Skills, Memory, Context, Design, Prompt library)
  from the shared admin UI layer. Memory lists the entries first, in a table
  that stacks at 782px, with the add form and the entry editor as native
  sections, one **Settings** form for memory abilities, custom instructions and
  their text, and times in site time with the UTC instant in the `title`.
  Context shows the system facts and a copyable snapshot beside the user
  context form. Design lists every stored direction with its state and the
  reason a draft is not ready. Prompt library filters its cards as you type
  and says how many are left; copy confirms next to its button. Skills uses
  the layer's tabs, buttons, badges, tags, notices, empty states and
  skeletons, opens its review drawer as a native dialog with the safe action
  focused, and offers undo as a toast. Capabilities, nonces, form actions and
  REST routes are unchanged.
- Add `FormField` and `UtcTime` helpers to the admin UI layer, a list filter
  (`data-sw-ui-filter`, `Stonewright.ui.initFilters`), a drop zone and a
  code-face text area to `sw-ui.css` and `sw-ui.js`, and an optional row id to
  `Ui\Table`. A copy button that carries only `data-sw-ui-copy-text` now copies.
- Move AI Abilities and the Custom code pages (Drafts, Library, Active, Crash
  recovery, Approvals) to the shared admin UI. AI Abilities switches an ability
  or runs a bulk action without reloading the page, with a toast and Undo, through
  two REST routes that keep the capability, nonces and option of the form
  handlers (a third route lists a row's parameters); the bulk form stays as the
  way in without script, and Apply with nothing chosen now says what is missing.
  Categories start closed and a row's parameters load when it opens, so the page
  prints less than half the elements it did. Custom code gets empty states, one
  toolbar in the Library in place of a second row of tabs, a status badge per
  file, a confirmation dialog before a file is deleted, facts and a risk badge on
  the approval page, and a token that is still shown unmasked. Nothing about the
  sandbox storage, the file name rule, the nonces, the production-safe tokens or
  the approval stop changed.
- `elementor-v4-update-node` accepts `operations` as an alternative to
  `element_id` and `settings`; its input schema now requires only `post_id`, and
  a call with neither form fails with `missing_element_id`.
- `ProviderRouter::element_limits()` reports the element and depth caps the
  Elementor routes share.
- Deleting the plugin always removes the rescue helper, also when the plugin
  data is kept. A full data removal also deletes the journal files in
  `uploads/stonewright-state/`.
- Pin every native Elementor contract to the Elementor version. A range ending in
  `.*` covers a verified minor line, so a patch release certifies when every
  fingerprint matches exactly and a new minor line does not. `build-composition`
  is now certifiable (its description text is not part of the contract), and the
  provider report adds `native_write`, `native_write_reason`, and `execute_with`
  per ability, so a certified ability whose native write is refused is no longer
  shown as preferred.
- Mark `elementor-v4-render-from-spec`, `design-spec-to-elementor-v4`, and the V4
  class and variable create and update abilities as the fallback writers in their
  descriptions.
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
- Add the indexed `repair_of` column to the audit table (schema version 3); the
  update adds it in place. The audit rows of a repair carry `repair_of` and a
  `parent_event_id` that points at the newest event of the repaired change.
- Run the WordPress 7.1 ability lifecycle filters `wp_ability_validate_input`,
  `wp_ability_permission_result`, and `wp_ability_validate_output` on
  Stonewright abilities, on every supported WordPress version, so site policy
  and security plugins can refuse a call. A filter cannot approve a call that
  Stonewright refused: a failed schema check, a denied permission callback, and
  a failed output check stay refusals. Checking permissions no longer validates
  the input a second time inside one `execute()` call.
- WordPress's REST run endpoint (`wp-abilities/v1`) chooses the HTTP method from
  the annotations: GET for a read-only ability, DELETE for a destructive and
  idempotent one, POST for the others.
- Return the input schemas of `GET /stonewright/v1/abilities` through
  `wp_prepare_json_schema_for_client()` on WordPress 7.1 and later.
- List an ability that records its call through `audit_write()`, or carries a
  confirmation gate, as a write in the ability truth matrix and the public API
  contract; both use one detection. 19 abilities changed from `Read` to `Write`
  (for example `stonewright-comment-create`, `stonewright-user-create`,
  `stonewright-plugin-activate`, and `stonewright-menu-create`), and the
  contract lists four abilities that the matrix already listed as writes
  (`stonewright-design-direction-save`, `stonewright-design-direction-capture`,
  `stonewright-design-quality-check`, and
  `stonewright-security-runtime-data-purge`) as `Write`.
- `stonewright-elementor-v4-migrate` declares `destructive` in its annotations.
- Theme-file writes use the Rescue health probe for their check after the
  write. The receipt adds `site_probe` (`passed`, `failed`, `unavailable`, or
  `skipped`) and `incident_id`; `theme_write_smoke_failed` keeps its code and
  carries the probe evidence. A theme-file write reports `verified` and
  `effect_verified` only when that check passed, and `unverified` otherwise.
- Keep Stonewright's own notices where its pages print them, so the Code
  approval warning and guidance, the Setup mode and bridge callouts, and the
  Memory explainer stay visible. A notice no longer removes itself after five
  seconds.
- Start the first heading of every Stonewright page right below the band:
  about 24 px under it, 16 px at 782 px and below, whatever notices WordPress
  shows. The band and the page header scroll with the page.
- Show the Companion bridge on the Overview as a state ("Not used",
  "Configured", or "Needs attention") with its host and port, not the stored
  URL.
- Draw the admin bar ON indicator in green with a dot. Name each switch on AI
  Abilities after its ability, label the bulk action and category selects, and
  give the Custom instructions field on Memory a label, with its guidance and
  limit as descriptions.
- Set badge text at 12 px in sentence case instead of 10 px in capitals, and
  darken the muted text colour and the border of form controls so they meet
  4.5:1 and 3:1.
- Group the links of the dark band at the top of every Stonewright page by
  hub, in sidebar order: Overview, SETUP (Setup, Troubleshoot), AI Abilities,
  KNOWLEDGE (Skills, Memory, Context, Design, Prompt library), CUSTOM CODE
  (Custom code, Code approval) and ACTIVITY (Audit log, Block queue, Rescue).
  A hub with two links or more shows its name and a thin rule. The band lists
  only the pages the user can open, highlights the current page on any of its
  tabs, shows a count beside a link that has something to show, and sits above
  one page header (title, a line of explanation, status and the page's main
  action). The Stonewright consent screen has no band. The sidebar says
  Overview, Custom code (was Workflows), Knowledge (the Skills landing page),
  Prompt library (was Prompts) and Activity (the Audit log landing page); the
  Block queue (was Block Editor Queue) is a link in the band and has no sidebar
  entry; the page addresses did not change.
- Read the small **EXP** marker of a page that is still changing as "This
  feature is experimental." in the band and the sidebar: the marker is hidden
  from screen readers and the words are hidden text on the link and the
  entry. In the band the tooltip shows on hover and keyboard focus and closes
  with Escape; in the sidebar it shows while the pointer is over the marker.
- Show a tab bar under the page header only on a page with tabs of its own:
  Custom code (Drafts, Library, Active, Crash recovery). Show the block queue
  console inside the shell with the same band and header as the other pages.
  The Rescue page and the console no longer print their own heading.
- Fold WordPress and other plugins' notices into the "Other WordPress notices"
  disclosure only when more than three arrive; fewer stay where WordPress puts
  them, under the page header, drawn the way WordPress draws them. The
  disclosure is titled with its contents ("1 error, 3 notices") and starts open
  when it holds an error or a warning.
- Remove the Dashboard stylesheet `assets/admin/dashboard.css`; the Overview
  uses the shared admin UI layer and `assets/admin/pages/overview.css`.
- Make the shared layer's heading and paragraph reset outrank WordPress's
  element margins, so a card title no longer carries 16 px above and below it,
  and make a standalone link at least 24 px wide.
- The Activity pages use the shared admin UI layer. The Audit log shows an
  incident band, recurring patterns in one table, a toolbar whose fields each
  state how they match, the views as links, five stacked columns, and one
  Details drawer per row that holds the facts and the redacted payload; times
  are `time` elements in site time with the UTC time in the title. The change
  set lineage and the Change set line use the same drawer, dialogs and buttons,
  and `pages/audit-lineage.css` and `audit.css` are removed. Troubleshoot puts
  Run diagnostics above the results, summarises them in words, lists the checks
  that need attention in one table with the others folded, and shows
  placeholders while a run is busy. The Block queue console opened without a
  session explains how to get one instead of showing dead controls; with a
  session it shows a counts band and a journal table. The consent screen is a
  page header and one card of facts with Approve as its one primary action, a
  warning for a client that registered itself with this site, and a note when
  the destination is on this computer.
- The Audit log delete dialog and the dismiss dialog of a recurring pattern are
  layer dialogs; the browser `confirm()` prompt is gone. Delete all logs still
  needs the typed phrase `DELETE`.
- The Audit log lede says that changes made on admin screens, such as Setup
  settings and Memory edits, are not recorded in the log.

- Rebuild Stonewright > Setup from the admin UI layer as four views shown as
  tabs: Get started (turn on, choose a sign-in method, connect a client, verify),
  Settings (the settings form and the domain lock), Connections (sign-in
  addresses and connected OAuth clients) and Updates. The address `tab` argument
  chooses the view, so links, redirects after a save and reloads land on the right
  one; the page header comes from the shell. Every option, field name, nonce,
  capability and form action is unchanged. Stored API keys and the bridge token
  are still never written into the page; connected clients and Application
  Passwords stack as cards on narrow screens; each repeated action names its
  client or password; the page prints no duplicate id.
- Split the Setup screen's code (`Admin\ConfigurationPage`) into small classes
  under `Admin\Setup`; `ConfigurationPage` keeps the menu, the settings
  registration and the form handlers.
- Remove the small product-name line (a logo square and the word Stonewright)
  above the title of every Stonewright page and of the connection approval
  screen, and the page header's `eyebrow` option and its styles. The title,
  the explanation line and the page's actions stay.
- Draw no ring, outline, border or shadow on a link, button, tab, choice card
  or summary after a mouse click or tap on a Stonewright page. WordPress's own
  admin styles draw a ring on every focus, mouse included; the layer now
  answers them for its own markup. A control reached with the keyboard keeps a
  2 px outline, and the current tab keeps its underline.
- Remove `assets/admin/setup.css` and `assets/admin/blueprints.css`, which no
  page loads any more, and the Setup branch of the page style map that could
  not run. The styles of the copy fallback dialog (shown when the browser
  blocks the clipboard) moved to `admin.css`.

### Removed

- Remove the unused `league/oauth2-server` dependency and the packages only it
  required (`lcobucci/jwt`, `lcobucci/clock`, `league/event`, `league/uri`,
  `league/uri-interfaces`, `psr/clock`, `psr/http-message`,
  `stella-maris/clock`), and the unused `nyholm/psr7` and `psr/http-factory`.
  The release ZIP no longer carries them. `defuse/php-encryption` 2.4.0, which
  seals the OAuth credentials, is now a direct requirement of the plugin at the
  same version, and the `conflict` entries for `lcobucci/jwt`,
  `lcobucci/clock` and `league/uri` are gone.
- Remove the `.github` folder from the plugin ZIP.

### Fixed

- Read the style controls that Elementor keeps apart from its other controls
  into the live schema of widgets, containers, sections and columns. A standard
  style setting, such as the `title_color` or `align` of a heading, is no longer
  refused as unknown, `elementor-schema` lists those controls, and the cached
  schemas are dropped when the plugin version changes.
- Keep a control that a legacy section or column defines under its own name: the
  `gap` of a section is no longer renamed to the container control `flex_gap`
  and then refused.
- Give the repeated-failure advice of a failed batch the guidance of its cause
  instead of the generic batch text.
- Move the page's stylesheet version when `stonewright-elementor-css-regenerate`
  writes a file. It regenerates through Elementor's `update()`, which stores the
  CSS metadata with the file, and moves the version (the `?ver=` of the page's
  stylesheet link, the `time` of `_elementor_css`) past its previous value, also
  within the same second, so browsers and page caches fetch the new file
  whatever the anonymous probe answers. The result reports `css_version`,
  `css_version_before` and `css_version_changed`; if the version cannot be moved
  the regeneration is rolled back as a failed operation.
- Follow a short same-origin redirect in the Elementor CSS delivery probe. A
  chain of at most two redirects that keeps the scheme, host and port, never
  reaches a login page and ends in HTTP 200 `text/css` with the written bytes
  (at most 8192 bytes are read, without cookies or credentials) is `verified`,
  and the probe records `redirect_hops`. A repeated URL, another origin or a
  downgrade to http still stops before any write. A redirect to a login page or
  to a page that is not the CSS (an access-control layer in front of uploads) no
  longer fails `stonewright-elementor-css-regenerate`: the file is written,
  `delivery_status` is `blocked`, the answer is `ok:true` with a `warnings`
  entry and a `repair` text, and the layout must not be rebuilt for it.
  `stonewright_elementor_css_probe_failed` and its rollback stay for a file that
  was delivered before the write and is not after it. The repair hints for the
  three CSS delivery error codes say not to rebuild the layout.
- Fix the errors of `section-reuse-extract`. A source without a valid Elementor
  document answers `stonewright_no_elementor_document` with a `reason`; an
  element that is not a container, or that mixes V3 and V4, answers
  `stonewright_section_not_copyable`; the messages of `stonewright_not_found`
  and `stonewright_section_not_found` say which statuses and locators are
  allowed.
- Fix Rescue rolling back a healthy write when the first render of a page
  that uses Google fonts is slow. Elementor downloads every font file the
  first time a page uses a font, which can take minutes, longer than a probe
  request waits. A probe request is now marked by a single-use token on every
  leg that needs it, and Elementor does not print or download Google fonts
  while it renders a marked request. The page of a draft carries the login
  token; the page of a published post or a kit write, which is requested as
  an anonymous visitor, carries a new mark-only token that is single use,
  bound to its path and nonce, short-lived, validated like the login token
  and never logs anyone in. A header, parameter or cookie that is not a valid
  token never marks a request, and normal visits print Google fonts as
  before. Outbound HTTP is not blocked in a probe request. The page leg of a
  published post follows a redirect on the site's own origin (same scheme,
  host and port), at most twice, with a new mark-only token bound to each new
  path; a redirect to another host, scheme or port is not followed.
- Fix Rescue rolling back a healthy write when the first render of a freshly
  written page is slow. A leg that passed before the write and gets no answer
  at all after it (a timeout or a refused connection) is probed once more,
  with the longest wait a probe request may have (15 seconds, inside the
  30 second probe budget) and a fresh token, before the write counts as failed.
  A server error, the critical error page and a PHP fatal are still failures
  at once, with no second attempt. The probe evidence marks a leg asked twice.
- Tell agents to record corrections without asking for the full response: the
  MCP connect-time instructions and the default compact `stonewright-task-start`
  (`context.learning`) now say to call `stonewright-learning-record` when the
  user corrects the agent or a mistake repeats, and that a lesson counts only
  with `verified:true`. The compact task-start byte cap grows from 3600 to 3750
  so its visual quality floor keeps the same rules.
- Fix the refusal of `insert_section` while section reuse is `off`: the failure
  message of `elementor-v3-batch-mutate` (dry run too), `elementor-v4-update-node`
  and `blocks-batch-mutate` now says that section reuse is off, and an MCP client
  also reads `retryable: false` and `execution_status: blocked` in it.
- `change-restore` no longer drops the snapshot it is restoring when the
  history of 10 is full: the snapshot of the current state is stored without
  evicting its target, and a restore whose snapshot cannot be stored is refused
  with `stonewright_backup_failed`.
- Twelve Elementor write abilities (`elementor-v3-add-container`,
  `elementor-v3-add-widget`, `elementor-v3-move-element`,
  `elementor-v3-remove-element`, `elementor-v3-update-element`,
  `elementor-v3-build-page-from-spec`, `elementor-v3-update-page-settings`,
  `elementor-v3-update-kit-colors`, `elementor-v3-update-kit-typography`, the
  per-widget `elementor-add-*` abilities, `design-spec-to-elementor-v3` and
  `elementor-v4-migrate`) refuse with `stonewright_backup_failed`, before writing
  anything and without leaving a write lock held, when the snapshot of the post
  could not be stored.
- `blueprint-apply` on an existing page returns the snapshot taken before the
  title, the status and the content change, so restoring it brings back the page
  as it was. It takes that snapshot for the FSE engine too.
- Fix `detach_patterns` on a `blocks-batch-mutate` `insert_section`: the input
  schema accepts `true`, `false`, or a list of pattern ids.
- Fix section reuse while the setting is `off`: `stonewright-task-start` no
  longer offers the section reuse skill, and the bundled skill is stored as
  `stonewright-section-reuse` instead of a doubled prefix (a bundled skill
  directory that already starts with `stonewright-` keeps its own name). The
  `stonewright_section_reuse_off` refusal is blocked and not retryable, is not
  counted as a recurring error or wrapped in repeat-failure advice, and a
  recorded entry is hidden and removed when the setting changes.
- Fix the role guess of a section: a section with an h1, a heading and a button
  or image among the first three sections of its page is a hero, not only the
  first one. Cached section signatures are analyzed again.
- Fix the Setup callout of **Reuse saved sections**: it follows the switch
  before saving and is announced politely; the setting still changes only when
  the form is saved.
- Write Elementor V4 text (the title of `e-heading`, the paragraph of
  `e-paragraph`, the text of `e-button`) with the prop type the live widget
  declares in its props schema (`escaped-html`, `html-v3`, `html-v2` or
  `html`), in `elementor-v4-update-node`, `elementor-v4-render-from-spec` and
  every other V4 text write. The bundled `html-v3` map applies only when no
  live schema is available. An envelope of another type, or one whose value is
  not the shape of its type, is refused; the readback of a write refuses text
  the live widget would render empty, and restores the snapshot, instead of
  reporting it verified.
- Report a section of the wrong builder sent to `elementor-v3-batch-mutate` or
  `elementor-v4-update-node` as `stonewright_section_builder_mismatch` before
  the custom CSS gate reads it.
- Accept in a copied V3 section a stored select or choose value that the live
  control still maps through its `selectors_dictionary` (for example a heading
  `align` of `left`). Any other value the control does not list is still
  refused, naming the setting.
- Rename an element id attribute that a copied section shares with the page or
  with another copy in the same batch (`_element_id` in V3, `_cssid` in V4) to
  `name-2`, `name-3`, point the `#name` links of the copy at it, and return an
  `anchors_renamed` warning.
- Warn at `section-reuse-extract` about what the insert will refuse: CSS
  classes not in `stonewright_approved_css_classes` (named), custom CSS that
  needs a custom-code grant, HTML widgets, and placeholder widgets. The refusal
  of unapproved CSS classes names the classes and how a site approves them.
- Refuse, in the dry run and the apply, a V3 section that holds a placeholder
  Elementor registers for a plugin that is not active
  (`stonewright_section_placeholder_widget`) or a widget that is not registered
  (`stonewright_section_widget_unregistered`), with or without settings.
- Refuse a batch with a duplicate `op_id` (`stonewright_duplicate_op_id`) in
  `elementor-v3-batch-mutate`, `elementor-v4-update-node` and
  `blocks-batch-mutate`, and refuse a batch whose `insert_section` operations
  add more elements than the element cap of one write
  (`stonewright_section_batch_too_large`).

- Design: saving, importing or capturing a revision that is not ready for the
  active design direction switches the direction off, as restoring one already
  did, so agents stop receiving it. `design-direction-save`,
  `design-direction-capture` and `design-direction-restore` return
  `active_cleared` (declared in their output schemas), and the Design page says
  the direction was switched off. A refused DESIGN.md import shows the
  validator's reason, or a short list of reasons, escaped, instead of one fixed
  sentence. The active direction card shows spacing, typography, radii,
  elevation, motion tokens and components next to the colors, dials and rules.
- Troubleshoot and Setup: a domain lock mismatch is its own check. It names the
  locked and the current address and the Setup actions that resolve it (Review
  and rebind this site, Restore prior domain binding). The Stonewright
  abilities row, the Setup preflight and the Verify connection advice use the
  effective state of the abilities instead of the stored switch, so a blocked
  site is never reported as enabled. With Stonewright off or blocked, the OAuth
  challenge and OAuth dynamic registration checks are skipped like the other
  dependent checks, and a skipped check shows its own name and says which
  checks it needed in words instead of check ids. A failing check no longer
  prints the same sentence as cause and remedy, and the Application Passwords
  check names the real cause (no support in this WordPress, plain HTTP without a
  local environment type, a filter or setting, or one user). The MCP and OAuth
  MCP server registration checks start the REST server in the request and read
  the recorded outcome; when they cannot be checked they say why and name the
  checks that cover them.
- Troubleshoot: a finished run with no problem or warning says "No problems or
  warnings."; "so far" is shown only while checks have not run. **Copy report
  for support** swaps its icon for a check and shows **Copied**, and the report
  lists each check's name and summary and the remedy of a problem or warning,
  with credentials, passwords and the install path removed.
- Context: the user context is stored and sent as plain text, with tags removed
  and no HTML entities, so `5 < 6` and quotes stay as typed, and saving the form
  again changes nothing. A value stored earlier with entities is read as the
  text it stands for; reading does not rewrite it. The page says that compact
  task start carries the first 400 characters and full task start and
  context-bootstrap the first 1,200, and the saved notice gives the stored
  character count and how much each mode receives.
- Log the Rescue health probe's own requests in as the user a probe token was
  issued for before anything about the request is recorded, so the admin leg
  and the preview of a draft, private or pending page are answered as that
  user and a write to them is verified. The identity is kept for that one
  request only, is removed when it ends, and a token that is expired, used or
  bound to another path or nonce still logs nobody in.
- Clear the pending rescue incident when the automatic rollback of a change
  succeeds after the rescue helper recorded a fatal for it. A rollback that
  fails keeps the incident open.
- Include `code`, `change_set_id`, `incident_id`, `rollback_status`,
  `site_status` and `original_error_code` in the error message an MCP client
  receives for `stonewright_rescue_write_rolled_back` and
  `stonewright_rescue_rollback_failed`. Other error data is not copied.
- Send an administrator who opens a rescue link while already signed in as the
  administrator it was issued for on to the page named in the link (the Rescue
  page by default) instead of showing the sign-in form. Only a plain GET visit
  of the sign-in page is sent on, only to an address on the site, and only
  when the signed-in user is the session's administrator.
- Remove the notice that the health probe is unavailable as soon as a probe
  passes.
- Show the state of the rescue helper on **Stonewright > Rescue** and as
  `helper` (`state`, `safe_mode`) in `rescue-status`, and offer **Open in safe
  mode** only while the helper is installed and loaded.
- Refuse a revision id in `content-update-page` with
  `stonewright_invalid_post_type` before any capability check, snapshot or
  write.

- Make `elementor-v3-update-page-settings` take the per-post write lease before
  it snapshots or writes, and release it on every path. A page another writer
  holds is refused with the retryable `stonewright_elementor_write_busy`, and
  nothing is written or snapshotted.
- Include `retryable` and `retry_after` (seconds) in the error message an MCP
  client receives for a busy page, as a trailing JSON object. Other error data
  is not copied. The REST error envelope carries `retry_after` as well.
- Fix `stonewright-design-implementation-contract` with `action: "validate"`
  failing its own output schema. The schema now requires only `version`, which
  both actions return, and describes the `contract` fields (`sequence` and the
  rules) and the `validate` fields (`ok`, `errors`, `css_policy`) separately.
- Probe the public front page after an Elementor kit write
  (`elementor-v3-update-kit-colors`, `elementor-v3-update-kit-typography`,
  `elementor-v3-kit-batch-mutate`) instead of the kit's own address, which only
  redirects. The change can now end `verified`. The probe sends no internal
  token to that page, and page writes are probed as before.
- Keep a widget's own control when its name is also a container shorthand.
  `background`, `gap`, `column_gap` and `row_gap` are no longer rewritten to
  `background_color`, `flex_gap`, `flex_column_gap` and `flex_row_gap` for a
  widget whose schema defines them (for example Alert, Divider, Text Editor and
  Social Icons), on the per-widget add tools, `elementor-v3-add-widget`,
  `elementor-v3-update-element`, `elementor-v3-batch-mutate` and
  `elementor-build-tree`. Containers, sections and columns still take the
  shorthands, and a widget without a control of that name still does.
- Store a real inner layout from `elementor-add-inner-section`: an inner
  section (`elType` `section`, `isInner` true) with one column inside a column,
  or an inner container inside a container, instead of a widget of type
  `inner-section` that nothing renders. A section as the parent is refused,
  and `elementor-v3-add-widget` and the `add_widget` operation refuse
  `inner-section` as a widget type.
- Refuse to add a widget under another widget. The parent of an added widget
  must be a container, a section or a column, on the per-widget add tools,
  `elementor-v3-add-widget` and the `add_widget` operation of
  `elementor-v3-batch-mutate` (`stonewright_parent_not_container`).
- Make `elementor-v3-build-page-from-spec` with `mode` `append` work on a page
  that already has content. Appended elements whose ids are already used on the
  page get new ids and existing ids never change. A dry run now runs the same
  checks as the write and returns the same error, and a refused write returns
  that error with its violations instead of a generic message.
- Return the retryable `stonewright_elementor_write_busy` error, with the retry
  delay, when another Elementor write holds the page, from the per-widget add
  tools, `elementor-v3-add-widget`, `elementor-v3-add-container` and
  `elementor-v3-build-page-from-spec`, instead of "Could not save Elementor
  data." `elementor-v3-build-page-from-spec` no longer restores its snapshot
  after such a refusal.
- Accept the column width keys Elementor saves, `_column_size` and
  `_inline_size`, on section columns. `elementor-build-tree` gives a column that
  has no `_column_size` an even share of its section, so Elementor no longer
  logs an undefined `_column_size` when it renders the column.
- Refuse an Elementor Pro or WooCommerce widget on a site where the plugin that
  renders it is not active, with `stonewright_widget_unavailable`, instead of
  storing a widget that renders empty.
- Stop `elementor-v3-get-element` raising a PHP warning for an element nested
  inside another element.
- Accept the block attributes that a block's `supports` add (such as
  `anchor`, `lock`, `metadata`, `className`, `align`, colours, `layout` and
  `style`) in
  `blocks-batch-mutate` and in the attribute check of the other Gutenberg
  abilities, so a registered block that supports them takes them. A block that
  does not declare the support still refuses the attribute, and any other key
  that the block does not declare is still refused.
- Register the Block queue and Rescue tabs of the Activity hub on `init`, so no
  Stonewright label is translated before WordPress is ready to load the text
  domain and WordPress no longer reports translation loading triggered too
  early. Every label, count and capability stays as it was.
- Register Stonewright's ability categories after the categories other plugins
  register on the same hook, so a category that another plugin registers (such
  as `elementor`) is no longer registered a second time. Every ability keeps a
  registered category.
- Leave the default server of the bundled MCP adapter out of a request in which
  the Abilities API has already fired `wp_abilities_api_init` before the adapter
  initialised (the Troubleshoot page builds the REST server after listing
  abilities). That server would have been created with three tools that were not
  registered. Every REST request still creates it, and the Stonewright servers
  and their tools are unchanged.
- Memory: every action now ends in a message (entry created, saved, deleted,
  lesson approved, draft discarded, learned rule disabled, settings saved,
  legacy feedback classified). Adding an entry whose scope and key are already
  in use is refused with the name of the entry that holds them, and nothing is
  replaced; moving an entry onto a pair in use is refused the same way.
- Memory: saving **Enable memory abilities** no longer clears the custom
  instructions, and saving the instructions no longer clears the memory switch;
  the three settings share one form.
- Design: importing, activating and deactivating answer with their own message
  ("imported and activated", "imported, stored as a draft", "activated",
  "deactivated"). Every stored direction is listed with its state, a draft
  shows why it is not ready, and a deactivated or ready direction can be
  activated again from the list.
- Design directions: restoring a revision stores the status that revision had,
  and clears the active-direction pointer when the restored contract is not
  ready, so an active direction is always ready.
- Render a wrapped bullet or numbered item in release notes (Plugins → View
  details) as one list item: the lines that continue it, up to the next blank
  line or block, are joined into it.
- Correct the documented retry behaviour of the companion: it sends each
  WordPress MCP request once and does not repeat it after a timeout or network
  error; on OAuth connections an HTTP 401 refreshes the access token and sends
  that request once more, a tool call included.
- Draw the label of a Setup step that is still to do at full strength instead of
  at 85% opacity, so it reads at 4.5:1 or better.
- Count `site.public_ability_count` in `stonewright-task-start` from the abilities
  WordPress registered, the same count the admin screens show, instead of the
  classes the plugin ships.
- Compare Elementor ability schemas by content when a live schema holds an empty
  object where a recording holds an empty array, so a certified ability is not
  rejected for that difference alone.
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
- Address blocks the same way in every block read and write. A block's index
  in `stonewright-blocks-parse` is its path in `stonewright-blocks-update`,
  `stonewright-blocks-remove`, `stonewright-blocks-insert` and
  `stonewright-blocks-batch-mutate`, also when the stored content has blank
  lines between blocks. A path or insert parent that names no block returns
  `stonewright_invalid_path` and leaves the post unchanged. Nested writes keep
  the wrapper markup of the parent block, and `stonewright-blocks-update`
  refuses to replace the HTML of a block that holds inner blocks.
- Store templates and template parts written by
  `stonewright-fse-write-template` and `stonewright-fse-write-template-part`
  the way WordPress does (slug as the post name, theme as the `wp_theme` term,
  area as the `wp_template_part_area` term), so `get_block_template()`,
  `stonewright-fse-read-template` and `stonewright-fse-update-template` find
  them. An earlier record written under a composite name is found and repaired
  on the next write. `stonewright-fse-update-template` stores a customized
  theme file template the same way.
- Keep `isGlobalStylesUserThemeJSON: true` on every global styles record
  written by `stonewright-fse-write-global-styles` and
  `stonewright-fse-update-global-styles`, the marker WordPress needs to apply
  the record. Merge mode now works on the record WordPress creates, and empty
  settings or styles are no longer stored as lists.
- Render `card` and `column` blocks, with their nested blocks, in the
  Gutenberg renderers instead of dropping them with an `unsupported_node`
  diagnostic.
- Register the Recipe Hero and Recipe Slider block editor scripts after the
  WordPress editor packages they use, so they no longer fail on editor load.
- Regenerate Elementor CSS for a page that produces no post CSS file, such as a
  page built only from Atomic elements, without reporting a collateral change
  or opening an incident. `stonewright-elementor-css-regenerate` now succeeds
  with `css_file_status: not_produced`, `css_file_reason: empty_css` and
  `delivery_status: not_applicable` when Elementor itself reports an empty
  stylesheet and no other CSS asset changed. A missing file without that
  evidence is still refused and rolled back, and the internal CSS print method
  now reports `stonewright_elementor_css_inline_print_method` instead of a
  collateral change.
- Render section and container styling in the Elementor V4 renderer instead of
  dropping it. Layout and direction (including viewport maps), gap, padding,
  background color, full width, alignment and z-index are written as typed
  Atomic style variants, a container without a layout stacks its children, and
  any property without a certified Atomic mapping is refused with
  `stonewright_v4_unsupported_property`, naming the property and its spec path.
- Name the block type and spec path when the Elementor V4 renderer cannot render
  a block (`stonewright_v4_unknown_node`), list the block types that render,
  and render `card` blocks as containers. `spacer`, `list`, `video`, `embed` and
  `slider` stay unsupported in V4 and the V4 documentation now says so.
- Store the template type, edit mode, version and type term that Elementor
  itself writes when `stonewright-elementor-v3-save-template` creates a library
  template, so the template opens as a library document and appears in the
  template library. A template type Elementor does not register on the site is
  refused before any post is created.
- Bound the Elementor schema cache. Cached widget schemas are compressed, at
  most 300 entries and 6 MB are kept, the oldest are evicted first, records of
  an earlier runtime fingerprint are removed when the fingerprint changes, and
  a record is served only for the Elementor version and runtime fingerprint it
  was built for.
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
- Store sandbox drafts and their backups without a PHP extension (`name.draft`
  and `name.<time>.bak`) so no web server can run them, remove a draft's
  backups together with the draft, and rename drafts and backups written by
  earlier versions the first time the folder is used after the update. Files
  that cannot take their new name, and any other file in the folder that a web
  server could run as PHP, are renamed to `.quarantined` and never overwritten
  or deleted. The folder guard now denies every request on Apache 2.4
  (`Require all denied`), Apache 2.2 and IIS, and its index file stops at once
  outside WordPress.
- Let `stonewright/php-execute` run `esc_sql()`, `wp_count_posts()`,
  `get_posts()` and every other call that needs the database handle's own
  escaping and query code on sites that use a database driver with its own
  `wpdb` subclass, such as SQLite. The guarded handle now forwards every method
  and property to the live handle and checks the same writes as before.
- Make the pattern of the Sandbox file name field valid in current browsers and
  equal to the server-side file name rule.
- Fix primary submit buttons on Stonewright pages (Save Settings and the other
  `input[type=submit].button-primary` buttons) rendering as secondary buttons.
- Fix the AI Abilities filter bar: it sticks directly under the admin bar from
  783 px up, and the search field stays 40 px tall at 400 px wide instead of
  growing to 160 px.
- Show the port of an OAuth callback on the consent screen, so "Returns you to"
  reads `http://127.0.0.1:7999` and not `http://127.0.0.1`.
- Show code inside a `pre` block as the block's own text, not as a chip inside
  the block.
- Delete all logs also deletes every incident, and the dialog, the receipt and
  the confirmation message say how many events and incidents went. Incidents
  used to stay behind and pointed at events that no longer existed.
- Audit log filters that take free text (ability, operation class, root error
  code and path) match part of the stored value in any case and keep dots, so
  `design_direction.save` and `skill_write` find their rows; status, category,
  outcome, verification, rollback, user ID and change set ID match exactly. The
  page states each rule under its field.
- Confirmation token checks (`security.confirmation_token`) are recorded in the
  `SAFETY` category instead of `WRITE`, so a refused call is no longer followed
  by a row that reads as a successful write.
- The Troubleshoot summary uses real plurals ("1 problem") and does not say that
  everything passed while checks have not run.

- Fix **Clear domain lock** giving no feedback and the lock reappearing at once:
  the site address is recorded again on every request while AI abilities are on,
  so the action is disabled with that reason while they are on, and Setup says
  what a clear, rebind or restore did.
- Fix revoking an Application Password from Setup on sites without pretty
  permalinks (the request lost its password id).
- Wait up to one second, instead of a quarter of a second, for a Windows OAuth
  token file that another program holds open for a moment, such as a virus
  scanner or an indexer, before the privacy check gives up. Privacy is still
  rechecked before every attempt, and permission, ownership and other errors
  still fail closed at once.
- Accept one top-level `confirmation_token` on `elementor-v3-apply-bundle`,
  issued for the whole call, and require it in production-safe mode. The
  per-write `confirmation_token` field is removed; the token covers every write
  of the call.
- Keep the Elementor knowledge in a private folder under uploads
  (`stonewright-private/knowledge/elementor/<hub>/<slug>.md`); the plugin
  package ships no articles. `elementor-knowledge-search`, `elementor-explain-editor` and
  `elementor-describe-widget` read only that folder and return nothing, with a
  hint that names `elementor-knowledge-refresh`, until the first refresh;
  `elementor-knowledge-refresh` writes only there. Every folder level of the
  store gets `index.php`, `.htaccess` and `web.config` deny rules. Files that an
  earlier version wrote elsewhere are left alone.
- Bind the confirmation token of `elementor-create-custom-widget` to the full
  argument object, as for the other confirmed abilities, so a token issued the
  standard way is accepted. A token issued for only `slug`, `title`, `template`
  and `activate` is no longer accepted.
- Make `elementor-v3-update-element` with `dry_run: true` need no confirmation
  token in production-safe mode, like the dry runs of `elementor-v3-batch-mutate`
  and `elementor-v3-build-page-from-spec`. A dry run writes nothing: no
  snapshot, post meta or write lock. A write still needs a token bound to its
  arguments.
- State the real range of `ttl_seconds` on `security-issue-confirmation-token`:
  60 to 3600 seconds (the schema minimum was 1 while the lifetime was never
  shorter than 60). `expires_at` reports the actual expiry.
- Make `elementor-v3-build-page-from-spec` with `mode: "replace_section"`
  replace the container built from the spec section with the same section `id`,
  and no other. A build that names its sections records the section id next to
  the container id in post meta (`_stonewright_spec_sections`); the document
  itself gains no key. The replaced container keeps its element id and every
  other container is left as it is. Every spec section needs an `id`. The call
  writes nothing and returns `stonewright_replace_section_id_required`,
  `stonewright_replace_section_unrecorded` (a page built before sections were
  recorded, or by another tool: rebuild it once with `mode: "replace"`),
  `stonewright_replace_section_target_missing` or
  `stonewright_replace_section_target_ambiguous`; it never matches by position
  or element id. A dry run returns the same answer as the write.
- Render the spec `icon` block into the icon widget's `selected_icon` control,
  a value and a library, instead of an `icon` key the schema does not define.
  Make every other block renderer emit only settings the write validation
  accepts: set the typography toggle with a directly given `font_size` on
  heading, paragraph, text editor and button blocks and on the labels of
  `chip-list`, put the button block's padding in `text_padding`, add
  `image_spacing: "custom"` with an image gallery's spacing, use the video
  widget's `poster` (hosted video) or image overlay (embedded video), add the
  `message` and `redirect` expire actions a countdown's message and redirect
  need, drop the unsupported `striped` progress setting and the image box's
  `link_to`, and add the call-to-action `border_radius` only when the live
  widget defines it. A spec test now renders each block type and runs it
  through the write validation against the bundled schemas.
- Evaluate control conditions written as `relation` (`and` or `or`) and
  `terms`, including nested groups, the operators `==`, `!=`, `===`, `!==`,
  `in`, `!in`, `contains`, `!contains`, `<`, `<=`, `>` and `>=`, and a
  `name[key]` sub-value, when a setting is checked against its control. Text
  Editor `column_gap` is accepted when `text_columns` is empty or above 1. A
  condition with an operator that is not defined keeps the control inactive and
  the error names the operator. A flat condition is also met by a
  multiple-value control that contains the expected value.
- Refuse a widget as the parent in `elementor-v3-add-container`,
  `elementor-v3-move-element` and the `add_container` and `move_element`
  operations of `elementor-v3-batch-mutate`, with `parent_not_container`, as
  the widget add paths already do.
- Accept a column whose `_inline_size` is empty (`null` or `""`), which is how
  Elementor stores a column without a custom width. Make the dry run of
  `elementor-build-tree` run the checks the write runs against the stored
  document, and compare the validated settings with the written ones without
  regard to key order, so a rebuilt column that lists `_column_size` and
  `_inline_size` in another order no longer fails at the write.

### Security

- Require a confirmation token bound to its arguments for
  `stonewright-design-normalize-assets` in production-safe mode whenever it
  sideloads (the default). `sideload: false` fetches and stores nothing and needs
  no token. The ability is now listed as a write with a token gate in the
  ability matrix.
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
- Validate the `Origin` header on the MCP routes `mcp/stonewright` and
  `mcp/stonewright-oauth`. A request without an `Origin` passes; a request from
  the site's own origin (home URL or site URL: scheme, host, and port) or from
  an origin listed through the `stonewright_mcp_allowed_origins` filter passes;
  any other origin, including `null`, is refused with 403 and a JSON-RPC error
  body. Command-line and server-side clients, which send no `Origin`, are not
  affected.
- Refuse an ability call when its permission callback is missing, throws, or
  returns anything other than `true` or an error.
- Keep sandbox drafts and backups from running as PHP when a web server that
  ignores `.htaccess` (nginx, IIS, PHP's built-in server) is asked for them by
  URL: they no longer carry a PHP extension, and a deleted draft no longer
  leaves a backup behind.
- Write the activated copy of a sandbox draft with `defined( 'ABSPATH' ) || exit;`
  as its first statement, after any leading `declare` and `namespace`
  statements, so an active sandbox file stops at once when a web server runs it
  outside WordPress. The static analysis gate and the other activation checks
  are unchanged.
- Stop writing stored Unsplash and Pexels API keys and the bridge token into the
  Setup page. The fields stay empty and show that a value is stored, the bridge
  launch values use a placeholder, saving an empty field keeps the stored value,
  a new value replaces it, and a checkbox removes it.
- Require a confirmation token in production-safe mode for every
  `elementor-v3-batch-mutate` write that is not a dry run, bound to the
  arguments of the call. Before, only `remove_element` operations and
  `mode: replace` required one. Dry runs need no token.
- Require a confirmation token in production-safe mode for every
  `elementor-v3-build-page-from-spec` write that is not a dry run, in every
  mode. Before, only `replace` and `replace_section` required one, and
  `append` wrote without it.
- Require the task context token on `elementor-add-icon-list`,
  `elementor-add-price-list`, `elementor-add-read-more`, and
  `elementor-add-search`. A write ability no longer skips the context token
  because its name contains `-list`, `-read`, or `-search`.
- Refuse an Elementor HTML widget write that has no `allow_html_widget: true`
  when the HTML widget site option is on, with
  `html_widget_requires_explicit_approval`.
- Refuse colour and typography values that are not real values. An Elementor
  colour control takes hex (3, 4, 6 or 8 digits), `rgb()`, `rgba()`, `hsl()`,
  `hsla()` with numeric arguments, a CSS colour name, `transparent`,
  `currentColor`, an Elementor global colour variable, or an empty string; a
  `__globals__` binding takes the stored `globals/<type>?id=<id>` form or an
  empty string. Font families are plain names, font weight, transform, style and
  decoration come from fixed lists, and slider, dimension and shadow values need
  numeric parts and a unit from a fixed list. Anything else fails with
  `stonewright_elementor_settings_invalid` and the key. The check runs in every
  Elementor write path, and in `elementor-v3-update-kit-colors`,
  `elementor-v3-update-kit-typography`, `elementor-v3-kit-batch-mutate` and
  `elementor-v3-update-page-settings` (colour, typography and unit keys, and
  kit palette ids).
- Make `elementor-css-regenerate` refuse a post whose stored settings carry
  `;`, braces, `<`, `>`, `url(`, `expression(` or similar under a colour,
  typography, unit or numeric-side key, with `stonewright_elementor_css_unsafe_value`
  and the paths, before any backup, lock or generation. Custom CSS keys stay
  under the custom-code approval gate.
- Stop `stonewright-design-mirror-export` from writing files. It returns each
  page's JSON, filename, byte count and SHA-256 in the result to the
  authenticated caller, up to 1.5 MB of JSON per call, and checks edit
  permission for every post. On plugin update, and on each export call, the
  `uploads/stonewright-mirror` folder from earlier versions gets `index.php`,
  `.htaccess` and `web.config` deny rules, and the regular `.json` files in it
  that carry the export format are deleted; links, subfolders and other files
  stay. The counts are logged.
- Accept only `elementor.com` and hosts ending in `.elementor.com` in
  `elementor-knowledge-refresh`; hosts such as `evilelementor.com` and
  `elementor.com.example.test` are refused. `hub` is checked against its list
  in code, and the final file path is checked to stay inside the knowledge
  store, before anything is written.

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

## Older releases

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
