# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog keeps the five latest releases inline. Older public betas
remain available in their immutable versioned release notes. Pre-beta
development builds were never stable releases.

## [Unreleased]

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

## Older releases

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
