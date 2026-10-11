# Companion

The companion is a Node.js sidecar for local MCP transport, optional HTTP MCP
transport, proxying to the WordPress MCP endpoint, health checks, optional
HTTP proxying, and tokenized WP-CLI.

## Endpoints

- `GET /health`
- `POST /mcp`
- `POST /wp-cli/status`
- `POST /wp-cli/discover`
- `POST /wp-cli/run`
- `POST /wp-cli/batch`
- `POST /wp-cli/job-start`
- `POST /wp-cli/job-status`

## WP-CLI Runtime Routing

The companion runs WP-CLI through `execFile` with argv tokens. It blocks
WP-CLI PHP and shell entry points: `wp eval`, `wp eval-file`, `wp shell`,
`wp package`, `--exec`, and `--require`. On the `full` profile, use
`stonewright/php-execute` for direct PHP runtime snippets inside WordPress; no
other profile has it.

Use `STONEWRIGHT_WP_ROOT` or `STONEWRIGHT_WP_ALLOWED_ROOTS` to restrict working
directories. `STONEWRIGHT_WP_ROOT` is optional; when set, it must be the
absolute WordPress install folder containing `wp-config.php`, not the plugin
folder or site URL.

## Configuration

| Variable | Description |
|---|---|
| `COMPANION_BEARER_TOKEN` | Required outside local/dev mode |
| `COMPANION_ALLOWED_ORIGINS` | Required outside local/dev mode |
| `STONEWRIGHT_HTTP_ENABLE` | Set to `1` to enable the optional HTTP bridge; stdio MCP does not need it |
| `PORT` | Port for the optional HTTP transport; ignored unless `STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` |
| `STONEWRIGHT_HTTP_REQUIRED` | Set to `1` only when an HTTP bridge bind failure should make startup fail; this also enables the bridge |
| `STONEWRIGHT_WP_URL` | WordPress site URL; the companion derives `/wp-json/mcp/stonewright` when `STONEWRIGHT_MCP_URL` is absent |
| `STONEWRIGHT_WP_USERNAME` | WordPress username for Application Password auth |
| `STONEWRIGHT_WP_APP_PASSWORD` | WordPress Application Password |
| `STONEWRIGHT_SITE_ALIAS` | Multi-site registry alias; at startup the companion loads only this site's URL/username/credential into `STONEWRIGHT_WP_*`. Direct tools then act on this site only and refuse a `site` argument that names another |
| `STONEWRIGHT_DIRECT_ALLOW_ON_PLUGIN_SITE` | Recovery only. `1` lets a Direct write run on a site where the Stonewright plugin is active, when the call also passes `confirm: true`; each use is audited. Direct otherwise refuses writes on a plugin site |
| `STONEWRIGHT_DIRECT_CONFIRM_LEGACY` | `on` accepts a plain `confirm: true` as the confirmation for a destructive Direct call on any site; `off` refuses it everywhere, local sites included. Unset, it counts on localhost, `.local` and `.test` only; elsewhere the refused call returns a `confirmation_id` to repeat the call with |
| `STONEWRIGHT_SITES_FILE` | Override path for the multi-site registry (default `~/.stonewright/sites.json`) |
| `STONEWRIGHT_CONFIGURED_PACKAGE` | Optional exact official package source used for authenticated `/health` configured-package evidence; invalid values are omitted |
| `STONEWRIGHT_MCP_TOOL_PROFILE` | Initial/fallback client-visible surface. The unknown-client fallback is `essential-static`; generated known-client configs also set `essential-static` and save `essential` as the WordPress surface. Normal plugin-mode clients may follow the bootstrap/essential/full surface saved in WordPress Setup; `low-tools` and specialist profiles remain explicit overrides. `full` is never selected implicitly. |
| `STONEWRIGHT_MCP_TOOL_PROFILE_LOCK` | Set to `1` only when the environment profile must override the WordPress Setup preference. |
| `STONEWRIGHT_MCP_URL` | Explicit WordPress MCP endpoint override |
| `WP_API_USERNAME` | Legacy alias for `STONEWRIGHT_WP_USERNAME` |
| `WP_API_PASSWORD` | Legacy alias for `STONEWRIGHT_WP_APP_PASSWORD` |
| `STONEWRIGHT_MCP_AUTHORIZATION` | Optional full Authorization header override |
| `STONEWRIGHT_CREDENTIAL_STORE` | Per-project JSON file for a saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | Auto-create missing local credentials through tokenized WP-CLI; default `local-only` |
| `STONEWRIGHT_WP_APP_PASSWORD_NAME` | Label used when auto-creating the WordPress Application Password |
| `STONEWRIGHT_WP_CLI_BIN` | WP-CLI executable; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | Optional absolute WordPress install folder containing `wp-config.php`; default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | Comma- or semicolon-separated allowed roots |
| `STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES` | Largest file Direct `stonewright-media-upload` sends, in bytes; default 25 MiB, at most 512 MiB |
| `STONEWRIGHT_DIRECT_MEDIA_ROOTS` | Comma- or semicolon-separated folders, besides the working directory and `<state dir>/media`, that Direct `stonewright-media-upload` may read local files from without `confirm: true` |
| `STONEWRIGHT_DIRECT_PROBE` | `on` or `off`: whether Direct checks the public page after a page, post, template or template-part write. Default: on for a local site, off for a remote one. A call's `probe` argument overrides it, and `rollback_on_probe_failure:true` turns the check on |
| `MCP_PROXY_TARGET` | Optional upstream MCP server |

