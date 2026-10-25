# Changelog

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
  table schema versions, the migration flags, the dangerous-function notice, the
  registered widget slugs, the widget scan verdicts, and the locked domain with
  its mismatch record. An existing site is switched on its next version change
  or when the plugin is activated again.
- Run the two lesson migrations once on upgrade and then once a day, with at
  most five attempts, instead of scanning the memory table on every
  request. An attempt that a resource limit killed counts as an attempt.
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
  or modification time changes, and after an update, instead of reading and
  hashing it on every wp-admin request. A repair that failed is tried again
  after five minutes.
- No ability changed its contract, and no permission, backup, token,
  validation, or audit gate changed.

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

- [1.0.0-beta.16](../docs/releases/1.0.0-beta.16.md)
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
