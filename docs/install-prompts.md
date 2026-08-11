# AI Client Install Prompts

Copy **one** block below into your AI client. Both configure the same
Stonewright companion MCP server (`stonewright-mcp`). The difference is
whether the Stonewright WordPress plugin is installed on the site.

Replace `VERSION` with the exact release version you installed, without the
leading `v`, as shown on the GitHub Releases page.
Companion package URL pattern:

`https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz`

Keep the values behind the placeholders in private client configuration. Do
not paste a real site URL, username, Application Password, token, memory entry,
or audit payload into an agent chat. See [Updating Stonewright](updates.md)
when changing versions.

## What “local stdio” means

With local stdio, the AI client starts the Stonewright companion on your
computer and communicates with that process through standard input/output. The
companion is required for local stdio, Direct mode, and local WP-CLI. Remote
Streamable HTTP connects the client directly to the WordPress plugin over HTTPS
and does not run a local companion.

## Custom-code approval rule

For theme files, Customizer CSS, WPCode, Code Snippets, or equivalent
PHP/CSS/JS/HTML surfaces, the agent must run the approval-gated typed tool with
`dry_run` first, return `approval_url`, the exact path, byte counts, and a short
summary, then stop. The agent must not open the approval page, issue or retrieve
the grant, or apply `custom_code_grant` unless the user explicitly asks it to
perform that approval step. Direct mode does not write custom code because it
has no authenticated wp-admin grant boundary.

## OAuth connection (recommended in Plugin mode)

```text
Connect Stonewright using OAuth.

Server URL:
https://YOUR-SITE/wp-json/mcp/stonewright-oauth

Open the browser sign-in, let me approve access in WordPress, then verify
initialize, tools/list, and stonewright-task-start. Do not request or store a
WordPress password.
```

## Option A — With the Stonewright plugin (full surface)

```text
Configure the Stonewright MCP server for my WordPress site in this AI client.

Prefer the versioned `stonewright connect add` installer. Ask me for a unique
site alias, environment, Plugin/Direct policy, WordPress mode, MCP tool surface,
Elementor V4 choice, and target client. Ask once for a browser provider and ask
separately before scanning client configuration or installing anything. Store
those choices per site/client. Use a hidden password prompt or --password-env;
never put a password on argv. After I restart the client, run
`stonewright connect verify <alias> --client <client>` and require spawned task-start/status
proof, not only a valid config file.

Connection values (I will provide secrets when asked):
- WordPress URL: <https://example.com>
- MCP server name: stonewright
- Transport: command `npx`, args ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"]
- Env vars only (never inline secrets in args): STONEWRIGHT_WP_URL,
  STONEWRIGHT_WP_USERNAME, STONEWRIGHT_WP_APP_PASSWORD,
  STONEWRIGHT_MCP_TOOL_PROFILE=essential (normal bounded working profile; use
  essential-static only for an unknown client with stale tool-list behavior,
  or low-tools for a strict tool cap).
- I will supply secrets only into private client config. Do not ask me to paste
  real Application Passwords or OAuth tokens into chat.

After a client-specific restart / MCP reload (not only a chat refresh):
- Call stonewright-setup-profile and stonewright-wordpress-mcp-status.
- Confirm companion_version matches VERSION, the WordPress MCP endpoint is
  authenticated, and refresh_required_tool_names is empty.
- Status and gateway reports must be honest when disconnected or unauthorized.
- If OAuth header delivery is in doubt, call the read-only
  `stonewright-oauth-header-diagnostic`; it returns booleans only and never
  returns a header or token fragment.
- Verify stonewright-task-start is in the tool list; if missing, stop and tell me.
- Start every WordPress task with stonewright-task-start.
- Follow the returned skills, memory, expertise, and fast_path.tool_profile.
- Task start returns a native rule digest, not the rule bodies. Read them once
  with stonewright-rules-get, cache them, and pass knownDigest on later calls so
  an unchanged registry costs one short response.
- Treat hard rules as blocking, strong rules as required practice that the
  runtime cannot check for you, and advisory rules as guidance.
- For custom code, dry-run and show approval_url, exact path, byte counts, and
  summary, then stop for the human-issued one-time grant. Never open or submit
  the approval page unless I explicitly ask.
- To keep reads cheap, pass stonewright_fields with the paths you need. Required
  response-envelope fields remain present. Pass knownHash to
  stonewright-elementor-v3-get-page-structure to skip an unchanged page tree.
- For visual work, ask once whether to use Playwright (recommended), another
  connected browser, or none. Ask permission before scanning client
  tools/private config and separate permission before installing or configuring
  a missing provider; then verify the approved tool before the first write.
- After any Elementor write, call
  stonewright-elementor-post-write-verify with the touched element IDs. Do not
  call the task complete until its frontend assertions pass and desktop,
  tablet, and mobile browser measurements/screenshots are accepted.
- Do not inspect private AI-client config files, hand-roll JSON-RPC, or run wp in a normal shell as an MCP workaround.
- Use stonewright-php-execute for short runtime PHP; keep WP-CLI tokenized via stonewright-wp-cli-*.
```

## Option B — Without the plugin (Direct mode, any live WordPress)

Works with only a WordPress Application Password — nothing installed on the
site. Direct mode covers content, pages, media, taxonomy, menus, FSE
templates, settings, plugins/themes lifecycle, comments, users and
application passwords, widgets, revisions and autosaves, site health,
search/oEmbed/block-directory utilities, WooCommerce reads, ACF field values
(when ACF exposes them over REST), SEO head reads, a guarded read-only REST
passthrough, and local self-improvement (per-site skills and memory under
`~/.stonewright/` on this machine).