## Direct mode private state

Run `stonewright connect add` (or the compatibility `stonewright-companion init`
command) for guided setup. New sites use schema v2: metadata and a
`credential_ref` in permission-restricted `~/.stonewright/sites.json`, with the
Application Password in the OS credential store (Keychain / Windows DPAPI
secrets under `~/.stonewright/secrets/` / Secret Service) or an `env://VAR` ref.
MCP client configs set `STONEWRIGHT_SITE_ALIAS` only; the companion injects
`STONEWRIGHT_WP_*` for that alias at startup. Prefer `--password-env` or an
interactive prompt over `--password` on argv. Legacy v1 plaintext files remain
readable; run `connect migrate` to move secrets out of the file.

Direct tools act on the session's site. With `STONEWRIGHT_SITE_ALIAS` set, that
alias replaces the registry default for every Direct tool (REST, WP-CLI, memory,
incidents, task-start latch) and a `site` argument naming another alias is
refused (`site_not_in_session`).

Before a write, Direct checks whether the Stonewright plugin is active on the
site (its `stonewright/v1` namespace in `/wp-json/`, cached five minutes per
site and cleared on reconnect). On a plugin site the write is refused and the
agent is told to use Plugin mode. `auto` mode does not fall back to Direct when
the plugin is live but its MCP route is missing, and it honors the site's
`fallback_policy`; `direct-only` still detects the plugin and refuses writes.

Each record also retains configured Direct/Plugin policy and the WordPress
Setup expectations (`enabled_requested`, mode, bootstrap/essential/full
surface, Elementor V4 Atomic). Each client binding retains the browser provider
and separate scan/install consent. `recommended` means the documented external
Playwright default without embedding a browser module. `unknown` means ask once; it never authorizes
a scan or installation. `connect repair` can update the binding without adding
another server entry.

Use `stonewright connect verify <alias> --client <id>` after client restart. It validates
the file, then spawns that exact stdio entry and requires task-start/status plus
the expected tool surface. The receipt stores active alias, companion version
when observable, tool count, and a surface digest; structural validation alone
is not reported as a working connection.

Direct user memory, user-created skills, backups, and redacted audit history
live under `~/.stonewright/`. A new state directory starts without user memory,
user-created skills, or audit events; packaged generic built-ins remain
available. Companion updates and restarts preserve existing state.

Credential-like memory and skill writes are rejected. Authorization headers,
tokens, and Application Passwords are redacted from Direct audit diagnostics
before persistence.

