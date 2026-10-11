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

## Capability matrix (Direct vs Plugin)

| Area | Direct (no plugin) | Plugin mode |
|---|---|---|
| Site discover / REST types | Yes | Yes (richer via abilities) |
| Pages/posts list create update delete | Yes (core REST) | Yes |
| Media upload | Yes (core REST). A local `path` must sit inside the working directory, `<state dir>/media` or `STONEWRIGHT_DIRECT_MEDIA_ROOTS`, else `confirm:true`; a `url` must be http or https and resolve to a public address on every redirect hop. Images, audio, video, PDF, Office, txt and csv only (never SVG, HTML or PHP), at most 25 MiB | Yes |
| Menus | Limited (core REST menus when available) | Yes + WP-CLI |
| Taxonomy terms | Yes | Yes |
| Settings read | Yes | Yes |
| Elementor write / DesignSpec | **Limited** — `elementor-data-get/update` without editor (WP-CLI when the local install is the alias site, else REST meta when registered; a different local install is reported as `wp_cli_refused`). Local writes invalidate only post HTML cache, preserve CSS metadata, never run global `flush-css`, and require Plugin-mode CSS closure plus browser verification. Remote cache closure is `not_checked`. No DesignSpec / batch-mutate schema engines | Yes — typed writes plus guarded post-write frontend verification |
| Global styles / FSE | Yes when endpoints exist; updates save a pre-image first, template and template-part updates need `confirm:true` in `confirm` mode, and theme.json custom CSS is refused | Yes |
| PHP execute | **No** | Yes (`stonewright/php-execute`, `full` profile only) |
| Skills / memory / learning | **Yes** — local `~/.stonewright/` without plugin; authoritative site memory through the typed bridge when installed | Yes (site-hosted Admin UI) |
| ACF field values | When ACF Show in REST | Yes (typed abilities) |
| SEO meta / head | Yoast head JSON when present | Yes (multi-plugin adapter) |
| CPT / taxonomy registration | **No** | Yes |
| Audit log / backups / tokens | Local redacted JSONL audit with aligned effect/incident fields; a pre-image file before five whole-document writes; Elementor data keeps its own file backup. **No Rescue snapshots, change sets, or confirmation tokens, and no tool restores a backup.** `confirm:true` is a flag the caller sets for destructive tools. Details: [What Direct mode protects](#what-direct-mode-protects) | Yes |
| WP-CLI (tokenized companion) | Yes (local CLI, independent of plugin) | Yes |

## What Direct mode protects

Direct mode writes through core WordPress REST, and through local WP-CLI for
Elementor data when the local install is the alias site. It has no plugin on the
site, so its protections are the companion's own:

- **Task-start latch.** Every write needs a `stonewright-task-start` for the same
  alias within 30 minutes, bound to the alias's canonical URL
  (`STONEWRIGHT_DIRECT_REQUIRE_TASK_START=off` opts out).
- **Write mode.** `STONEWRIGHT_DIRECT_WRITES=off` blocks all writes. `confirm`
  (the default for hosts other than localhost, `.local` and `.test`) needs
  `confirm: true` on destructive tools: plugin, theme, settings, user, comment
  and widget writes, template and template-part creates, updates and deletes,
  `global-styles-update`, `elementor-data-update`, most other deletes, and
  `blueprint-apply` with `post_id`. `elementor-data-update` reads only the
  environment variable, not the site URL, so it asks for `confirm: true` on local
  hosts too unless `STONEWRIGHT_DIRECT_WRITES=on`. Content, media, ACF, menu and
  taxonomy writes need no confirmation. `confirm: true` is a flag the caller
  sets; it is not bound to the call's arguments.
- **Custom-code refusal.** `global-styles-update`, `elementor-data-update`,
  content create, update and autosave, template and template-part create and
  update, `blueprint-apply` and `widget-manage` refuse custom code and send
  nothing. A page, template or widget that already holds custom code cannot be
  saved through Direct either.
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
  limited as described in the matrix above.
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
- **Custom-code screening is partial.** `acf-fields-update`, `media-upload`,
  `media-update`, menu, comment, taxonomy-term, settings and user writes are not
  screened for custom code.
- **Pre-images are partial.** Plugin, theme, settings, menu, media, ACF and
  taxonomy writes save none.
- **DNS rebinding is not pinned.** `media-upload` resolves a URL's host before
  the request; a host that changes its answer between the check and the download
  is not caught.
- **Elementor raw writes are unchecked beyond the gate above.** There is no
  schema validation, unique-id check, element or depth cap, per-post lease or
  base hash (a concurrent edit is overwritten), and no readback of
  `_elementor_data`.
- **`stonewright-wp-cli-run` and `-batch-run`** block only `eval`, `eval-file`,
  `shell`, `package`, `--exec`, `--require` and `--prompt`. They have no
  task-start latch, confirmation, audit row or target check, in either mode.
- **REST retries.** The REST client retries a request once after some network
  errors, for every method, so a retried create can be duplicated.
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
  `.test` hosts and `confirm` for the rest. `elementor-data-update` is the one
  exception, as described under Write mode.

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
