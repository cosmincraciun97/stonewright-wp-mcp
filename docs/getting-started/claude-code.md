# Getting started with Claude Code

This guide gets you from zero to a working Claude Code + Stonewright setup in
about five minutes.

## Prerequisites

- A WordPress site running locally or on a server you control.
- WordPress 6.7+ and PHP 8.1+ (plugin mode).
- Composer 2 installed for source installs of the plugin.
- Node.js 20+ for the Stonewright companion launched through `npx`.
- Claude Code installed.

## Choose your mode

- **Direct mode** (fastest): Application Password only — no WordPress plugin.
  Content, media, menus, templates, taxonomy, users, and local Elementor
  document edits with integrity gates. See counts and gaps in
  [direct-mode-e2e.md](../direct-mode-e2e.md).
- **Plugin mode** (full surface): install the plugin for Elementor engines,
  DesignSpec, php-execute, confirmation tokens, and shared site skills/memory.
  Plugin ability inventory: [ability-truth-matrix.md](../ability-truth-matrix.md).

The companion auto-detects mode (`STONEWRIGHT_MODE=direct|plugin` overrides).

## Fastest start (Direct mode)

Works with only a WordPress Application Password — nothing installed on the
site. Generate an Application Password in **Users → Profile** (or your host’s
app-password UI), then register Stonewright.

Replace `VERSION` with the exact release version without a leading `v`:

```bash
claude mcp add stonewright \
  --env STONEWRIGHT_WP_URL='https://your-site.example.com' \
  --env STONEWRIGHT_WP_USERNAME='your-wp-username' \
  --env STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --env STONEWRIGHT_DIRECT_WRITES=confirm \
  --env STONEWRIGHT_MODE=direct \
  -- npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright-mcp
```

Optional: omit `STONEWRIGHT_MODE=direct` to let the companion auto-detect (plugin
endpoint present → plugin proxy; HTTP 404 → Direct).

Restart Claude Code. Verify with:

```text
Use MCP tool stonewright-task-start with a short request that you only want a
connection smoke test.
```

Expect mode Direct, local skills/memory hints, and write-mode guidance. Then call
`stonewright-site-discover` before choosing REST operations. Destructive tools
require `confirm:true` when `STONEWRIGHT_DIRECT_WRITES=confirm`.

Copy-paste wording for other clients: [install-prompts.md](../install-prompts.md)
Option B.

## 1. Install the plugin (plugin mode)

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/cosmincraciun97/stonewright-wp-mcp.git stonewright
cd stonewright/plugin
composer install --no-dev
```

Activate the plugin:

```bash
wp plugin activate stonewright
```

Or activate it in the WordPress admin under Plugins.
This shell command is only for a human source install on a machine with WP-CLI
already configured. During MCP tasks, agents should not recover by running
`wp ...` in a normal shell, inspecting private client config files, creating
scratch helper scripts, creating helper JSON argument files, launching the
companion through ad hoc shell scripts, creating action scripts, inspecting
plugin/companion source to reverse-engineer tool schemas, hand-rolling
JSON-RPC, or calling the REST runner from shell; they should use Stonewright's
`stonewright-php-execute` for runtime snippets and `stonewright-wp-cli-*` tools
so commands stay tokenized.

## 2. Create an Application Password

In WordPress admin, open **Stonewright > Configuration** and use the
**Application Password** card. Give it a name like `Claude Code` and click
**Generate application password**. Copy the generated password immediately;
WordPress will not show it again.

(Direct mode without the plugin: generate the password from the user profile.)

## 3. Configure Claude Code (plugin mode)

Register Stonewright. Replace `VERSION` with the exact release version without
a leading `v`:

```bash
claude mcp add stonewright \
  --env STONEWRIGHT_WP_URL='https://your-site.example.com' \
  --env STONEWRIGHT_WP_USERNAME='your-wp-username' \
  --env STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --env STONEWRIGHT_MCP_TOOL_PROFILE=bootstrap \
  -- npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright-mcp
```

Add `--env STONEWRIGHT_WP_ROOT='...'` only when you want WP-CLI helper tools or
LocalWP discovery. The value is the absolute WordPress install folder
containing `wp-config.php`, such as `D:\Sites\example\app\public` or
`/Users/me/Sites/example/app/public`; it is not the plugin folder.

For strict tool-cap clients, use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools`
instead of `bootstrap`.

Register the separate Playwright MCP for browser testing and screenshots:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart Claude Code after adding the servers. Before any visual Stonewright
write, verify the Playwright/browser tool is visible in Claude Code.

## 4. Verify the connection

In Claude Code, run:

```text
Use MCP tool stonewright-task-start with a short request that you only want a
connection smoke test.
```

Claude Code should return session context: mode, matched skills, memory
highlights, and write guidance. `stonewright-context-bootstrap` remains a
compatibility path only.

If you get a 401, check the Application Password and the Authorization header
format. If neither `stonewright-task-start` nor compatibility
`stonewright-context-bootstrap` is visible, reload or fix the MCP config before
WordPress work.

## 5. Try a simple task

```text
Use Stonewright to create a draft page titled "Hello from Stonewright"
with a heading block saying "It works" and a paragraph block saying
"Stonewright is connected."
```

Claude Code should first call `stonewright-task-start`, then use the returned
write token / confirmation path for write tools. WordPress ability names in docs
use slashes, but MCP tool names use hyphens:
`stonewright/content-create-page` becomes `stonewright-content-create-page`.

Real Elementor prompt (plugin mode):

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-task-start, render a
validated design spec with stonewright-elementor-v3-build-page-from-spec, then
use stonewright-elementor-v3-batch-mutate for screenshot-driven polish. Verify
desktop, tablet, and mobile with no horizontal overflow.
```

## Next steps

- Read [abilities.md](../abilities.md) to see everything Stonewright can do.
- If you need WP-CLI acceleration, set up the [companion](../companion.md).
- For Elementor workflows, review the [design-to-wordpress skill](../skills.md).
- For production sites, set `stonewright_mode` to `production-safe` first.
- Client-specific notes: [connect-clients.md](../admin/connect-clients.md),
  [verified-client-versions.md](../verified-client-versions.md).
