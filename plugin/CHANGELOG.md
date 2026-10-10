# Changelog

## [Unreleased]

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
- Add `pre_restore_snapshot_id` to the result of `change-restore`: the snapshot
  of the state before the restore, which undoes it.
- Add the storage layer for change history, with no ability or page using it
  yet. A `stonewright_changes` table (created and upgraded with the other
  tables) records each change with its ability, user, resource, status and the
  hash and size of the content before and after it. The content is kept as
  gzip blobs in `uploads/stonewright-state/blobs/`, named by sha256, with deny
  files on every folder, size limits per blob and in total, and an integrity
  check on read. Passwords, keys and salts, OAuth tokens, `wp-config.php` and
  options on a list of secret names are never stored, and other credentials
  found in content are masked. A daily event keeps 90 days, 500 changes and
  100 MB by default (options and filters change the limits) and never deletes
  an open change. Removing all plugin data also drops the table, the event and
  the blobs.
- Record the changes that abilities make to posts in the change ledger, with the
  post as it was before and after the write: fields, slug, parent, featured
  image, terms, Elementor keys, page template, SEO keys and ACF values. Abilities
  that create posts, and `content-bulk-upsert-posts`, which overwrote posts
  without a snapshot, are recorded too. Internal groundwork: no page or ability
  shows or undoes these records yet, and a ledger failure never changes a write.
- Record changes to code in the change history, with no page or ability reading
  them yet. Theme file writes (patch, `theme.json` and backup restore),
  Customizer CSS updates (the first save included), WPCode and Code Snippets
  saves, and sandbox write, edit, delete, activate and deactivate each leave a
  row with the content before and after, and for a snippet its title, language,
  active state and scope. A file the change created has no before image, and
  undoing it deletes the file. Restore functions for each of these write the
  before image back through the path the original write used, so its checks
  still apply, and record the restore under the change. A history that cannot
  record never stops or changes the write.
- Add the Changes page (**Stonewright > Activity > Changes**, marked EXP). It lists
  the changes recorded in the change history, newest first, with filters for
  family, resource, ability, user, date range and result, and a "restorable
  only" switch. **View diff** opens one change in a drawer: the content before
  against after as lines with line numbers, block changes, Elementor elements or
  changed fields, a note when the diff was cut or values were masked, the reason
  a change cannot be restored, and the rollbacks and redos that follow it.
  Passwords, keys and tokens show as `[redacted]`. Rescue links to the page and,
  for a change that has a history record, to its diff.
- Add **Undo** and **Redo** to the Changes page. **Undo this change** opens a
  dialog with the diff of what the undo would change, warnings for a newer
  change to the same item and for an item that was edited since the change
  (which needs a ticked box to overwrite), and the same nonce, administrator
  check and production-safe confirmation token as Rescue. The undo restores the
  item as it was, probes the site and puts the earlier state back if the site
  stops loading, and records a rollback row that links back to the change; a
  rolled-back change offers **Redo**. It covers posts and their kinds, options,
  menus, widgets, theme files, snippets, the Customizer CSS and sandbox files.
  Undoing or redoing code needs an administrator at the page: an agent or
  WP-CLI call gets the approval-required answer. A rollback of a change that
  Rescue also lists goes through the Rescue path, so incidents are rolled back
  as before.
- Record the changes that abilities make to options, menus and widgets in the
  change ledger: site settings, the front page, custom instructions, custom post
  types, taxonomies, ACF field groups, the tool profile, theme chrome and the
  brand kit; menus with their items in order, parents and locations (a deleted
  menu keeps its full image); and sidebars with their widgets. Each ability has
  a list of the options it may write, and names on the secret list are never
  read or stored. Internal groundwork: no page or ability shows or undoes these
  records yet, and a ledger failure never changes a write.
- Record changes to users, comments, media, WooCommerce, themes, plugins, site
  memory, skills, design directions, `php-execute` and some settings writes in the
  change history, with no page or ability reading them yet. A user change keeps
  the account fields, roles and capabilities before and after, and never a
  password, session token, application password or other secret user data; a
  changed password and a created or revoked application password are recorded
  as events with no content. Media keeps the fields, alt text and metadata (an
  upload is a create, undone by deleting the attachment), comments and
  WooCommerce products, variations, terms and attributes keep the full content
  of what a delete removes, a theme switch keeps the previous theme, and a
  memory delete keeps the whole row so that it can be put back. Skills and
  design directions link to the revision their own stores keep and keep no
  second copy. A plugin delete, a `php-execute` run (only the hash of its code)
  and a settings write from an admin screen or route are recorded and marked
  not restorable, with the reason. Restore functions write the before image back
  through the functions the ability used. A history that cannot record never
  stops or changes the write.
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

### Fixed

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