Direct `stonewright-media-upload` limits where its bytes come from. A local
`path` is resolved (symlinks and `..` included) and must sit inside the working
directory, `<state dir>/media` or a folder in `STONEWRIGHT_DIRECT_MEDIA_ROOTS`;
any other file needs `confirm: true`. A `url` must be http or https and resolve
only to public addresses (private, loopback, link-local, multicast and reserved
ranges are refused, for IPv4 and IPv6). Redirects are followed by hand, at most
five. Each hop resolves the name once, checks every address, and connects to the
addresses it checked, keeping the original Host header and TLS server name. The file must be an image, audio, video, PDF, Office, OpenDocument, txt or csv file (never SVG, HTML, XML, PHP, scripts, executables or archives, even with `confirm: true`), at most 25 MiB (`STONEWRIGHT_DIRECT_MEDIA_MAX_BYTES` changes the limit, up to 512 MiB). The first bytes must match the extension and must not be markup or PHP. The upload name is reduced to one safe file name, and a name with no extension takes the source extension or the one the response content type implies.

Direct Elementor tools (`stonewright-elementor-status`, `-data-get`,
`-data-update`) use local WP-CLI for a configured alias only when the local
install's `home` (then `siteurl`) option matches the alias URL. Otherwise they
use core REST and return `wp_cli_refused`, which says the local WordPress is not
the target site and names neither host. The check runs once per alias and local
install for the life of the process.

Direct `stonewright-task-start` returns `required_followups`, the first of which
asks for `stonewright-learning-record` when the user corrects the agent or a
repeatable mistake is detected, and ranks `memory_highlights` by score (each
lesson has a `confidence` and a `last_verified` time; a stale or doubtful lesson
sorts lower and is marked `verify_before_use`). When a write to a resource fails
or its readback differs and a later write to the same resource succeeds, that
answer carries a `learning_offer` that asks the agent to put the question to the
user before recording anything; the failures are remembered in the running
companion only. `stonewright-memory-delete` removes a local lesson by id and needs a
confirmation bound to the call (`confirmation_id`). A skill saved through Direct, or drafted with `learning-record`,
is a disabled candidate; Direct refuses `enabled:true` and any change to an active
skill, because activation needs an administrator's approval in Plugin mode.
`stonewright-learning-record` screens the lesson like the plugin's memory does: a
lesson that tells an agent to override the plugin rules, disable confirmation
tokens or send credentials elsewhere is stored with the status `needs_review`
(`needs_review: true` in the answer), is not offered at task start, saves no
`draft_skill`, and is not cleared by recording the same text again. A person
clears it by deleting it (`stonewright-memory-delete`) or by setting its status to
`active` in `~/.stonewright/memory/<scope>.jsonl`.

Direct `stonewright-task-start` lists open incidents in `incident_actions`. It
asks for `repair_open_incidents_first` in `required_actions` only for an open
incident whose failure row carries a resource and a change set, because only
such an incident can be closed with `stonewright-incident-repair-record`. Other
incidents are information. An incident with no new matching failure for seven
days is closed and leaves the list, unless its latest failure row carries a
resource and a change set (those wait for a verified repair, as in Plugin mode);
a later matching failure reopens a closed incident.
Before `template-update`, `template-part-update`, `global-styles-update`,
`content-update`, `blueprint-apply` with a `post_id`, and
`stonewright-blocks-batch-mutate`, Direct saves the current resource
(`context=edit`) to
`~/.stonewright/backups/<site alias>/<tool>/<resource id>/<timestamp>.json`
(mode 0600 where supported, 20 kept per resource, oldest deleted) and returns
`backup_path` and `backup_sha256`. When the resource cannot be read or the file
cannot be stored, the write is refused and nothing is sent. The file holds the
REST resource only, never request headers or credentials.
`stonewright-direct-backup-list` lists the saved copies and
`stonewright-direct-backup-restore` puts one back (it needs `confirm:true`,
checks the stored hash and saves the current state first, so a restore can be
undone). A restore writes the saved meta back over the current meta and deletes
no key, so a meta key added after the backup stays; the result lists those keys
as `meta_not_in_backup`, and `matches_backup` can be false after a correct
restore.

