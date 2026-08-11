# Connect Your AI Client

OAuth is recommended. It opens WordPress in the browser, asks the current
administrator to approve the client, and avoids copying a WordPress password
into client configuration.

1. Open **Stonewright → Setup**.
2. Enable Stonewright.
3. Choose **OAuth**.
4. Pick the client in **Connect Your AI Client** and follow its instructions.
5. Approve the application in WordPress when the browser opens.

The OAuth MCP resource is:

```text
https://example.com/wp-json/mcp/stonewright-oauth
```

Use **Manage connected apps** to inspect or revoke active grants.

Clients send this exact canonical resource during authorization, token
exchange, and refresh. Access tokens issued for another audience are rejected.
Protected Resource Metadata exposes the minimal `mcp` scope; refresh access is
advertised only by authorization-server metadata.

When an OAuth request is not arriving correctly, use the read-only
`stonewright-oauth-header-diagnostic` ability. It distinguishes the dedicated
OAuth MCP route from an ordinary REST route and reports header visibility,
Bearer parsing, authentication success, proxy stripping suspicion, and the
Application Password path without returning the header value. It does not
replace a live client restart or reauthentication.

Terminal refresh failures (`refresh_token_expired`, `refresh_token_revoked`, or
`refresh_token_invalid`) clear local token state and require one explicit
reauthorization. Transient `429`/`temporarily_unavailable` responses retain
`Retry-After` and are retried with bounded backoff; do not start a second manual
refresh in parallel. Replaying a rotated refresh token revokes the entire token
family and all access tokens for that grant.

## Choose the connection method

- **Local stdio** means the AI client starts the Stonewright companion on your
  computer and talks to it through standard input/output. Use it for Direct
  mode, local WP-CLI, or clients that expect a local command-based MCP server.
- **Remote Streamable HTTP** connects the AI client straight to the Stonewright
  WordPress plugin over HTTPS. It does not install, start, or require a local
  companion.

Direct mode requires local stdio because Direct is implemented by the
companion. A plugin-only remote HTTP connection does not use the companion.

## Application Password fallback

This guide covers wiring supported AI clients to Stonewright. The shortest path
is the **Stonewright > Configuration** page:

1. Enable Stonewright abilities.
2. Choose **Application Password** and generate one in the page.
3. Save the private client snippet directly. The separate paste-to-agent prompt
   contains placeholders only and never includes the real credential.

The normal flow is in-page: it does not refresh, change the URL, or lose the
selected client/auth tabs. The credential is held only in current-tab memory
and cleared on selection changes, revoke, replacement, hide, or unload. The
no-JavaScript fallback is a standalone no-store one-time response; neither path
stores plaintext in WordPress transients or settings.

---

## Prerequisites

### WordPress Application Password

WordPress Application Passwords are one-time-display credentials tied to the
current WordPress user. Generate one from **Stonewright > Configuration >
Application Password**. Copy it immediately; WordPress will not show it again.

### Endpoint

The MCP endpoint is displayed on the Configuration page:

```text
https://{your-site}/wp-json/mcp/stonewright
```

### HTTPS

Production sites must use HTTPS. Application Password credentials travel in the
HTTP Authorization header. For local development with no HTTPS, set
`WP_ENVIRONMENT_TYPE=local` in `wp-config.php`.

---

## Recommended stdio config

Most clients can run the Stonewright companion with `npx`:

Replace `VERSION` with the exact release version without a leading `v`, as
shown on the GitHub Releases page.

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

Stonewright tool names are hyphenated in MCP clients. Example:
`stonewright/context-bootstrap` is called as `stonewright-context-bootstrap`.

Do not point IDE MCP configs at `node companion/dist/index.js`; `dist` is a
source build artifact and is intentionally not committed. Use the `npx` release
tarball above, or for source development use
`npm --prefix <repo>/companion run mcp:source`.
Do not configure generic WordPress MCP adapters such as
`@automattic/mcp-wordpress-remote` as the `stonewright` server. Use the
Stonewright companion so setup, status, compact profiles, php-execute, and
WP-CLI tools stay visible even while the WordPress endpoint is being fixed.

