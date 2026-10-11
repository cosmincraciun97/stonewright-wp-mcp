# Direct mode E2E matrix

Honest results for plugin-less (Direct) mode against core WordPress REST.

## How to run

```bash
cd companion
npm run build
STONEWRIGHT_MODE=direct \
STONEWRIGHT_WP_URL=http://your-site.local \
STONEWRIGHT_WP_USERNAME=admin \
STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
node scripts/e2e-direct.mjs
```

HTTP local URLs are supported. For Application Passwords on plain HTTP, WordPress
requires `WP_ENVIRONMENT_TYPE=local` (or the Application Passwords availability filter).
Direct sends the Application Password over plain http only to `localhost`, a
loopback address, `.localhost`, `.test` and `.local` hosts; another http site is
refused before a request is made unless `STONEWRIGHT_DIRECT_ALLOW_HTTP=on`.

## Capability matrix (Direct vs Plugin)

| Area | Direct (no plugin) | Plugin mode |
|---|---|---|
| Site discover / REST types | Yes | Yes (richer via abilities) |
| Pages/posts list create update delete | Yes (core REST) | Yes |
| Media upload | Yes (core REST). A local `path` must sit inside the working directory, `<state dir>/media` or `STONEWRIGHT_DIRECT_MEDIA_ROOTS`, else `confirm:true`; a `url` must be http or https and resolve to a public address on every redirect hop, and the download connects to the address that was checked. Images, audio, video, PDF, Office, txt and csv only (never SVG, HTML or PHP), at most 25 MiB | Yes |
| Menus | Limited (core REST menus when available) | Yes + WP-CLI |
| Taxonomy terms | Yes | Yes |
| Settings read / update | Yes; update takes an allowlist of keys and a limited `default_role` | Yes |
| Elementor write / DesignSpec | **Limited** — `elementor-data-get/update` without editor (WP-CLI when the local install is the alias site, else REST meta when registered; a different local install is reported as `wp_cli_refused`). Local writes invalidate only post HTML cache, preserve CSS metadata, never run global `flush-css`, and require Plugin-mode CSS closure plus browser verification. Remote cache closure is `not_checked`. No DesignSpec / batch-mutate schema engines | Yes — typed writes plus guarded post-write frontend verification |
| Global styles / FSE | Yes when endpoints exist; updates save a pre-image first and are read back, template and template-part updates need a confirmation in `confirm` mode, and theme.json custom CSS is refused | Yes |
| PHP execute | **No** | Yes (`stonewright/php-execute`, `full` profile only) |
| Skills / memory / learning | **Yes** — local `~/.stonewright/` without plugin; authoritative site memory through the typed bridge when installed | Yes (site-hosted Admin UI) |
| ACF field values | When ACF Show in REST | Yes (typed abilities) |
| SEO meta / head | Yoast head JSON when present | Yes (multi-plugin adapter) |
| CPT / taxonomy registration | **No** | Yes |
| Audit log / backups / tokens | Local redacted JSONL audit with aligned effect/incident fields, before and after hashes and a `direct_verifier` row for each typed write; a pre-image file before five whole-document writes; Elementor data keeps its own file backup; `stonewright-direct-backup-list` and `-restore` list and restore both. **No Rescue snapshots, change sets, or plugin confirmation tokens.** A destructive tool needs a `confirmation_id` bound to the call's arguments (a plain `confirm:true` only on a local site). Details: [What Direct mode protects](#what-direct-mode-protects) | Yes |
| WP-CLI (tokenized companion) | Yes (local CLI, independent of plugin); a command that changes the site is a Direct write with task-start, `confirm` and an audit row | Yes |

## What Direct mode protects

Direct mode writes through core WordPress REST, and through local WP-CLI for
Elementor data when the local install is the alias site. It has no plugin on the
site, so its protections are the companion's own:

- **Session site.** With `STONEWRIGHT_SITE_ALIAS` set, every tool acts on that
  site and refuses a `site` argument that names another alias
  (`site_not_in_session`). Without an alias the registry default applies.
- **Plugin guard.** A write is refused on a site where the Stonewright plugin is
  active (`stonewright/v1` in the REST index), with
  `stonewright_direct_write_blocked_plugin_present`; the message points to Plugin
  mode. This covers every REST write, the media upload, the Elementor WP-CLI
  write and the WP-CLI commands that change the site (`stonewright-wp-cli-run`
  and `stonewright-wp-cli-job-start`). The answer is cached per site for five minutes and cleared on reconnect.
  When the check fails twice the write is refused with
  `stonewright_direct_plugin_check_failed`. Reads and the typed plugin bridge are
  not checked. Recovery only: `STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE=1` plus
  `confirm: true` on the call lets a write through, writes an audit row and adds
  a `warnings` entry to the result.