`stonewright-gutenberg-validate` parses the post's blocks: balanced comments,
valid JSON attributes, block names against the site's block list when it can be
read, and the custom-code guard over every block. `stonewright-gutenberg-compose`
reads its own result the same way and refuses custom code in `html` and `raw`
markup. `stonewright-blocks-batch-mutate` inserts, updates and removes blocks by
path with the Plugin ability's arguments (`post_id`, `operations`,
`expected_content_hash`, `dry_run`): a dry run first, a write only against the
content hash it returned, one saved copy, a readback, and a refusal of custom
code that the operations add. See the
[companion README](../companion/README.md#gutenberg-blocks-direct).

Direct never writes custom code. `global-styles-update` refuses theme.json
`styles.css` and per-block `css`; `elementor-data-update` refuses Elementor
custom CSS setting keys and HTML-type widgets, and replaces the whole tree only after its
checks: `expected_hash` (the `hash` that `elementor-data-get` returns) must match the stored tree,
ids must be unique, the root holds containers or sections, `settings` are objects, the tree is within
the size caps, and an Atomic (V4) or mixed document is not rewritten without `allow_atomic_raw`;
`force_destructive`, `allow_widget_type_remap` and `allow_atomic_raw` need a confirmation, are audited and
are listed in the result as `overrides_used`; content, template, and blueprint
writes refuse `<script>`, `<style>`, `<iframe>`, `<object>`, `<embed>`, `<link>`, `<meta http-equiv>`, inline `on*=` event handlers,
and `javascript:` URLs (case-insensitive, entity-encoded and JSON-escaped forms
included). A refusal is a normal result with `approval_required` and
`agent_must_stop`, and nothing is written. `template-update`,
`template-part-update`, and `blueprint-apply` over an existing page need
a confirmation when `STONEWRIGHT_DIRECT_WRITES=confirm` or the site is remote: the refused call returns a
`confirmation_id` (an HMAC over the tool, the site and the exact arguments, kept in memory for
10 minutes, one use) and the same call is repeated with it. A plain `confirm: true` counts only on
a local site or with `STONEWRIGHT_DIRECT_CONFIRM_LEGACY=on`.
`acf-fields-update`, `media-upload`, `media-update`, menu and menu-item,
comment, taxonomy-term, settings, user and Application Password writes refuse the
same markup, `javascript:` URLs and custom CSS keys in the text they send (ACF
values, titles, names, captions, alt text, descriptions, URLs, setting values).

Direct sends the Application Password only over https, or over http to
`localhost`, a loopback address, `.localhost`, `.test` or `.local`; any other
plain-http site is refused before a request is made, and
`STONEWRIGHT_DIRECT_ALLOW_HTTP=on` allows it for a trusted private network. A
GET is retried once after a network error; a write (POST, PUT, PATCH, DELETE) is
never retried.

`plugin-deactivate` and `plugin-delete` refuse Stonewright and the MCP adapter.
`user-delete` refuses the account the session signs in as and the last
administrator, and `user-update` refuses a role change that removes administrator
from either. `settings-update` accepts an allowlist of keys only (never the site
address or administrator email) and `default_role` only for `subscriber`,
`contributor`, `author` or `editor`.

In a Direct session `stonewright-wp-cli-run` and `stonewright-wp-cli-job-start`
treat a command that is not read-oriented as a Direct write: task-start for the
site, `confirm: true` in `confirm` mode (a local run keeps the plain flag), a local WordPress that is the Direct
site, and an audit row. Commands the shared command policy refuses stay refused,
and `stonewright-wp-cli-batch-run` is read-oriented only.

Direct mode lists and restores its saved copies (`stonewright-direct-backup-list`,
`stonewright-direct-backup-restore`), reads every typed write back, and can probe the
public page after a page, post or template write (rolling back only when asked),
but it has no change sets or plugin confirmation tokens (its `confirmation_id` lives in the companion's memory). In Plugin
mode the companion does not call WordPress REST write endpoints; Direct mode is
the exception, through its typed tools only. See
[Direct mode E2E](direct-mode-e2e.md#what-direct-mode-does-not-do-yet) for the
full list.

Plugin-mode OAuth refresh is single-flight and persists rotated access/refresh
token pairs atomically with mode `0600`. Status reports use schema version 3.
Terminal grant/client failures clear the local state and return
`reauthentication_required` with a model-visible `user_action` that opens with
one fixed sentence for the reason when it is one of `invalid_client`, `refresh_token_expired`,
`refresh_token_revoked`, `refresh_outcome_unknown`, or `invalid_grant`;
transient HTTP or network failures honor bounded backoff, jitter,
`Retry-After`, and a circuit breaker. A refresh that got no response at all is
sent once more with the same refresh token; a second silence reports
`refresh_outcome_unknown`. Access tokens last one hour. Seven-day continuity is a refresh SLO
within a grant that ends at most 90 days after authorization, where each
refresh token expires after 30 days without use. Each WordPress MCP
request is sent once and is not repeated after a timeout or network error; on
OAuth connections an HTTP 401 refreshes the access token and the request is
sent once more, a tool call included. Degraded
`stonewright-task-start` reconnects once. See
[Permanent remediation contracts](permanent-remediation-contracts.md).

### Tools shared with Plugin mode

Thirty-seven tool names exist in both modes (the full table of differences is in
[Direct mode E2E](direct-mode-e2e.md#tools-that-exist-in-both-modes)). Direct
keeps its own argument names and also accepts the plugin's, so a client that
learned the Plugin schema still works in a Direct session:

| Tool | Direct argument | Plugin name also accepted |
|---|---|---|
| `comment-list`, `comment-create` | `post` | `post_id` |
| `comment-update` | `status: approved` | `status: approve` |
| `comment-list` | `status: approved` | `status: approve` (and `all`) |
| `menu-delete` | `id` | `menu_id` |
| `media-upload` | `alt_text` | `alt` |
| `user-create` | `username`, `email`, `password`, `roles[]`, `name` | `user_login`, `user_email`, `user_pass`, `role`, `display_name` |
| `user-update` | `email`, `password`, `roles[]`, `name` | `user_email`, `user_pass`, `role`, `display_name` |
| `user-list` | `roles`, `per_page` | `role`, `number` (at most 50 in Direct) |
| `blueprint-get` | `id` | `blueprint_id` |
| `blueprint-apply` | `id`, `title`, `status` | `blueprint_id`, `page_title`, `mode` |
| `wc-sales-report` | `date_min`, `date_max` | `date_from`, `date_to`; `period` `last_7_days`, `last_30_days`, `year_to_date`, `custom` |
| `settings-update` | `title`, `description`, `timezone` | `blogname`, `blogdescription`, `timezone_string` |
| `plugin-activate`, `-deactivate`, `-delete` | `folder/file` | `folder/file.php` |

An alias and its Direct argument given with different values are refused
(`argument_alias_conflict`, nothing is sent); an argument only the plugin has and
Direct cannot honour (`palette_override`, `brand_kit`, `base64`, `tags` and a few
more) is refused with `plugin_only_argument` instead of being dropped. Direct
output keeps its keys and adds the plugin's where the value is the same
(`url`, `mime`, `alt`, `active`, `menu_id`, `login`, `display_name`). In
`blueprint-apply`, `engine: elementor` or `fse` answers `engine_requires_plugin_mode`.

The state the two modes share under `~/.stonewright` is kept apart. Rows for
local work (`stonewright-command-*`, `stonewright rescue`) carry
`backend: "local"`: they open no Direct incident and are not counted as Direct
errors. `task-start` lists only its own site's errors from the last 30 days.
Memory lives in `memory/<host>-<fingerprint>.jsonl`, keyed by the site's
canonical URL rather than by the alias: two aliases for one site share it, and an
alias pointed at another site does not inherit it. A `memory/<alias>.jsonl` file
from an earlier companion is copied once (the old file is never moved or deleted;
`memory/_site-bindings.json` records which site it was adopted for) and is still
read while the alias points at that site. `memory-list` reports the store it read
(`backend`, `memory_backend`) and, when the plugin is active on the site, says
that `task-start` and `learning-record` use the plugin's memory there.
`task-start` and `learning-record` answer with a common core of keys and a
`backend` field whichever store answered. The managed block of
`~/.stonewright/AGENTS.md` has shared rules and one section per mode; only the
Direct section names Direct-only tools.

## Plugin Integration

The normal `npx` setup uses the versioned GitHub release tarball and does not
need the WordPress-side HTTP bridge. Use the bridge only when a site
deliberately wants WordPress-side `stonewright/wp-cli-*` abilities to call a
local companion HTTP process.

If a `.env` file sets `PORT`, stdio MCP ignores it unless
`STONEWRIGHT_HTTP_ENABLE=1` or `STONEWRIGHT_HTTP_REQUIRED=1` is also set. Set
`STONEWRIGHT_HTTP_REQUIRED=1` only for bridge-only deployments where a bind
failure should stop startup.

For a human administrator with WP-CLI already configured, bridge options can be
set from shell:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
wp option update stonewright_companion_token change-this-long-random-token
```

Then use Stonewright abilities:

- `stonewright-task-start`
- `stonewright-context-bootstrap`
- `stonewright-workflow-preflight`
- `stonewright-tool-profile`
- `stonewright-skills-get`
- `stonewright-wordpress-mcp-status`
- `stonewright-wp-cli-status`
- `stonewright-wp-cli-discover`
- `stonewright-wp-cli-run`
- `stonewright-wp-cli-batch-run`
- `stonewright-wp-cli-job-start`
- `stonewright-wp-cli-job-status`

If the HTTP bridge is not running, use the direct companion MCP tools instead:

- `companion_wp_cli_status`
- `companion_wp_cli_discover`
- `companion_wp_cli_run`
- `companion_wp_cli_batch_run`

Do not recover by running `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp` commands in a normal shell. Use the
Stonewright MCP tools above so the companion can apply path checks, command
guards, and tokenized argv execution.

Use `stonewright-wp-cli-batch-run` for repeated read-oriented commands
(`post`, `term`, `option`, `plugin`, `theme`, `user` reads, `db check`, cron
lists, cache and rewrite flushes), especially when values contain non-ASCII
text. It accepts JSON arrays of argv tokens, preserves UTF-8, and avoids large
inline PowerShell or Node scripts where shell encoding can corrupt diacritics.
A command off that list refuses the whole batch before any command runs.
Use `stonewright-wp-cli-job-start` plus `stonewright-wp-cli-job-status` for
long imports, cache rebuilds, or batches of writes that should not block a
single MCP request.

Every runner reads the argv the way WP-CLI does: a leading `@alias`, global flags
before or between the command words, quotes, case, and a whole command in one
token do not hide the command. The agent-facing runners (run, batch, jobs, and
their HTTP routes) refuse `eval`, `eval-file`, `shell`, `package`, `config`,
`scaffold`, plugin and theme `install`/`update`/`delete`/`activate`, `db`
(except read-only subcommands), `user` changes, role and capability changes,
`core download`/`update`/`install`, `cron event run`, `cli update`,
`search-replace` without `--dry-run`, option writes to sensitive options, and
the `--exec`, `--require`, `--prompt`, `--config`, `--ssh` and `--http` flags.
Commands the companion builds for its own setup, rescue and approved recipes
use a narrower list that refuses only the entry points that run arbitrary code.

Call `stonewright-setup-profile` once after connecting. Its `first_calls` and
`tool_visibility_checks` fields show the compact startup path: task-start,
optional compatibility bootstrap/profile verification, skill playbook retrieval, WordPress
MCP proxy status, and direct WP-CLI aliases. Use `fast_path.tool_profile` from
`stonewright-workflow-preflight` before making a separate
`stonewright-tool-profile` call.
The companion also sets mode-aware MCP server instructions at handshake time.
Both modes call `stonewright-task-start` first and keep low-tools sessions
compact. Plugin mode may use `stonewright-php-execute` on the `full` profile
only; Direct mode explicitly
has no PHP escape hatch and uses only typed Direct tools or tokenized companion
`stonewright-wp-cli-*` tools. Both reject shell WP-CLI, generic PHP adapters,
and ad hoc REST runners. When the WordPress plugin MCP is reachable, the companion appends the
plugin's `initialize.instructions` after a `--- WordPress plugin instructions ---`
separator so clients see site rules and the task-start contract without an
extra tool call.

If both `stonewright-task-start` and compatibility
`stonewright-context-bootstrap` are missing, or other expected WordPress tools
are absent,
call `stonewright-wordpress-mcp-status`. The companion keeps this diagnostic,
`stonewright-setup-profile`, and direct `stonewright-wp-cli-*` tools available
even when the WordPress MCP endpoint cannot be reached.
Do not inspect private AI-client config files, parse repository files as the
tool list, create scratch scripts such as `query-mcp.js` or `run-ability.js`,
create helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, launch the companion through ad hoc
scripts such as `query-local-stonewright.js`, create action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, inspect plugin/companion
source to reverse-engineer tool schemas, hand-roll JSON-RPC, or call
`/wp-json/stonewright/v1/abilities/run` from shell as a substitute for the live
Stonewright MCP tools.
When the endpoint connects, the status response reports `startup_ready`,
`startup_missing_tool_names`, `local_recovery_tool_names`, and
`local_tool_names` so agents can see whether bootstrap, preflight,
skill-playbook, and direct WP-CLI tools are ready. It also reports
`profile_expected_tool_count`, `client_visible_expected_tool_count`, and
`profile_missing_tool_names` for the selected compact profile even when the
WordPress MCP endpoint cannot be reached.
Both setup and status responses include `tool_inventory`, a compact grouped map
of first-call, diagnostic, direct WP-CLI, long-running WP-CLI, and proxied
profile tools. Use it before broad tool discovery in token-sensitive sessions.

For new stdio sessions, an unknown client defaults to
`STONEWRIGHT_MCP_TOOL_PROFILE=essential-static`; generated known-client configs
set the same value and save `essential` as the WordPress surface. Both
profiles provide useful bounded startup tools without depending on live
relisting. Bootstrap remains available as an explicit
transport/profile diagnostic. When a profile does change,
`stonewright-task-start` sets `tools_changed` + `re_list_instruction`, updates
or enables the corresponding callable handles before it emits
`notifications/tools/list_changed`, and reconnect reuses those handles rather
than registering duplicate tool or prompt names. In Direct mode task-start also stamps
a per-site write latch (30-minute TTL); write tools re-require task-start after
expiry or when targeting a different site. Use companion tool
`stonewright-client-surface-check` (or `stonewright doctor --client-surface`)
to diagnose profile vs client registration mismatches. Set the env profile to
`full` only for deliberate specialist diagnostics.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients. It keeps the total client-visible surface under
30 tools by hiding legacy duplicate aliases while direct WP-CLI recovery tools
remain local, including batch runs and background jobs. It registers no
`stonewright-php-execute`, even when the site's saved surface is `full`; that
tool is on the full profile only, as in the plugin.
Common aliases normalize before filtering, so `antigravity`, `gemini`,
`elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` select the closest
compact canonical profile instead of silently falling back to `essential-static`.

## Persistent Application Passwords

Application Passwords are one-time-display credentials in WordPress. The
companion therefore supports a local per-project credential store. If env
credentials are missing, it reads the saved credential for the current
Stonewright MCP URL and project root. For local development hosts it can create
the password once through `stonewright-wp-cli-run`-equivalent tokenized WP-CLI
execution, save it outside the repo by default, and reuse it in future agent
sessions.

Do not store these credentials in Stonewright memory, user skills, public docs,
commits, issues, or admin instructions. See [Updating Stonewright](updates.md)
for the component matrix and state-preserving upgrade procedure.
