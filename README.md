# Stonewright

Stonewright is a WordPress MCP plugin that exposes site-building primitives for
Gutenberg, Full Site Editing, Elementor, media, menus, memory, skills, and
WP-CLI-assisted debugging. It builds well-formed WordPress data with permission,
backup, validation, context, and audit gates.

Stonewright no longer owns design-tool ingestion or automated visual QA. Use a
separate design MCP for design files and user feedback for visual approval.

## Components

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | GPL-2.0-or-later |
| Companion | `companion/` | MIT |
| Skill packs | `skills/` | MIT |
| Documentation | `docs/` | CC BY 4.0 |

## Requirements

- WordPress 6.7+ with `wordpress/mcp-adapter`
- PHP 8.1+
- Composer 2
- Node.js 20+ for the companion
- Elementor 3.21+ for Elementor abilities
- WP-CLI for companion-assisted WordPress work

## Quickstart

```bash
cd /path/to/wp-content/plugins
git clone https://github.com/stonewright/stonewright-wp-mcp.git stonewright

cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
node dist/index.js
```

MCP clients call hyphenated tool names. First smoke test:

```text
stonewright-ping
stonewright-context-bootstrap
```

For browser testing and screenshots, configure a separate Playwright MCP server
next to Stonewright with `npx @playwright/mcp@latest`.

## Companion Environment

Copy `companion/.env.example` to `companion/.env`.

| Variable | Required | Description |
|---|---|---|
| `COMPANION_BEARER_TOKEN` | production | Token callers send to the companion HTTP server |
| `COMPANION_ALLOWED_ORIGINS` | production | Comma-separated allowed request origins |
| `PORT` | optional | Enables companion HTTP transport |
| `STONEWRIGHT_WP_CLI_BIN` | optional | WP-CLI executable path; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | optional | Default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | optional | Comma- or semicolon-separated allowed WP-CLI roots |
| `MCP_PROXY_TARGET` | optional | Upstream MCP server to proxy requests to |
| `MCP_PROXY_TOKEN` | optional | Bearer token for the proxy target |

## Plugin Options

| Option | Values | Default | Description |
|---|---|---|---|
| `stonewright_mode` | `development`, `staging`, `production-safe` | `development` | Production-safe mode requires confirmation tokens for destructive writes |
| `stonewright_companion_url` | URL string | `http://127.0.0.1:8765` | Internal URL of the companion |
| `stonewright_custom_instructions` | text | empty | Persistent site-specific agent instructions |
| `stonewright_memory_enabled` | boolean | true | Enables persistent site memory |

## Architecture

```
MCP client
   |
WordPress MCP Adapter
   |
Stonewright plugin  ---> WordPress posts, blocks, FSE, Elementor, media
   |
Companion           ---> WP-CLI, health checks, optional MCP proxy
```

The companion can write to WordPress only through guarded, tokenized WP-CLI
commands. It blocks arbitrary PHP and shell entry points such as `wp eval`,
`wp eval-file`, `wp shell`, `wp package`, `--exec`, and `--require`.

## Further Reading

- [Plugin documentation](plugin/README.md)
- [Installation guide](docs/installation.md)
- [Companion documentation](companion/README.md)
- [Skill packs](skills/README.md)
- [Documentation index](docs/index.md)
- [Security model](plugin/SECURITY.md)
