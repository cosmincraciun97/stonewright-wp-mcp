# Getting started with Cursor

This guide gets you from zero to a working Cursor + Stonewright MCP setup.
Prefer user-level config for secrets; do not commit Application Passwords into
project-tracked files.

Verified docs snapshot: Cursor is listed in
[verified-client-versions.md](../verified-client-versions.md) (config kind:
JSON; manual smoke pending). Prefer `~/.cursor/mcp.json` for credentials.

## Prerequisites

- A WordPress site running locally or on a server you control.
- WordPress 6.7+ and PHP 8.1+ (plugin mode).
- Node.js 20+ for the Stonewright companion launched through `npx`.
- Cursor with MCP support enabled.

## Choose your mode

- **Direct mode** (fastest): Application Password only — no WordPress plugin.
  Content, media, menus, templates, taxonomy, users, and local Elementor
  document edits with integrity gates. Gaps and matrix:
  [direct-mode-e2e.md](../direct-mode-e2e.md).
- **Plugin mode** (full surface): Elementor engines, DesignSpec, php-execute,
  confirmation tokens, shared site skills/memory. Ability inventory:
  [ability-truth-matrix.md](../ability-truth-matrix.md).

The companion auto-detects mode (`STONEWRIGHT_MODE=direct|plugin` overrides).

## Fastest start (Direct mode)

Generate a WordPress Application Password, then add Stonewright to Cursor.

Config file (user-level recommended for secrets):

```text
~/.cursor/mcp.json
```

Project-local alternative: `.cursor/mcp.json` (do not commit secrets).

Replace `VERSION` with the exact release version without a leading `v`:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": [
        "-y",
        "--package",
        "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz",
        "stonewright-mcp"
      ],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_DIRECT_WRITES": "confirm",
        "STONEWRIGHT_MODE": "direct"
      }
    }
  }
}
```

Optional: omit `STONEWRIGHT_MODE` for auto-detect, or set
`STONEWRIGHT_MCP_TOOL_PROFILE` to `bootstrap` or `low-tools` when you want a
compact client-visible surface (strict tool-cap clients should use `low-tools`).

Restart Cursor or reload MCP servers. Smoke test:

```text
Use MCP tool stonewright-task-start with a short request that you only want a
connection smoke test.
```

Expect mode Direct and local skills/memory hints. Then call
`stonewright-site-discover` before REST work. Destructive tools require
`confirm:true` when writes are gated.

Copy-paste Option B: [install-prompts.md](../install-prompts.md).

## Plugin mode

1. Install and activate the Stonewright WordPress plugin (release ZIP or source
   under `wp-content/plugins`).
2. Open **Stonewright > Configuration**, enable abilities, choose operating
   mode, and generate an Application Password.
3. Use the same `mcpServers.stonewright` JSON shape as Direct mode, but drop
   `STONEWRIGHT_MODE=direct` (or set `plugin` / leave auto) and set:

```json
"STONEWRIGHT_MCP_TOOL_PROFILE": "bootstrap"
```

Example (plugin-oriented env block):

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": [
        "-y",
        "--package",
        "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz",
        "stonewright-mcp"
      ],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "bootstrap"
      }
    }
  }
}
```

Add `STONEWRIGHT_WP_ROOT` only when you want path-scoped WP-CLI / LocalWP
discovery. Use the WordPress install folder that contains `wp-config.php`, not
the plugin folder.

For strict tool budgets in Cursor:

```json
"STONEWRIGHT_MCP_TOOL_PROFILE": "low-tools"
```

Shared stdio notes and other clients:
[connect-clients.md](../admin/connect-clients.md).

## Browser MCP (visual work)

Add a separate Playwright MCP when the task needs screenshots or visual checks.
Stonewright does not embed browser tools. Example pattern (merge into the same
MCP config file your Cursor build expects):

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

Verify Playwright tools are visible before the first visual write.

## Verify the connection

After reload:

1. Confirm `stonewright-task-start` appears in Cursor’s MCP tool list.
2. Call `stonewright-task-start` (canonical first call). Use
   `stonewright-context-bootstrap` only as a compatibility path.
3. Optionally call `stonewright-setup-profile` and
   `stonewright-wordpress-mcp-status` after a release or skill sync.

If neither `stonewright-task-start` nor compatibility
`stonewright-context-bootstrap` is visible, Stonewright is not connected. Fix
the config or restart Cursor before WordPress work. Do not inspect private
client config files as an agent workaround, hand-roll JSON-RPC, or run shell
`wp ...` as a substitute for live MCP tools.

## Try a simple task

```text
Use Stonewright to create a draft page titled "Hello from Stonewright"
with a heading block saying "It works" and a paragraph block saying
"Stonewright is connected."
```

Cursor should call `stonewright-task-start` first, then use the returned write
path. MCP tool names use hyphens (`stonewright-content-create-page`), not the
slash ability form.

## Next steps

- [onboarding.md](../onboarding.md) — dual-mode workflows and prompt templates.
- [install-prompts.md](../install-prompts.md) — Option A / Option B copy-paste.
- [abilities.md](../abilities.md) — capability surface.
- [companion.md](../companion.md) — WP-CLI companion details.
- For production plugin sites, set `stonewright_mode` to `production-safe` first.
