# Getting started with Claude Code

This guide gets you from zero to a working Claude Code + Stonewright setup in
about five minutes.

## Prerequisites

- A WordPress site running locally or on a server you control.
- WordPress 6.7+ and PHP 8.1+.
- Composer 2 installed for source installs.
- Node.js 20+ for the Stonewright companion launched through `npx`.
- Claude Code installed.

## 1. Install the plugin

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

## 2. Create an Application Password

In WordPress admin, open **Stonewright > Configuration** and use the
**Application Password** card. Give it a name like `Claude Code` and click
**Generate application password**. Copy the generated password immediately;
WordPress will not show it again.

## 3. Configure Claude Code

Register Stonewright:

```bash
claude mcp add stonewright \
  --env STONEWRIGHT_WP_URL='https://your-site.example.com' \
  --env STONEWRIGHT_WP_USERNAME='your-wp-username' \
  --env STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --env STONEWRIGHT_MCP_TOOL_PROFILE=essential \
  -- npx -y https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.43/stonewright-companion-1.0.0-alpha.43.tgz
```

Add `--env STONEWRIGHT_WP_ROOT='...'` only when you want WP-CLI helper tools or
LocalWP discovery. The value is the absolute WordPress install folder
containing `wp-config.php`, such as `D:\Sites\example\app\public` or
`/Users/me/Sites/example/app/public`; it is not the plugin folder.

Register the separate Playwright MCP for browser testing and screenshots:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart Claude Code after adding the servers. Before any visual Stonewright
write, verify the Playwright/browser tool is visible in Claude Code.

## 4. Verify the connection

In Claude Code, run:

```text
Use MCP tool stonewright-ping.
```

Claude Code should call the ability and return:

```json
{ "ok": true, "message": "pong" }
```

If you get a 401, check the Application Password and the Authorization header
format.

## 5. Try a simple task

```text
Use Stonewright to create a draft page titled "Hello from Stonewright"
with a heading block saying "It works" and a paragraph block saying
"Stonewright is connected."
```

Claude Code should first call `stonewright-context-bootstrap`, then use the
returned `stonewright_context_token` for write tools. WordPress ability names in
docs use slashes, but MCP tool names use hyphens:
`stonewright/content-create-page` becomes `stonewright-content-create-page`.

Real Elementor prompt:

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-context-bootstrap and stonewright-workflow-preflight, render a
validated design spec with stonewright-elementor-v3-build-page-from-spec, then
use stonewright-elementor-v3-batch-mutate for screenshot-driven polish. Verify
desktop, tablet, and mobile with no horizontal overflow.
```

## Next steps

- Read [abilities.md](../abilities.md) to see everything Stonewright can do.
- If you need WP-CLI acceleration, set up the [companion](../companion.md).
- For Elementor workflows, review the [design-to-wordpress skill](../skills.md).
- For production sites, set `stonewright_mode` to `production-safe` first.
