# Getting started with Claude Code

This guide gets you from zero to a working Claude Code + Stonewright setup in about five minutes.

## Prerequisites

- A WordPress site running locally or on a server you control (WordPress 6.7+, PHP 8.1+).
- Composer 2 installed.
- Claude Code installed (`npm install -g @anthropic-ai/claude-code` or the desktop app).

## 1. Install the plugin

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/stonewright/stonewright-wp-mcp.git stonewright
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
claude mcp add stonewright -- npx -y --package @stonewright/companion@latest stonewright-mcp \
  --env STONEWRIGHT_SITE_URL='https://your-site.example.com' \
  --env STONEWRIGHT_MCP_URL='https://your-site.example.com/wp-json/mcp/stonewright' \
  --env WP_API_USERNAME='your-wp-username' \
  --env WP_API_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx'
```

Register the separate Playwright MCP for browser testing and screenshots:

```bash
claude mcp add playwright -- npx @playwright/mcp@latest
```

Restart Claude Code after adding the servers.

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