- **Task-start latch.** Every write needs a `stonewright-task-start` for the same
  alias within 30 minutes, bound to the alias's canonical URL
  (`STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off` opts out).
- **Write mode.** `STONEWRIGHT_DIRECT_WRITES=off` blocks all writes. `confirm`
  (the default for hosts other than localhost, `.local` and `.test`) needs
  a confirmation on destructive tools: plugin, theme, settings, user, comment
  and widget writes, template and template-part creates, updates and deletes,
  `global-styles-update`, `elementor-data-update`, most other deletes, and
  `blueprint-apply` with `post_id`. `elementor-data-update` resolves its mode from
  the site URL like the other tools. Content, media, ACF, menu and
  taxonomy writes need no confirmation.
- **Confirmation bound to the call.** A destructive call without a valid
  confirmation returns `confirmation_required`, a `confirmation_id` and a short
  `summary` of what it will do; the agent repeats the same call, unchanged, with
  `confirmation_id`. The id is an HMAC (a secret that exists only in the
  companion process) over the tool name, the site and the canonical arguments, is
  kept in memory for 10 minutes and works once. Other arguments, another site,
  another tool, an expired or a used id are refused with `confirmation_invalid`
  (the reason is in `reason`, and a fresh id for the call comes back). A plain
  `confirm: true` still counts on a local site (localhost, `.local`, `.test`) and
  with `STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on`; on any other site it is refused with
  a message that says so; `STONEWRIGHT_DIRECT_CONFIRM_LEGACY=off` refuses it on
  local sites too. Theme activation, plugin and user deletes, Application
  Password create and revoke, and a backup restore ask for a confirmation in
  every write mode. Local WP-CLI commands that change the site keep the plain
  `confirm: true` (they run on this machine against a checked install). A
  restart of the companion drops pending ids.
- **Custom-code refusal.** `global-styles-update`, `elementor-data-update`,
  content create, update and autosave, template and template-part create and
  update, `blueprint-apply`, `widget-manage`, `acf-fields-update`, `media-upload`,
  `media-update`, menu and menu-item writes, comment writes, taxonomy-term writes,
  user and Application Password writes and `settings-update` refuse custom code
  in the text they send and send nothing. A page, template or widget that already
  holds custom code cannot be saved through Direct either.
- **Self-protection.** `plugin-deactivate` and `plugin-delete` refuse Stonewright
  and the MCP adapter. `user-delete` refuses the account the session signs in as
  and the last administrator; `user-update` refuses a role change that removes
  administrator from either. `settings-update` accepts only allowlisted keys and
  `default_role` only for `subscriber`, `contributor`, `author` or `editor`.
- **WP-CLI writes.** In a Direct session `stonewright-wp-cli-run` and
  `-job-start` treat a command that is not read-oriented as a write: task-start
  for the site, `confirm: true` in `confirm` mode, a local install that is the
  Direct site, and an audit row (command words only). The shared command policy
  still refuses `eval`, `shell`, `package`, `config`, the database, users and
  roles, and plugin, theme and core installs and updates.
  `-batch-run` is read-oriented only. In Plugin mode these tools are unchanged.
- **Transport and retries.** The Application Password goes over https, or over
  http to a local host only (`STONEWRIGHT_DIRECT_ALLOW_HTTP=on` for a trusted
  private network). A GET is retried once after a network error; a write is
  never retried.
- **Pre-image.** `template-update`, `template-part-update`,
  `global-styles-update`, `content-update` and `blueprint-apply` with `post_id`
  save the current resource first and refuse the write if that fails (20 files
  kept per resource). `elementor-data-update` saves its own full-tree file
  backup. Each file carries a hash of its content; the result returns
  `backup_id`, `backup_path` and `backup_sha256`.
- **List and restore.** `stonewright-direct-backup-list` shows the saved copies
  with an integrity check. `stonewright-direct-backup-restore` needs
  a confirmation (`dry_run:true` previews), refuses a file whose stored hash does
  not match, refuses a resource that no longer exists, saves the current state
  first so the restore can be undone, writes the stored fields back through the
  same REST route (Elementor backups through the Elementor update path), reads
  the resource back and audits it. The names are kept apart from the Plugin
  abilities (`backup-restore`, `site-backup-page`, `rescue-*`).
- **Readback.** After every typed write the resource is read again and the fields
  that were sent are compared with what WordPress returns. The result carries
  `verification` (`verified`, `mismatch` or `not_checked`); a mismatch returns
  `ok:false` with the differing fields, never plain success. The audit row has
  `before_sha256` and `after_sha256`, and a `direct_verifier` row records the
  outcome. The generic REST request tool and local WP-CLI commands are not read
  back.
