# AI Client Install Prompts

Copy **one** block below into your AI client. Both configure the same
Stonewright companion MCP server (`stonewright-mcp`). The difference is
whether the Stonewright WordPress plugin is installed on the site.

Replace `VERSION` with the exact release version you installed, without the
leading `v`, as shown on the GitHub Releases page.
Companion package URL pattern:

`https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz`

## Option A — With the Stonewright plugin (full surface)

```text
Configure the Stonewright MCP server for my WordPress site in this AI client.

Connection values (I will provide secrets when asked):
- WordPress URL: <https://example.com>
- MCP server name: stonewright
- Transport: command `npx`, args ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"]
- Env vars only (never inline secrets in args): STONEWRIGHT_WP_URL,
  STONEWRIGHT_WP_USERNAME, STONEWRIGHT_WP_APP_PASSWORD,
  STONEWRIGHT_MCP_TOOL_PROFILE=essential (use low-tools for strict tool-cap clients).

After reload:
- Call stonewright-setup-profile and stonewright-wordpress-mcp-status.
- Confirm companion_version matches VERSION, the WordPress MCP endpoint is
  authenticated, and refresh_required_tool_names is empty.
- Verify stonewright-task-start is in the tool list; if missing, stop and tell me.
- Start every WordPress task with stonewright-task-start.
- Follow the returned skills, memory, expertise, and fast_path.tool_profile.
- For visual work, verify browser/Playwright tools before the first write.
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

```text
Configure the Stonewright MCP server in Direct mode (no WordPress plugin) in this AI client.

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
- Call stonewright-site-discover before choosing WordPress REST operations.
- Load a matched skill body with stonewright-skill-get only when needed.
- When I correct a repeatable mistake, call stonewright-learning-record so it
  persists for future sessions.
- Destructive tools require confirm:true. Do not work around write gating.
- One-time setup: call stonewright-agents-md-sync and offer to add the pointer to your global agent config.
- Fix recurring_errors from task-start before new work; never invent Elementor/Gutenberg schemas.
- For visual work, verify browser/Playwright tools before the first write.
- Do not inspect private AI-client config files, hand-roll JSON-RPC, or run wp in a normal shell as an MCP workaround.
```
