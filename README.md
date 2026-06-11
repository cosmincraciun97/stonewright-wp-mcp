<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>MCP tools for WordPress builders</strong><br />
  Secure WordPress, Gutenberg, Elementor, WooCommerce, and content-model
  automation for AI agents.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="release" src="https://img.shields.io/badge/version-1.0.0--alpha.13-blue" /></a>
  <img alt="plugin license" src="https://img.shields.io/badge/plugin-GPL--2.0--or--later-green" />
  <img alt="companion license" src="https://img.shields.io/badge/companion-MIT-blue" />
  <img alt="php" src="https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4" />
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-%3E%3D6.7-21759b" />
  <img alt="abilities" src="https://img.shields.io/badge/MCP%20abilities-200%2B-6d5dfc" />
  <img alt="ci" src="https://img.shields.io/github/actions/workflow/status/cosmincraciun97/stonewright-wp-mcp/ci.yml?branch=main&label=CI" />
</p>

Stonewright exposes guarded WordPress building primitives to MCP-compatible AI
clients. It covers Gutenberg, Full Site Editing, Elementor, Elementor widget
intelligence, content-model plugins, WooCommerce catalog work, media, menus,
Persistent memory, skills, and WP-CLI-assisted debugging. It builds
well-formed WordPress data with permission, backup, validation, context, and
audit gates.

## Components

| Capability | What Stonewright gives the agent |
|---|---|
| Elementor widget intelligence | Reads widget schemas by Content, Style, and Advanced tabs before writing settings. |
| Block themes and Gutenberg | Works with core blocks, `theme.json`, templates, template parts, patterns, and FSE global styles. |
| Persistent memory | Stores project conventions and repeatable lessons across sessions. |
| Safe WP-CLI | Runs tokenized WP-CLI commands through the companion while blocking arbitrary PHP/shell entry points. |

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | GPL-2.0-or-later |
| Companion | `companion/` | MIT |
| Skill packs | `skills/` | MIT |
| Documentation | `docs/` | CC BY 4.0 |

## Requirements

- WordPress 6.7+ with `wordpress/mcp-adapter`
- PHP 8.1+
- Composer 2 for source installs
- Node.js 20+ for the optional companion
- Elementor 3.21+ for Elementor abilities
- WP-CLI for companion-assisted WordPress work, or LocalWP with its bundled WP-CLI

## Install From Release

1. Download `stonewright-<version>.zip` from
   [GitHub Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases).
2. In WordPress Admin, open **Plugins > Add New > Upload Plugin** and upload
   the ZIP.
3. Activate **Stonewright**.
4. Open **Stonewright > Configuration** and enable the plugin.
5. Create a WordPress Application Password from **Users > Profile**. Use the
   generated Application Password for MCP auth; do not use the wp-admin login
   password.

MCP endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

First calls:

```text
stonewright-ping
stonewright-workflow-preflight
stonewright-context-bootstrap
```

Use `stonewright-workflow-preflight` for fast task setup. It returns a context
token, active mode, auth reminders, compact Elementor capability data,
task-aware MCP tool names, and a compact call sequence. For ACF, ACPT, Meta
Box, ASE, Pods, WooCommerce, or custom field tasks, it also returns compact
specialization guidance.

## Optional Companion

Download `stonewright-companion-<version>.tgz` from the same release and install
it globally:

```bash
npm install -g ./stonewright-companion-<version>.tgz
```

Then configure MCP clients that use a local stdio server with:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "stonewright-mcp",
      "env": {
        "STONEWRIGHT_MCP_URL": "https://your-site.example.com/wp-json/mcp/stonewright",
        "WP_API_USERNAME": "your-wp-username",
        "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_WP_ROOT": "/absolute/path/to/wordpress"
      }
    }
  }
}
```

This starts the local Stonewright companion over stdio. The companion then
proxies the WordPress MCP endpoint using the Application Password credentials.

Set `PORT`, `COMPANION_BEARER_TOKEN`, and `COMPANION_ALLOWED_ORIGINS` when you
also want the companion HTTP bridge for WordPress-side `stonewright/wp-cli-*`
abilities.

## Source Install

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
stonewright-workflow-preflight
stonewright-context-bootstrap
```

For browser testing and screenshots, configure a separate Playwright MCP server
next to Stonewright before visual work:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart the AI client after adding Playwright so the tool list refreshes. If no
Playwright/browser tool is visible, the agent should stop before the first
visual write instead of building blind. In locked-down environments where `npx`
cannot fetch packages or write to the user npm cache, use the already-connected
Playwright MCP server rather than a one-off Playwright CLI install.

## Prompting Stonewright

Begin every task by asking the AI client to call `stonewright-context-bootstrap`.
Good prompts name the target page, template, post, menu, or media item; the
allowed editor surface; the safety mode; visual references or content sources;
and the acceptance checks.

Minimal task prompt:

```text
Use Stonewright for this WordPress task. Start with stonewright-context-bootstrap.
Edit page {id or title}. Use native Gutenberg/Elementor abilities first.
For visual work, verify Playwright/browser MCP is connected before writing.
Snapshot before writes, validate design specs before rendering, and verify
desktop, tablet, and mobile breakpoints with no horizontal overflow.
```

For visual work, include the design URL or screenshot, exact assets, whether
global styles may be changed, and whether Elementor HTML widgets are allowed.
By default, Stonewright should use native WordPress and Elementor widgets.
For repeated cards, galleries, logos, or section grids, ask the agent to use a
spec or bundle first pass and reserve single-widget calls for screenshot-driven
corrections.

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
| `STONEWRIGHT_CREDENTIAL_STORE` | optional | Per-project JSON file for saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | optional | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | optional | Auto-create missing local credentials through guarded WP-CLI; default `local-only` |
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
- [Plugin specializations](docs/specializations.md)
- [Documentation index](docs/index.md)
- [Security model](plugin/SECURITY.md)
