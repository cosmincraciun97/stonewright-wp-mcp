# Getting started with Cursor

This guide gets you from zero to a working Cursor + Stonewright MCP setup.
Prefer user-level config for secrets; do not commit Application Passwords into
project-tracked files.

Verified docs snapshot: Cursor is a **tier-1 certification target** in
[verified-client-versions.md](../verified-client-versions.md) (config kind:
JSON; manual smoke pending until a
[client acceptance](../releases/client-acceptance-template.md) pass). Prefer
`~/.cursor/mcp.json` for credentials. Paste prompts stay credential-free.

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

Use an explicit saved policy: `direct-only` without the plugin and
`plugin-only` when the plugin is installed. Reserve auto-detection for a
connection where Direct fallback is intentional.

## Fastest start (Direct mode)

Generate a WordPress Application Password, then add Stonewright to Cursor.

Use the installer so Cursor receives an alias-specific, secret-free entry:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username editor \
  --mode direct-only --client cursor
```

Use `--profile essential` for the normal bounded working surface or
`--profile low-tools` for a strict tool-cap client. Bootstrap is a diagnostic,
not a permanent Cursor default.

**Restart Cursor or reload MCP servers** (required). Smoke test:

```text
Use MCP tool stonewright-task-start with a short request that you only want a
connection smoke test.
```

Expect mode Direct and local skills/memory hints. Then call
`stonewright-site-discover` before REST work. Destructive tools require
`confirm:true` when writes are gated. Direct mode cannot write custom
PHP/CSS/JS/HTML.

Copy-paste Option B: [install-prompts.md](../install-prompts.md).

## Plugin mode

1. Install and activate the Stonewright WordPress plugin (release ZIP or source
   under `wp-content/plugins`).
2. Open **Stonewright > Configuration**, enable abilities, choose operating
   mode, and generate an Application Password.
3. Register an alias-specific Plugin connection:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect add \
  --alias site-a --url https://site-a.example --username editor \
  --mode plugin-only --client cursor --profile essential \
  --plugin-enabled yes --wp-surface essential
```

If the alias exists, run `stonewright connect repair site-a --client cursor
--mode plugin-only`; it reuses the saved credential and updates only that named
entry. Restart Cursor, then require `connect verify` to report the same alias
and `active_mode=plugin`.

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

The agent asks once whether to use Playwright (recommended), another connected
browser, or none; it must ask before scanning Cursor's tools/private config and
again before installing or configuring a missing provider. Stonewright does not
embed browser tools. After approval, merge this example into the MCP config file
your Cursor build expects:

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
