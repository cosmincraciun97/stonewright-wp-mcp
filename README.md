# Stonewright

Stonewright is a WordPress plugin that exposes 67 site-building primitives as MCP tools, letting AI agents create and edit Gutenberg and Elementor pages from a structured design spec without touching the database directly or executing arbitrary PHP. You describe what you want; Stonewright translates it into well-formed WordPress data with an audit trail behind every write.

## What is in the box

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | GPL-2.0-or-later |
| Companion (Node bridge) | `companion/` | MIT |
| Skill packs for Claude Code | `skills/` | MIT |
| Documentation | `docs/` | CC BY 4.0 |

## Requirements

- WordPress 6.7+ with `wordpress/mcp-adapter` (6.9+ recommended)
- PHP 8.1+
- Composer 2
- Node.js 20+ (companion only)
- Elementor 3.21+ (Elementor abilities only)

## Quickstart

```bash
# Clone into your plugins directory
cd /path/to/wp-content/plugins
git clone https://github.com/stonewright/stonewright-wp-mcp.git stonewright

# Install PHP dependencies
cd stonewright/plugin
composer install --no-dev

# Activate the plugin
wp plugin activate stonewright

# (Optional) Start the companion for Figma ingestion and pixel QA
cd ../companion
npm install
npm run build
node dist/index.js
```

The MCP server is now available at:

```
https://your-site.example.com/wp-json/stonewright/v1/mcp
```

### Wire to Claude Code

Add the following to your `claude_desktop_config.json` (or `.claude/settings.json`):

```json
{
  "mcpServers": {
    "stonewright": {
      "url": "https://your-site.example.com/wp-json/stonewright/v1/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_APPLICATION_PASSWORD"
      }
    }
  }
}
```

Generate an Application Password in **WordPress Admin > Users > Profile > Application Passwords**.

## Environment variables

These apply to the companion (`companion/.env`). Copy `companion/.env.example` to start.

| Variable | Required | Description |
|---|---|---|
| `STONEWRIGHT_WP_URL` | Yes | Base URL of your WordPress installation (no trailing slash) |
| `STONEWRIGHT_WP_APP_PASSWORD` | Yes | WordPress application password — `username:app-password` |
| `FIGMA_TOKEN` | Figma abilities | Personal access token from figma.com/settings |
| `COMPANION_BEARER_TOKEN` | Yes (production) | Token callers must send to the companion HTTP server |
| `COMPANION_ALLOWED_ORIGINS` | Recommended | Comma-separated allowed request origins |
| `PORT` | No | Companion HTTP port (default: 3500) |
| `MCP_PROXY_TARGET` | No | Upstream MCP server to proxy requests to |
| `MCP_PROXY_TOKEN` | No | Bearer token for the proxy target |

## Plugin options

| Option | Values | Default | Description |
|---|---|---|---|
| `stonewright_mode` | `development`, `staging`, `production-safe` | `development` | `production-safe` disables all destructive abilities |
| `stonewright_companion_url` | URL string | — | Internal URL of the running companion server |
| `stonewright_figma_token` | Token string | — | Figma access token stored server-side (use companion env var instead when possible) |

## Further reading

- [Plugin documentation](plugin/README.md)
- [Companion documentation](companion/README.md) (if it exists)
- [Skill packs](skills/README.md) (if it exists)
- [Full documentation](docs/index.md)
- [Security model](plugin/SECURITY.md)
- [Changelog](plugin/CHANGELOG.md)
- [Contributing](plugin/CONTRIBUTING.md)

## Architecture

```
MCP client (Claude Code / Codex)
        |
        v
WordPress MCP Adapter  -->  Stonewright server  -->  67 Stonewright abilities
        |
        v                                                |
WordPress core (posts, blocks, FSE, options, media)     |
                                                         v
Companion Node bridge  <---  Figma MCP, Playwright, pixel diff
```

The plugin owns all WordPress writes. The companion handles Figma ingestion, screenshots, and pixel diffs and never writes to WordPress directly.

## License

Plugin: GPL-2.0-or-later.
Companion and skills: MIT.
Documentation: CC BY 4.0.
