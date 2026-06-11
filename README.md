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
git clone https://github.com/cosmincraciun97/stonewright-wp-mcp.git stonewright

cd stonewright/plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
PORT=8765 COMPANION_BEARER_TOKEN=change-this-long-random-token COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1 node dist/index.js
```

MCP clients call hyphenated tool names. First smoke test:

```text
stonewright-ping
stonewright-context-bootstrap
```

For browser testing and screenshots, configure a separate Playwright MCP server
next to Stonewright with `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`.
In locked-down environments where `npx` cannot fetch packages or write to the
user npm cache, use the already-connected Playwright MCP server rather than a
one-off Playwright CLI install.

## Prompting Stonewright

Begin every task by asking the AI client to call `stonewright-context-bootstrap`.
Good prompts name the target page, template, post, menu, or media item; the
allowed editor surface; the safety mode; visual references or content sources;
and the acceptance checks.

Minimal task prompt:

```text
Use Stonewright for this WordPress task. Start with stonewright-context-bootstrap.
Edit page {id or title}. Use native Gutenberg/Elementor abilities first.
Snapshot before writes, validate design specs before rendering, and verify
desktop, tablet, and mobile breakpoints with no horizontal overflow.
```

For visual work, include the design URL or screenshot, exact assets, whether
global styles may be changed, and whether Elementor HTML widgets are allowed.
By default, Stonewright should use native WordPress and Elementor widgets.

## Companion Environment

Copy `companion/.env.example` to `companion/.env`.

| Variable | Required | Description |
|---|---|---|
| `COMPANION_BEARER_TOKEN` | production | Token callers send to the companion HTTP server |
| `COMPANION_ALLOWED_ORIGINS` | production | Comma-separated allowed request origins |
| `PORT` | optional | Enables companion HTTP transport; use `8765` for WordPress-side WP-CLI abilities |
| `STONEWRIGHT_MCP_URL` | optional | WordPress MCP endpoint proxied into stdio MCP clients |
| `WP_API_USERNAME` | optional | WordPress username for Application Password auth |
| `WP_API_PASSWORD` | optional | WordPress Application Password |
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
- [Onboarding guide](docs/onboarding.md)
- [Installation guide](docs/installation.md)
- [Companion documentation](companion/README.md)
- [Skill packs](skills/README.md)
- [Documentation index](docs/index.md)
- [Security model](plugin/SECURITY.md)
