# Getting started with Claude Code

This guide gets you from zero to a working Claude Code + Stonewright setup in about five minutes.

## Prerequisites

- A WordPress site running locally or on a server you control (WordPress 6.7+, PHP 8.1+).
- Composer 2 installed for source installs.
- Stonewright companion installed from the release package with
  `npm install -g ./stonewright-companion-<version>.tgz`.
- Claude Code installed (`npm install -g @anthropic-ai/claude-code` or the desktop app).

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

In the WordPress admin: **Users > Profile > Application Passwords**.

Give it a name like `Claude Code` and click **Add New Application Password**. Copy the generated password immediately — you cannot retrieve it again.

## 3. Configure Claude Code

Register Stonewright:

```bash
claude mcp add stonewright -- stonewright-mcp \
  --env STONEWRIGHT_SITE_URL='https://your-site.example.com' \
  --env STONEWRIGHT_MCP_URL='https://your-site.example.com/wp-json/mcp/stonewright' \
  --env WP_API_USERNAME='your-wp-username' \
  --env WP_API_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  --env PORT='8765' \
  --env COMPANION_BEARER_TOKEN='change-this-long-random-token' \
  --env COMPANION_ALLOWED_ORIGINS='http://localhost,http://127.0.0.1'
```

Add `--env STONEWRIGHT_WP_ROOT='...'` only when you want WP-CLI helper tools or
LocalWP discovery. The value is the absolute WordPress install folder containing
`wp-config.php`, such as `D:\\Sites\\example\\app\\public` or
`/Users/me/Sites/example/app/public`; it is not the plugin folder.

Register the separate Playwright MCP for browser testing and screenshots:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart Claude Code after adding the servers. Before any visual Stonewright
write, verify the Playwright/browser tool is visible in Claude Code.

## 4. Verify the connection

In Claude Code, run:

```
Use MCP tool stonewright-ping.
```

Claude Code should call the ability and return something like:

```json
{ "ok": true, "message": "pong" }
```

If you get a 401, check the Application Password and the Authorization header format.

## 5. Try a simple task

```
Use Stonewright to create a draft page titled "Hello from Stonewright"
with a heading block saying "It works" and a paragraph block saying
"Stonewright is connected."
```

Claude Code should first call `stonewright-context-bootstrap`, then use the returned
`stonewright_context_token` for write tools. WordPress ability names in the docs use
slashes, but MCP tool names use hyphens: `stonewright/content-create-page`
becomes `stonewright-content-create-page`.

## Next steps

- Read [abilities.md](../abilities.md) to see everything Stonewright can do.
- If you need WP-CLI acceleration, set up the [companion bridge](../companion.md).
- For Elementor workflows, review the [design-to-wordpress skill](../skills.md).
- For production sites, set `stonewright_mode` to `production-safe` first.