- **Front-end probe.** After a published page or post is written, or a template
  or template part is updated, the public page is fetched (same pinned,
  redirect-checked fetch as media downloads; only the site's own local host may
  be a non-public address) and checked for HTTP 200, a non-empty body, no PHP
  fatal error text and not the WordPress critical-error page. On by default for a
  local site, off for a remote one (`probe:true` or `STONEWRIGHT_DIRECT_PROBE=on`
  turns it on). A failure is returned with a `rollback_offer`; the saved copy is
  restored automatically only when the call has `rollback_on_probe_failure:true`.
- **Elementor.** `elementor-data-get` returns `hash` (SHA-256 of the tree with
  object keys sorted; not equal to the plugin's tree hash) and `architecture`
  (`v3`, `v4`, `mixed`, `empty`). `elementor-data-update` replaces the whole
  tree. With `expected_hash` (optional, recommended) a tree that changed since the
  read is refused with `revision_conflict` and `current_hash`. The integrity gate
  blocks double-encoded data, malformed nodes, a tree under 85 percent of the
  previous size (when that is over 2 KiB), and `widgetType` remaps. The structure
  checks refuse duplicate ids, a widget or column at the document root (a widget
  the stored document already keeps there stays editable), a widget directly inside
  a widget, `inner-section` as an element type, `settings` that are not an object (an
  empty list is fine), CSS characters in a colour setting, and a tree over 4 MiB,
  5000 elements or 32 levels. A document with Atomic (V4) elements or `$$type`
  settings, or a tree that has them, is not rewritten: Plugin mode types V4
  writes. `force_destructive` (size), `allow_widget_type_remap` and
  `allow_atomic_raw` each skip one check, need a confirmation in every write
  mode, are audited before the write, and are listed in `overrides_used`. The
  result returns `previous_hash` and `hash`. A restore is not held to the
  structure checks. WP-CLI is used only after the local install's `home` or `siteurl` matches the alias URL;
  otherwise the tool uses REST and returns `wp_cli_refused`.
- **Media upload.** Local paths, URLs, types, size, content and file names are
  limited as described in the matrix above. Each URL hop resolves the host once,
  checks every address and connects to the checked address.
- **Audit.** A redacted local JSONL row for refusals and errors on every tool and
  for success on every typed write tool, with the readback hashes and a
  `direct_verifier` row.
- **Task-start incidents.** Incidents are listed as information and close after
  seven days without a new failure.

## What Direct mode does not do yet

- **The probe is narrow.** It covers page, post, template and template-part
  writes. Global styles, Elementor data and the other writes are read back but
  not probed. Elementor HTML and CSS caches are still not checked remotely.
- **Pre-images are partial.** Plugin, theme, settings, menu, media, ACF and
  taxonomy writes are read back but save no before-state, so a restore cannot
  undo them. A backup written by an older version has no stored hash; restoring
  it needs `expected_sha256` (the `backup_sha256` its write returned, which an
  older Elementor write did not).
- **Elementor raw writes have no schema and no lock.** The checks above are
  structural: there is no control schema, no value validation beyond colour
  settings, and no per-post lock, so two writers can race between the hash check
  and the write (`expected_hash` is optional). Atomic (V4) content is refused,
  not typed. The write is read back and the stored tree is compared with the
  written one.
- **WP-CLI in Plugin mode.** `stonewright-wp-cli-run` and `-job-start` apply the
  shared command policy only; the companion adds no task-start latch,
  confirmation or audit row there.
- **Audit is local and unread.** Rows carry a change set id on a verified write,
  before and after hashes, and a `direct_verifier` row, but no tool reads the
  audit file. `stonewright-incident-repair-record` still needs a verifier row
  from a different tool that names the failure it repairs, so verified repair
  returns guidance only until an agent records one.
- **Plugin-only features.** Rescue, change sets, production-safe confirmation
  tokens, OAuth grants, typed Elementor V3 and V4 engines, Design Direction and
  site Context stay Plugin mode. Hard rules in the native registry are enforced
  only where the plugin runs.
- **`task-start` `write_mode`** is the mode the site's tools use: the
  environment variable when set, otherwise `on` for localhost, `.local` and
  `.test` hosts and `confirm` for the rest.

## Live run log

| Date | Target | Plugin | Transport | Result |
|---|---|---|---|---|
| 2026-07-15 | (operator-run) | deactivated for Direct | `http://` | Use `scripts/e2e-direct.mjs`; record pass/fail per step above |

> The automated unit suite (`npm test`) covers Direct config, mode probe, and REST client contracts.
> Full live E2E needs a site + Application Password and is operator-triggered via the script.

## Degradation expectations

- Elementor / DesignSpec tools must not crash: they return a clear install-plugin message.
- With a remote alias and a local WP-CLI path that points at another site, the
  Elementor tools must not read or write through WP-CLI: they answer through REST
  and return `wp_cli_refused`.
- `media-upload` refuses a local file outside the allowed roots without
  `confirm:true`, and refuses a URL that is not http(s) or that resolves (or
  redirects) to a private, loopback, link-local or multicast address. It also
  refuses SVG, HTML, XML, PHP, script, executable and archive files even with
  `confirm:true`, a file over 25 MiB, and a file whose first bytes do not match
  its extension.
- One failed tool call must not make `task-start` return
  `repair_open_incidents_first`; only an open incident with a resource and a
  change set on its failure row does, and any other incident quiet for seven days
  closes.
- Local Elementor writes invalidate only the target post's element/HTML cache,
  preserve CSS metadata, never run a CSS flush, and report visual verification
  as required; Plugin mode is required for guarded CSS closure.
- Remote Elementor writes must not claim PHP-renderer or cache-manager checks
  they could not perform.
- With the plugin re-activated, auto mode selects the plugin MCP proxy and existing proxy tests remain green.
- With the plugin active but its MCP route missing, auto mode stays degraded
  (`plugin_route_missing`) and registers no Direct tools. A site with
  `fallback_policy: never` does the same on a site without the plugin.
- `STONEWRIGHT_MODE=direct` on a plugin site registers Direct with
  `mode_conflict` in the status; reads work and writes are refused.
- When the plugin appears during a Direct session, the next `task-start` reports
  `plugin_detected` and `required_actions: ["switch_to_plugin_mode"]`; an `auto`
  session switches to Plugin mode in the same call when the MCP route answers,
  and a reconnect does the same. Direct writes are refused in the meantime.

## Blueprints (Direct)

| Tool | Direct | Notes |
|---|---|---|
| blueprint-list | Yes | Bundled JSON in companion |
| blueprint-get | Yes | Full spec |
| blueprint-apply | Yes (Gutenberg draft) | Elementor requires plugin |


## Wave 3–5 additions

Direct mode includes comments, user/app-password writes, widgets, health aggregate,
oEmbed/editor utilities, FSE create/delete, WC read, **read-only**
`stonewright-rest-request` (GET), ACF fields get/update when REST-exposed, SEO head
read, pluginless self-improvement (`skill-*`, `memory-list`, `learning-record`,
`task-start`), **Elementor local data tools** (`elementor-status|data-get|data-update`),
**gutenberg-validate**, **agents-md-sync**, error auditing + recurring errors at
task-start, and a **task-start write gate** (opt-out `STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off`).

Protocol e2e for self-improvement runs with zero WordPress credentials
(`tests/direct-selfimprove-e2e.test.ts`). Direct surface: **103** tools.

Lifecycle tests also prove that a new state directory has no user memory,
user-created skills, or audit file; packaged generic built-ins are still
available. Restarting or updating the companion preserves the state already
stored under `~/.stonewright/`. Credential-like memory and skill payloads are
rejected, and Direct audit diagnostic text is redacted before persistence.
Terminal idempotency and incident storage use the resolved canonical target
identity, never the mutable alias, so repointing an alias cannot replay or
suppress another site's terminal event. Marker retention is bounded and
compacted under the same interprocess lock that protects append and stale-lock
recovery. Audit and incident mutations use an exclusive recovery mutex plus
token, host, boot, real per-PID process-start, bounded lease, descriptor, and inode ownership. Recovery
revalidates a stale candidate before atomic quarantine, never renames a
replacement canonical owner, and bounds stale recovery/release artifacts.
Incident repair validation is CAS-bound to generation, update-time, and
occurrence state. The authoritative terminal row establishes its receipt before
incident persistence, whose failure remains a bounded secondary error.
Ordinary REST read failures are recorded once from dispatch context. Always-
confirm theme, plugin, user, Application Password, and skill deletions also
pass the central write-mode and task-start gate before execution.

`task-start` binds learning and the Direct write latch to an alias, normalized
URL, target fingerprint, backend, and expiry. A configured target change
invalidates the latch immediately and requires a new task-start before every
write class, including Application Password creation or revocation.
Authentication, transport, or server failure never silently redirects learning
to local storage; local fallback is allowed only when the typed plugin route is
confirmed absent.

## Install prompts

See [install-prompts.md](install-prompts.md) for copy-paste AI client setup for both plugin and Direct modes.
See [updates.md](updates.md) for companion update and state-preservation steps.
