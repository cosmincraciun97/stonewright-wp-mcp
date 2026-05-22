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

Add the MCP server to your Claude Code configuration. The config file is at:

- macOS/Linux: `~/.claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "stonewright": {
      "url": "https://your-site.example.com/wp-json/stonewright/v1/mcp",
      "headers": {
        "Authorization": "Bearer your-username:xxxx xxxx xxxx xxxx xxxx xxxx"
      }
    }
  }
}
```

Replace `your-site.example.com` with your site URL and the bearer value with your WordPress username and application password joined with a colon.

Restart Claude Code after saving the config.

## 4. Verify the connection

In Claude Code, run:

```
Use the stonewright/site/ping ability.
```

Claude Code should call the ability and return something like:

```json
{ "status": "pong", "timestamp": "2026-05-21T12:00:00Z" }
```

If you get a 401, check the Application Password and the Authorization header format.

## 5. Try a simple task

```
Use Stonewright to create a draft page titled "Hello from Stonewright"
with a heading block saying "It works" and a paragraph block saying
"Stonewright is connected."
```

Claude Code will call `stonewright/content/create-page` and return the new post ID and URL.

## Next steps

- Read [abilities.md](../abilities.md) to see everything Stonewright can do.
- If you work with Figma, set up the [companion bridge](../companion.md).
- For Elementor workflows, review the [design-to-wordpress skill](../skills.md).
- For production sites, set `stonewright_mode` to `production-safe` first.
