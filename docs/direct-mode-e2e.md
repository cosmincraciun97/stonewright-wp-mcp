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
| Global styles / FSE | Yes when endpoints exist; updates save a pre-image first, template and template-part updates need `confirm:true` in `confirm` mode, and theme.json custom CSS is refused | Yes |
| PHP execute | **No** | Yes (`stonewright/php-execute`, `full` profile only) |
| Skills / memory / learning | **Yes** — local `~/.stonewright/` without plugin; authoritative site memory through the typed bridge when installed | Yes (site-hosted Admin UI) |
| ACF field values | When ACF Show in REST | Yes (typed abilities) |
| SEO meta / head | Yoast head JSON when present | Yes (multi-plugin adapter) |
| CPT / taxonomy registration | **No** | Yes |
| Audit log / backups / tokens | Local redacted JSONL audit with aligned effect/incident fields; a pre-image file before five whole-document writes; Elementor data keeps its own file backup. **No Rescue snapshots, change sets, or confirmation tokens, and no tool restores a backup.** `confirm:true` is a flag the caller sets for destructive tools. Details: [What Direct mode protects](#what-direct-mode-protects) | Yes |
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
  mode. This covers every REST write, the media upload and the Elementor WP-CLI
  write. The answer is cached per site for five minutes and cleared on reconnect.
  When the check fails twice the write is refused with
  `stonewright_direct_plugin_check_failed`. Reads and the typed plugin bridge are
  not checked. Recovery only: `STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE=1` plus
  `confirm: true` on the call lets a write through, writes an audit row and adds
  a `warnings` entry to the result. `stonewright-wp-cli-run` and its batch and job
  forms are not covered by this check.
- **Task-start latch.** Every write needs a `stonewright-task-start` for the same
  alias within 30 minutes, bound to the alias's canonical URL
  (`STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off` opts out).
- **Write mode.** `STONEWRIGHT_DIRECT_WRITES=off` blocks all writes. `confirm`
  (the default for hosts other than localhost, `.local` and `.test`) needs
  `confirm: true` on destructive tools: plugin, theme, settings, user, comment
  and widget writes, template and template-part creates, updates and deletes,
  `global-styles-update`, `elementor-data-update`, most other deletes, and
  `blueprint-apply` with `post_id`. `elementor-data-update` resolves its mode from
  the site URL like the other tools. Content, media, ACF, menu and
  taxonomy writes need no confirmation. `confirm: true` is a flag the caller
  sets; it is not bound to the call's arguments.
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
  backup.
- **Elementor.** The integrity gate blocks double-encoded data, malformed nodes,
  a tree under 85 percent of the previous size (when that is over 2 KiB), and
  `widgetType` remaps; `force_destructive` and `allow_widget_type_remap` bypass
  the size and remap checks. WP-CLI is used only after the local install's `home` or `siteurl` matches the alias URL;
  otherwise the tool uses REST and returns `wp_cli_refused`.
- **Media upload.** Local paths, URLs, types, size, content and file names are
  limited as described in the matrix above. Each URL hop resolves the host once,
  checks every address and connects to the checked address.
- **Audit.** A redacted local JSONL row for refusals and errors on every tool and
  for success on most write tools (`blueprint-apply` and `acf-fields-update`
  write none on success).
- **Task-start incidents.** Incidents are listed as information and close after
  seven days without a new failure.

## What Direct mode does not do yet

- **No restore tool.** Pre-images and Elementor backups are files under
  `~/.stonewright/backups/`; nothing lists or restores them.
- **No after-write verification.** REST writes are not read back, and there is no
  front-end probe or rollback. A local Elementor write reads back the element
  cache only; a remote one reports `not_checked`.
- **Pre-images are partial.** Plugin, theme, settings, menu, media, ACF and
  taxonomy writes save none.
- **Elementor raw writes are unchecked beyond the gate above.** There is no
  schema validation, unique-id check, element or depth cap, per-post lease or
  base hash (a concurrent edit is overwritten), and no readback of
  `_elementor_data`.
- **WP-CLI in Plugin mode.** `stonewright-wp-cli-run` and `-job-start` apply the
  shared command policy only; the companion adds no task-start latch,
  confirmation or audit row there.
- **Audit proves little.** Audit rows carry no change set id or before and after
  hashes, and no tool reads the audit file. No Direct tool writes the
  `direct_verifier` row that `stonewright-incident-repair-record` needs, so
  verified repair returns guidance only.
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
(`tests/direct-selfimprove-e2e.test.ts`). Direct surface: **101** tools.

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
