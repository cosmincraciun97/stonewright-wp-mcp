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
  it does not widen the saved MCP surface.
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
  Pages register their hub, tab, title and order once; the sidebar, the tab
  bars, the page headers and the Help tabs read the same list. Rescue and the
  block queue console register through it. The sidebar is ordered by hub:
  Overview, Setup, AI Abilities, Knowledge, Custom code, Activity.
- Add a tab bar under the page header for every hub with more than one page.
  Knowledge holds Skills, Memory, Context, Design and Prompt library; Custom
  code holds Drafts, Library, Active, Crash recovery and Approvals; Activity
  holds Audit log, Block queue and Rescue; Setup holds Setup and Troubleshoot.
  A tab shows a number for open incidents, queued or failed block changes and
  changes needing a rollback, and a user sees only the tabs they can open.
- Add a skip link to the shell, and two Help tabs on every Stonewright page:
  "What is this page?" and "Glossary".
- Add **Overview** and **Setup** links and a **Docs** link to the Stonewright row
  on the Plugins screen, and open the Overview once after the first activation
  (not after a bulk activation, in the network admin, or on a site that has
  already chosen whether Stonewright is on).

### Changed

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
  (for example `stonewright/comment-create`, `stonewright/user-create`,
  `stonewright/plugin-activate`, and `stonewright/menu-create`), and the
  contract lists four abilities that the matrix already listed as writes
  (`stonewright/design-direction-save`, `stonewright/design-direction-capture`,
  `stonewright/design-quality-check`, and
  `stonewright/security-runtime-data-purge`) as `Write`.
- `stonewright/elementor-v4-migrate` declares `destructive` in its annotations.
- Theme-file writes use the Rescue health probe for their check after the
  write. The receipt adds `site_probe` (`passed`, `failed`, `unavailable`, or
  `skipped`) and `incident_id`; `theme_write_smoke_failed` keeps its code and
  carries the probe evidence. A theme-file write reports `verified` and
  `effect_verified` only when that check passed, and `unverified` otherwise.
- Keep Stonewright's own notices where its pages print them, so the Code
  approval warning and guidance, the Setup mode and bridge callouts, and the
  Memory explainer stay visible. A notice no longer removes itself after five
  seconds.
- Start the first heading nearer the top of every Stonewright page: within
  120 px of the top of the screen at 1440 px and within 200 px at 390 px. The
  page header scrolls with the page.
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
- Replace the two-row dark header with one page header (title, a line of
  explanation, status and the page's main action) and the hub's tab bar. The
  WordPress sidebar is the only navigation. The sidebar now says Overview,
  Custom code (was Workflows), Knowledge (the Skills landing page), Prompt
  library (was Prompts), Block queue (was Block Editor Queue) and Activity
  (the Audit log landing page); the page addresses did not change.
- Say **Beta** in words, in the sidebar and in the page header with a visible
  sentence, in place of the 8 to 9 px "EXP" marker and its hover-only tooltip.
- Show the Sandbox tabs (Drafts, Library, Active, Crash recovery) in the Custom
  code tab bar instead of a second row of tabs inside the page, and show the
  block queue console inside the shell with the same header and tabs as the
  other pages. The Rescue page and the console no longer print their own
  heading.
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

### Fixed

- Draw the label of a Setup step that is still to do at full strength instead of
  at 85% opacity, so it reads at 4.5:1 or better.
- Count `site.public_ability_count` in `stonewright-task-start` from the abilities
  WordPress registered, the same count the admin screens show, instead of the
  classes the plugin ships.
- Compare Elementor ability schemas by content when a live schema holds an empty
  object where a recording holds an empty array, so a certified ability is not
  rejected for that difference alone.
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
  in `stonewright/blocks-parse` is its path in `stonewright/blocks-update`,
  `stonewright/blocks-remove`, `stonewright/blocks-insert` and
  `stonewright/blocks-batch-mutate`, also when the stored content has blank
  lines between blocks. A path or insert parent that names no block returns
  `stonewright_invalid_path` and leaves the post unchanged. Nested writes keep
  the wrapper markup of the parent block, and `stonewright/blocks-update`
  refuses to replace the HTML of a block that holds inner blocks.
- Store templates and template parts written by
  `stonewright/fse-write-template` and `stonewright/fse-write-template-part`
  the way WordPress does (slug as the post name, theme as the `wp_theme` term,
  area as the `wp_template_part_area` term), so `get_block_template()`,
  `stonewright/fse-read-template` and `stonewright/fse-update-template` find
  them. An earlier record written under a composite name is found and repaired
  on the next write. `stonewright/fse-update-template` stores a customized
  theme file template the same way.
- Keep `isGlobalStylesUserThemeJSON: true` on every global styles record
  written by `stonewright/fse-write-global-styles` and
  `stonewright/fse-update-global-styles`, the marker WordPress needs to apply
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