After restart, the AI client should show `stonewright-task-start` or
compatibility `stonewright-context-bootstrap` in the MCP tool list. If both are
missing, Stonewright is not connected yet: reload the client or fix the MCP
config before asking the agent to edit WordPress. Do
not use local agent skills, repository files, private client config files,
scratch scripts such as `query-mcp.js` or `run-ability.js`, hand-rolled
JSON-RPC, helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, direct companion shell launch
scripts such as `query-local-stonewright.js`, action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, plugin/companion
source-code spelunking to reverse-engineer tool schemas, or
`/wp-json/stonewright/v1/abilities/run` shell calls as substitutes for the live
MCP server.

Leave `PORT` out of normal stdio configs, including Antigravity. A stale `.env`
`PORT` is ignored by stdio startup unless `STONEWRIGHT_HTTP_ENABLE=1` or
`STONEWRIGHT_HTTP_REQUIRED=1` is also set. Use `STONEWRIGHT_HTTP_REQUIRED=1`
only when the optional HTTP bridge must start or startup should fail.
For Antigravity, Gemini API, or another strict tool-cap client, set
`STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` in the same env block. It keeps the
client-visible tool list under 30 while preserving composite writes and direct
`stonewright-wp-cli-*` recovery tools, including background jobs.

For Antigravity 2.0, Antigravity IDE, and Antigravity CLI, use the shared
`~/.gemini/config/mcp_config.json` file. See
[Getting started with Antigravity](../getting-started/antigravity.md) for a
`low-tools` config, refresh steps, and `/mcp` validation.

Do not recover by running `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp` commands in a normal shell, and do not switch
to another PHP adapter. Use `stonewright-wordpress-mcp-status`,
`stonewright-php-execute`, and the direct `stonewright-wp-cli-*` tools exposed
by the companion.

---

## Codex

Codex CLI and the Codex IDE extension share MCP config from
`~/.codex/config.toml`. Trusted projects can also use `.codex/config.toml`.
Add Stonewright as a stdio MCP server:

```toml
[mcp_servers.stonewright]
command = "npx"
args = ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"]

[mcp_servers.stonewright.env]
STONEWRIGHT_WP_URL = "https://your-site.com"
STONEWRIGHT_WP_USERNAME = "your-wp-username"
STONEWRIGHT_WP_APP_PASSWORD = "xxxx xxxx xxxx xxxx xxxx xxxx"
        STONEWRIGHT_MCP_TOOL_PROFILE = "essential"
```

In the Codex IDE extension, open the gear menu, choose **Codex Settings >
Open config.toml**, paste the block, save, then restart Codex or reload the MCP
session. In the Codex TUI, use `/mcp` after restart to confirm `stonewright` is
active.

After every Stonewright release or skill sync, run `stonewright-setup-profile`
and `stonewright-wordpress-mcp-status`. Check `companion_version`,
`expected_companion_package`, and `refresh_required_tool_names`; if the expected
package or required tools are stale, Codex is still running an old companion
process or cached tool list.

See [Updating Stonewright](../updates.md) for the plugin/companion version
matrix, replacement steps, and persistence guarantees.

---

## Claude Code

Claude Code registers MCP servers through its CLI:

```bash
claude mcp add stonewright \
  --env STONEWRIGHT_WP_URL='https://your-site.com' \
  --env STONEWRIGHT_WP_USERNAME='your-wp-username' \
  --env STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --env STONEWRIGHT_MCP_TOOL_PROFILE=essential \
  -- npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright-mcp
```

The server is registered for the current user. Restart or reload the client
after adding it.
After each Stonewright release or skill sync, rerun
`stonewright-setup-profile` and `stonewright-wordpress-mcp-status`. The
`companion_version`, `expected_companion_package`, and
`refresh_required_tool_names` fields tell agents whether the visible tool list
is current or the IDE/client still needs a restart.

---

## JSON Clients

Use the recommended stdio block for these clients unless their UI requests the
fields separately:

| Client | Config location |
|---|---|
| Codex | `~/.codex/config.toml` or trusted `.codex/config.toml` |
| Claude Desktop | `%APPDATA%\Claude\claude_desktop_config.json` on Windows, `~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `~/.config/Claude/claude_desktop_config.json` on Linux |
| Cursor | `.cursor/mcp.json` in the project or `~/.cursor/mcp.json` globally |
| Windsurf | `~/.codeium/windsurf/mcp_config.json` |
| OpenCode | `.opencode/config.json` or global OpenCode config |
| Roo Code | `~/.roo/mcp.json` or the extension settings panel |
| Amazon Q Developer | `~/.aws/amazonq/mcp.json` |
| Kilo Code | `~/.kilo/mcp.json` or the extension settings panel |
| Gemini CLI | `~/.gemini/settings.json` |
| Antigravity 2.0, IDE, CLI | `~/.gemini/config/mcp_config.json`; in IDE use **MCP Servers > Manage MCP Servers > View raw config** |

VS Code-style clients use a `servers` top-level key instead of `mcpServers`:

```json
{
  "servers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

Zed uses `context_servers`:

```json
{
  "context_servers": {
    "stonewright": {
      "command": {
        "path": "npx",
        "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz", "stonewright-mcp"],
        "env": {
          "STONEWRIGHT_WP_URL": "https://your-site.com",
          "STONEWRIGHT_WP_USERNAME": "your-wp-username",
          "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
          "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
        }
      }
    }
  }
}
```

---

## Browser MCP

Add a separate Playwright MCP server when the task needs browser testing,
screenshots, or visual inspection:

```json
{
  "mcpServers": {
    "playwright": {
      "command": "npx",
      "args": ["-y", "@playwright/mcp@latest", "--caps=testing,vision,devtools"]
    }
  }
}
```

Restart the AI client after adding Playwright so its tool list refreshes. For
visual tasks, verify a browser or screenshot tool is visible before the first
Stonewright write.

---

## First Calls

After connecting, verify Stonewright with the real MCP tools:

```text
Use MCP tool stonewright-ping.
Verify the tool list includes stonewright-task-start or compatibility stonewright-context-bootstrap.
Then call stonewright-task-start before the first real task. Use
`fast_path.tool_profile` from task-start before
making a separate stonewright-tool-profile call.
```

If both `stonewright-task-start` and compatibility
`stonewright-context-bootstrap` are missing, stop and restart or reload the AI
client. A good agent should not begin by only announcing named skills,
searching repository files, inspecting private client config files, creating
scratch helper scripts, creating helper JSON argument files, launching the
companion through ad hoc shell scripts, creating action scripts, inspecting
plugin/companion source to reverse-engineer tool schemas, or calling Stonewright
REST endpoints from shell; it should call the live Stonewright MCP tools.

For visual work, include the target URL, screenshot or design reference, allowed
plugins, operating mode, and desktop/tablet/mobile acceptance checks.

---

## Example Prompts

### Figma to Elementor V3

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-task-start, extract
layout, spacing, colors, typography, and responsive behavior, create a validated
design spec, render with stonewright-elementor-v3-build-page-from-spec, then
use stonewright-elementor-v3-batch-mutate for polish. Verify desktop, tablet,
and mobile screenshots against the design.
```

### ACF field group

```text
Use Stonewright to create an ACF field group for Case Studies with client logo,
industry, challenge, solution, results metrics, testimonial, gallery, and CTA
fields. Attach it to the case-study post type, add three sample entries, and
verify fields are available for dynamic Elementor templates.
```

### CPT UI content model

```text
Use Stonewright with CPT UI to create a Projects post type and Project Type
taxonomy. Add labels, archive support, featured images, REST visibility, and
sensible rewrite slugs. Then seed sample projects and build a responsive
archive layout that can be filtered by taxonomy.
```

### WooCommerce cleanup

```text
Use Stonewright to inspect WooCommerce catalog data, normalize product titles,
SKUs, prices, categories, and stock for the provided list, then verify the shop
and product pages still render correctly. Use bulk or batch tools where
possible and report changed products.
```