Local sites with tokenized WP-CLI can also inspect and update Elementor document
data with mandatory file backup. This is not remote pluginless Elementor engine
parity.

Plugin-only: php-execute, Elementor engines, DesignSpec render pipelines,
production-safe confirmation tokens, CPT/field-group registration, and the
wp-admin skills/memory/audit UI.

Optional interactive setup:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username admin \
  --mode direct-only --client cursor
```

It validates the credential, stores only a `credential_ref` in the
permission-restricted schema-v2 `~/.stonewright/sites.json`, keeps the secret in
the OS credential store (or an explicit `env://` reference), and writes a
secret-free named MCP entry. Existing v1 plaintext files remain readable only
for compatibility; run `stonewright connect migrate` to move their secrets out
without leaving a plaintext backup.

```text
Configure the Stonewright MCP server in Direct mode (no WordPress plugin) in this AI client.

Prefer `stonewright connect add` with a unique alias and --mode direct-only.
Ask once for Playwright, another connected browser, or none. Ask separately
before scanning and before installing/configuring a provider, save those
choices for this site/client, and never infer consent. After restart, run
`stonewright connect verify <alias> --client <client>`; require the spawned
companion version, active alias, task-start, status, and required tool surface.

Connection values (I will provide secrets when asked):
- WordPress URL: <https://example.com>
- MCP server name: stonewright
- Transport: command `npx`, args ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"]
- Env vars only: STONEWRIGHT_WP_URL, STONEWRIGHT_WP_USERNAME,
  STONEWRIGHT_WP_APP_PASSWORD, STONEWRIGHT_DIRECT_WRITES=confirm
  (use `on` only where unconfirmed writes are acceptable; multi-site via ~/.stonewright/sites.json).
  Optional: STONEWRIGHT_MODE=direct to force Direct mode.

After reload:
- Call stonewright-setup-profile and stonewright-wordpress-mcp-status.
- Confirm mode is Direct, companion_version matches VERSION, and capability
  gaps are reported honestly rather than silently falling back.
- Verify stonewright-task-start is in the tool list; if missing, stop and tell me.
- Start every WordPress task with stonewright-task-start — in Direct mode it
  returns this site's locally stored skills and memory (or _global).
- Task start returns a native rule digest. Read the rules once with
  stonewright-rules-get (on the Direct bootstrap surface), cache them, and pass
  knownDigest afterwards. Direct mode ships the same registry as the plugin, so
  the rules match; runtime enforcement of hard rules only exists where the
  plugin runs, so on Direct read hard as "enforceable where the plugin runs".
- Call stonewright-site-discover before choosing WordPress REST operations.
- Load a matched skill body with stonewright-skill-get only when needed.
- When I correct a repeatable mistake, call stonewright-learning-record so it
  persists for future sessions.
- Destructive tools require confirm:true. Do not work around write gating.
- Do not write custom PHP/CSS/JS/HTML in Direct mode. It has no authenticated
  wp-admin one-time-grant boundary; report the Plugin-mode approval-gated path.
- One-time setup: call stonewright-agents-md-sync and offer to add the pointer to your global agent config.
- Fix recurring_errors from task-start before new work; never invent Elementor/Gutenberg schemas.
- For visual work, ask once whether to use Playwright (recommended), another
  connected browser, or none. Ask permission before scanning client
  tools/private config and separate permission before installing or configuring
  a missing provider; then verify the approved tool before the first write.
- After a local Direct Elementor write, require the returned cache receipt and
  complete browser verification. Remote Direct cannot verify Elementor's PHP
  renderer or post cache; use Plugin mode when closed-loop verification is
  required.
- Do not inspect private AI-client config files, hand-roll JSON-RPC, or run wp in a normal shell as an MCP workaround.
```

## Capped-client setup

- Set `STONEWRIGHT_MCP_MAX_TOOLS=50` so the companion applies the same limit deliberately instead of letting the client truncate an arbitrary tail.
- Configure exactly one Stonewright MCP server entry. Do not register plugin-proxy and Direct mode side by side.
- `STONEWRIGHT_MCP_TOOL_PROFILE` selects the startup profile. Known clients
  normally use `essential`; use `essential-static` only for an unknown client
  with stale tool-list behavior. Use `STONEWRIGHT_MCP_TOOL_PROFILE_LOCK=1` only when you
  intentionally want the environment value to override WordPress Setup
  throughout the session.

For stale, disabled, or truncated tools, follow the [tool surface recovery runbook](runbooks/tool-surface-recovery.md).

## Certified vs compatible clients

- **Tier-1 certification targets:** Codex, Claude Code/Desktop, Cursor, VS Code /
  GitHub Copilot — maintained first, but not called certified without a passing
  acceptance report; use
  [client-acceptance-template.md](releases/client-acceptance-template.md).
- **Compatible:** other catalog clients may work with the same stdio/HTTP
  snippets but are not fully certified until an acceptance report passes.
- Certification priority, operational support, and evidence live in
  `plugin/data/clients/*.json` and
  [verified-client-versions.md](verified-client-versions.md).

## Updating an existing setup

- stdio + plugin: update both release artifacts to the same version;
- remote HTTP: update the plugin;
- Direct mode: update the companion.

Restart the client, call `stonewright-task-start`, then verify
`companion_version`, `expected_companion_package`, and
`refresh_required_tool_names`. Updates preserve existing plugin and Direct
memory, user skills, and audit history. Full steps:
[Updating Stonewright](updates.md).
