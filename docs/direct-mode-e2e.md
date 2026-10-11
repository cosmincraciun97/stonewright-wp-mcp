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
| Global styles / FSE | Yes when endpoints exist | Yes |
| Elementor write / DesignSpec | **Limited** — `elementor-data-get/update` without editor (WP-CLI when the local install is the alias site, else REST meta when registered; a different local install is reported as `wp_cli_refused`). Local writes invalidate only post HTML cache, preserve CSS metadata, never run global `flush-css`, and require Plugin-mode CSS closure plus browser verification. Remote cache closure is `not_checked`. No DesignSpec / batch-mutate schema engines | Yes — typed writes plus guarded post-write frontend verification |
| Global styles / FSE | Yes when endpoints exist; updates save a pre-image first, template and template-part updates need `confirm:true` in `confirm` mode, and theme.json custom CSS is refused | Yes |
| Elementor write / DesignSpec | **Limited** — `elementor-data-get/update` without editor (WP-CLI local or REST meta when registered). Local writes invalidate only post HTML cache, preserve CSS metadata, never run global `flush-css`, and require Plugin-mode CSS closure plus browser verification. Remote cache closure is `not_checked`. No DesignSpec / batch-mutate schema engines | Yes — typed writes plus guarded post-write frontend verification |
| PHP execute | **No** | Yes (`stonewright/php-execute`) |
| Skills / memory / learning | **Yes** — local `~/.stonewright/` without plugin; authoritative site memory through the typed bridge when installed | Yes (site-hosted Admin UI) |
| ACF field values | When ACF Show in REST | Yes (typed abilities) |
| SEO meta / head | Yoast head JSON when present | Yes (multi-plugin adapter) |
| CPT / taxonomy registration | **No** | Yes |
| Audit log / backups / tokens | Direct JSONL with aligned effect/incident fields. **No Rescue snapshots and no confirmation tokens.** Destructive tools ask for `confirm:true` (in `confirm` write mode), and so do `template-update`, `template-part-update`, and `blueprint-apply` over an existing page. Before `template-update`, `template-part-update`, `global-styles-update`, `content-update`, and `blueprint-apply` with `post_id`, the current resource is saved to `~/.stonewright/backups/<site alias>/<tool>/<resource id>/<timestamp>.json` (20 kept per resource) and the result returns `backup_path` and `backup_sha256`; the write is refused when that backup fails. An Elementor data update saves its own file backup. No tool restores a backup. **Custom-code boundary:** Direct refuses theme.json `styles.css` and per-block `css`, Elementor custom CSS keys and HTML-type widgets, and script, style, iframe, object, embed, link, or meta http-equiv elements, inline event handlers, and `javascript:` URLs in content, template, and blueprint markup (an `approval_required` result; this needs Plugin mode and its custom-code approval) | Yes |
| WP-CLI (tokenized companion) | Yes (local CLI, independent of plugin) | Yes |

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
