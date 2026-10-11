# Changelog

## [Unreleased]

### Added

- Check Elementor whole-tree writes in Direct mode before they are sent.
  `stonewright-elementor-data-get` returns `hash` (SHA-256 of the tree with object
  keys sorted, computed in the companion; it is not the plugin's tree hash) and
  `architecture` (`v3`, `v4`, `mixed`, `empty`), and takes `root_id`,
  `include_content` (bounded settings per node, no binary bodies), `knownHash`
  (answers `unchanged:true`) and the aliases `max_nodes` and `response_mode`.
  `stonewright-elementor-data-update` takes `expected_hash` (optional,
  recommended): a tree that changed since the read is refused with
  `revision_conflict` and `current_hash`, and nothing is written. It also refuses
  duplicate element ids, a widget or column at the document root (one the stored
  document already keeps there stays editable), a widget directly inside a widget,
  `inner-section` as an element type, `settings` that are not an object, CSS
  characters in a colour setting, and a tree over 4 MiB, 5000 elements or 32
  levels. A document with Atomic (V4) elements or `$$type` settings, or a tree
  that has them, is no longer rewritten as plain JSON: the error names Plugin mode,
  and `allow_atomic_raw:true` replaces the tree anyway. The result returns
  `previous_hash`, `hash` and `overrides_used`. A restore from a saved copy is not
  held to these checks.
- Bind confirmations to the exact call. A destructive Direct call without a valid
  confirmation is refused with `confirmation_required`, a short-lived
  `confirmation_id` and a plain `summary` of what the call will do; the same call
  is repeated with `confirmation_id`. The id is an HMAC over the tool name, the
  site and the canonical arguments, is kept in the companion's memory for 10
  minutes and works once. Other arguments, another site, another tool, an expired
  id and a used id are refused with `confirmation_invalid` and a `reason`. Every
  tool that took `confirm` takes `confirmation_id`. `STONEWRIGHT_DIRECT_CONFIRM_LEGACY`
  (`on`, `off`) sets whether a plain `confirm: true` still counts.
- List and restore the copies Direct saves before it overwrites something.
  `stonewright-direct-backup-list` lists the pre-images (pages, posts, templates,
  template parts, global styles) and the Elementor file backups, newest first,
  with a `backup_id`, the tool, the resource, the time, the size, the file hash
  and an integrity check (`ok`, `tampered`, `unverifiable`, `unreadable`); it
  never returns their content. `stonewright-direct-backup-restore` puts one back.
  It needs `confirm:true` (`dry_run:true` shows what would change and needs none),
  checks the hash stored in the file, checks that the resource still exists
  (nothing is recreated), saves the current state first so the restore can be
  undone, writes the stored fields back through the same REST route (Elementor
  backups through the Elementor update path), reads the resource back and
  compares it, and writes audit rows. Pre-images now carry a hash of their body
  and return a `backup_id`; Elementor file backups carry a hash of the document,
  the site and the collection, and return `backup_id` and `backup_sha256`. A
  backup written by an older version has no stored hash and is restored only with
  `expected_sha256`. The tools are named apart from the Plugin abilities
  (`backup-restore`, `site-backup-page`, `rescue-*`) and sit on the `gutenberg`,
  `content-model`, `elementor-design`, `site-admin` and `full` profiles, not on the
  bootstrap or essential surface. The Direct tool count is 105; the `full` budget
  in `tokens:measure` is 105.
- Read every typed Direct write back. After the write the resource is read again
  (`context=edit`) and the fields that were sent are compared with what WordPress
  returns, the way WordPress returns them. The result carries `verification`
  (`verified`, `mismatch`, `not_checked`) with the compared and differing fields
  and the before and after hashes; a mismatch is returned as `ok:false`,
  `error:"verification_mismatch"`, never as success, with `restore_with` when a
  saved copy exists. The write audit row carries `before_sha256`, `after_sha256`,
  `resource_type` and a change set id, and a `direct_verifier` row records the
  outcome. This covers content, template, template-part, global-styles, blueprint,
  Elementor, plugin, theme, settings, media, taxonomy, menu, comment, user,
  application-password, widget and ACF writes; the generic REST request tool and
  local WP-CLI commands are not read back.
- Check the public page after a page, post, template or template-part write.
  Direct fetches the permalink (the home page for a template) and checks for HTTP
  200, a non-empty body, no PHP fatal or parse error text and not the WordPress
  critical-error page, through the same pinned, redirect-checked fetch the media
  download uses (only the site's own local host may be a non-public address). The
  probe is on by default for a local site and off for a remote one; `probe:true`,
  `probe:false` and `STONEWRIGHT_DIRECT_PROBE=on|off` change that. A failed check
  returns `ok:false`, `site_status:"probe_failed"` and a `rollback_offer`; the saved
  copy is restored only when the call has `rollback_on_probe_failure:true`
  (`site_status:"rolled_back"`). A page that cannot be reached is reported as
  `unavailable`, not as a failure.

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
- Hold a local WP-CLI command that changes the site to the Direct write gate in a
  Direct session. `stonewright-wp-cli-run` and `stonewright-wp-cli-job-start` take
  an optional `confirm` and, for a command that is not read-oriented, need
  `stonewright-task-start` for the site, `confirm: true` when writes are in
  `confirm` mode, and a local WordPress whose `home` or `siteurl` is the Direct
  site, and they write an audit row (command words only; a job writes it when it
  ends). The commands the shared command policy refuses stay refused, with the
  same message as in Plugin mode, and a refusal writes a blocked audit row.
  `stonewright-wp-cli-batch-run` is unchanged (read-oriented commands only). In
  Plugin mode the tools behave as before.
- Refuse custom code in the remaining Direct writes: `acf-fields-update` (values
  at any depth, and a custom CSS field), `media-update` and `media-upload` (title,
  alt text, caption; checked before any download or file read), menu and menu-item
  writes (names, descriptions, titles, URLs), comment writes, taxonomy-term writes
  (name, description), `user-create` and `user-update` (display name),
  `app-password-create` (name) and `settings-update` (string values). The refusal
  is the same approval envelope as for content writes; nothing is sent.
- Protect what Direct stands on. `plugin-deactivate` and `plugin-delete` refuse
  Stonewright and the MCP adapter (`self_protection`); a Stonewright folder with a
  different name is recognised by its text domain. `user-delete` refuses the
  account whose Application Password the session uses (found with
  `/wp/v2/users/me`) and the last administrator, and `user-update` refuses a role
  change that takes administrator from either (`self_protection`,
  `last_administrator`).
- Limit `settings-update` to an allowlist of keys (the plugin's list, under the core
  REST names and the option names). The site address, the administrator email and
  any other key are refused with `settings_key_not_allowed`, and `default_role`
  takes only `subscriber`, `contributor`, `author` or `editor`
  (`settings_value_not_allowed`). A call that carries a refused key writes nothing.
- Refuse to send the Application Password over plain `http://` to a host that is not
  local (`localhost`, loopback addresses, `.localhost`, `.test`, `.local`), with
  `insecure_transport` and before any request is made.
  `STONEWRIGHT_DIRECT_ALLOW_HTTP=on` allows plain http for a trusted private network.

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
- Edit the blocks of a page or post by path in Direct mode.
  `stonewright-blocks-batch-mutate` takes the Plugin ability's arguments
  (`post_id`, `operations`, `expected_content_hash`, `dry_run`) and inserts,
  updates and removes blocks in the parsed block markup. A dry run writes nothing
  and returns `before_hash` and `after_hash`; a write needs `expected_content_hash`
  set to that `before_hash` and is refused as `content_conflict` if the content
  changed, checked again just before the write. All operations apply or none does.
  Blocks that were not touched are written back byte for byte. Direct saves a copy
  of the post first (`backup_id`), reads the post back (`verification`) and probes
  the page like `content-update`. It refuses custom code that the operations add,
  a block name the site does not have (when `/wp/v2/block-types` can be read),
  block comments inside markup and a post whose block markup is already broken.
  In `confirm` mode a write needs a confirmation bound to the exact operations and
  hash (`confirmation_id`), a dry run none.
  There is no `move`, no `insert_section` and no browser finalizer; `attrs` alone
  are accepted only on a block that has no markup of its own. The tool is on the
  `gutenberg` and `full` profiles.
- Delete a lesson from Direct memory. `stonewright-memory-delete` takes the `id`
  that `memory-list` and `task-start` show and always needs a confirmation (a
  `confirmation_id` from a first call, or `confirm:true` on a local site). It
  removes a local lesson only; a lesson in the plugin's site memory is removed in
  Plugin mode. It is on the `site-admin` and `full` profiles.
- Offer to record a lesson after a write that failed is fixed. When a Direct write
  to a resource fails, or its readback reports a mismatch, and a later write to the
  same resource on the same site succeeds, the successful answer carries a
  `learning_offer` that asks the agent to tell the user what failed and what fixed
  it, and to call `stonewright-learning-record` only if the user agrees. The
  failures are remembered in the running companion only; no new store was added.
  `task-start` also returns `required_followups`, which includes the correction
  trigger.
- Rank Direct memory by score. A lesson carries a `confidence` (0 to 1, a default
  by `source` when none is given; `learning-record` takes `confidence`) and a
  `last_verified` time that moves when the same lesson is recorded again. The five
  `memory_highlights` of `task-start` are the best by score (confidence, reduced by
  the time since last confirmed; a stale lesson sorts last) and carry the lesson
  `id`; an old or doubtful lesson is marked `verify_before_use`.
  `stonewright-memory-list` takes `order: "score"` and shows `confidence`,
  `last_verified` and `score`. Memory files written by earlier versions have
  neither field and keep working.
- Parse the blocks in `stonewright-gutenberg-validate`. The comment delimiters
  must balance and match, each block's attributes must be a valid JSON object, and,
  where the site's block list can be read, each block name must be one the site has
  (a warning, because a plugin or theme may be inactive). The answer lists
  `diagnostics` with line numbers, `unknown_blocks`, `custom_code` (the custom-code
  guard run over every block, `html` and classic blocks included) and the
  `content_hash` that `stonewright-blocks-batch-mutate` takes.

### Changed

- A plain `confirm: true` no longer confirms a destructive Direct call on a site
  that is not local. On localhost, `.local` and `.test` it is accepted as before;
  on any other site the call is refused with `confirmation_required`, a message
  that says so, and a `confirmation_id` to repeat the call with.
  `STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on` restores the old behavior everywhere and
  `off` refuses the plain flag on local sites too. Theme activation, plugin and
  user deletes, Application Password create and revoke, and a backup restore ask
  for a confirmation in every write mode, as before, and now through the same
  mechanism. Local WP-CLI commands that change the site keep `confirm: true`.
- `force_destructive`, `allow_widget_type_remap` and the new `allow_atomic_raw` on
  `stonewright-elementor-data-update` now need a confirmation in every write
  mode (they used to need none on a local site), are written to the audit log
  before the write, and are listed in the result as `overrides_used`.
  `overrides_requested` shows the flags the call set.
- `stonewright-blueprint-apply` reads the write settings from the environment of
  the server instead of the process environment.
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
- Direct REST writes (POST, PUT, PATCH, DELETE) are never retried after a network
  error; a GET is retried once, and the system code is now read from the cause of
  a failed fetch as well.
- `stonewright-media-upload` with a `url` connects to the address it checked. Each
  hop resolves the host once, requires every address to be public, and connects to
  those addresses with the original Host header and TLS server name, so a host that
  answers differently the second time cannot reach a private address.
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
  yet. The rule that the companion never calls WordPress REST write endpoints now
  applies to Plugin mode, with Direct mode as the documented exception.
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
- Check what `stonewright-gutenberg-compose` returns. The markup is read back as
  blocks: unbalanced comments or attributes that are not valid JSON are an error,
  an `html` block cannot contain block comments, and custom code in `html` or `raw`
  markup is refused with the `approval_required` envelope and no markup. Block
  attributes are written the way WordPress writes them, so a value cannot end its
  comment.
- Save a Direct skill as a disabled candidate. `stonewright-skill-save` and the
  `draft_skill` of `stonewright-learning-record` never activate a skill: a skill
  is stored disabled and is not offered at task start, `enabled:true` is refused,
  and saving over an active skill is refused, because Direct has no human approval
  step. Activation is an administrator's approval in Plugin mode.
- Keep an existing page's status in Direct `stonewright-blueprint-apply`. With
  `post_id` and no `status` the page keeps the status it has, as in Plugin mode;
  a new page without `status` is still a draft.
- Record a `learning-record` call that names no `source` as `agent-recorded`
  instead of `explicit-user-request`.
- Raise the Direct `full` budget in `tokens:measure` to 105 for
  `stonewright-memory-delete` and `stonewright-blocks-batch-mutate`; the bootstrap
  (8) and essential (22) budgets are unchanged.
- Correct the documents that said Direct has no restore tool or after-write
  verification (`docs/companion.md`, `docs/architecture.md`,
  `docs/install-prompts.md`, `docs/onboarding.md`, `docs/why-stonewright.md`,
  `docs/installation.md`, `docs/rescue.md`, the Claude Code and Cursor guides and
  the README table). Direct lists and restores its saved copies, reads every typed
  write back and can check the public page after a page, post or template write.
- `stonewright-tool-profile` in the companion does not switch to the `full` profile.
  `action=activate` with `profile=full` returns the plugin's refusal (`approval_required`,
  `agent_must_stop`, the settings link) as an error, or its own when no plugin answers,
  and no longer falls back to a local catalog that reports success. A profile hint in
  a tool result moves the companion onto `full` only when the site's tool surface is
  Full. `STONEWRIGHT_MCP_TOOL_PROFILE=full`, set by the person who runs the companion,
  is unchanged. The server instructions and `doctor` say who chooses `full`.

### Fixed

- Direct `stonewright-direct-backup-restore` lists the meta keys that were added
  after the backup as `meta_not_in_backup` (also in a dry run). A restore writes
  the saved meta back over the current meta and deletes no key, so
  `matches_backup` can be false after a correct restore.
- Direct `stonewright-learning-record` screens the lesson with the same
  instruction screen as the plugin's memory. A lesson that tells an agent to
  override the plugin rules, to disable confirmation tokens or to send credentials
  elsewhere is stored with the status `needs_review` (`needs_review: true`,
  `review_rules`): it is not offered at task start, a `draft_skill` with it is
  not saved, and recording the same text again does not clear it. A person clears
  it by deleting it or setting its status to `active` in the local memory file.
  `stonewright-incident-repair-record` screens its repair recipe the same way: the
  incident is still resolved, the lesson is stored as `needs_review` and the
  answer says `learning_status: "needs_review"`, `needs_review: true`.
- `stonewright-elementor-data-update` resolves its write mode from the target site
  like the other Direct tools: a local `.test`, `.local` or localhost site no
  longer asks for `confirm: true` unless `STONEWRIGHT_DIRECT_WRITES` says so.
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
- Make Direct tools act on the session's site. With `STONEWRIGHT_SITE_ALIAS` set,
  Direct tools took their site from the registry default instead of the session's
  alias, and its memory, incident record and task-start latch followed the default
  too. Every Direct tool now uses the session's site, and a `site` argument that
  names another alias is refused with `site_not_in_session`
  (`stonewright-task-start` returns the same refusal). With no alias set nothing
  changes.
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
