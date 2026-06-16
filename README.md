<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>MCP tools for WordPress builders</strong><br />
  Secure WordPress, Gutenberg, Elementor, WooCommerce, and content-model
  automation for AI agents.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="release" src="https://img.shields.io/badge/version-1.0.0--alpha.25-blue" /></a>
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
persistent memory, skills, and WP-CLI-assisted debugging. It builds
well-formed WordPress data with permission, backup, validation, context, and
audit gates.

## Components

| Capability | What Stonewright gives the agent |
|---|---|
| Elementor widget intelligence | Reads widget schemas by Content, Style, and Advanced tabs before writing settings. |
| Fast Elementor and content builds | Applies full page specs, Elementor batch mutations, and CPT/custom-field bulk upserts in a few guarded calls instead of many tiny writes. |
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
5. On **Stonewright > Configuration**, generate an Application Password and
   copy the setup note for your MCP client. Use that Application Password for
   MCP auth; do not use the wp-admin login password.

MCP endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

First calls:

```text
stonewright-ping
verify stonewright-context-bootstrap is visible in the MCP tool list
stonewright-wordpress-mcp-status
stonewright-workflow-preflight
stonewright-context-bootstrap
```

If `stonewright-context-bootstrap` is missing, reload or fix the MCP client
config before WordPress work. Local agent skills, repository files, private
client config files, and `/wp-json/stonewright/v1/abilities/run` shell calls
are not substitutes for the live Stonewright MCP server.
If the companion is visible but proxied WordPress tools are missing, call
`stonewright-wordpress-mcp-status`; setup-profile and direct `stonewright-wp-cli-*`
tools remain available while you fix credentials or endpoint URLs.

Use `stonewright-workflow-preflight` for fast task setup. It returns a context
token, active mode, auth reminders, compact Elementor capability data,
task-aware MCP tool names, and a compact call sequence. For ACF, ACPT, Meta
Box, ASE, Pods, WooCommerce, or custom field tasks, it also returns compact
specialization guidance.

For Elementor or design-to-WordPress work, prefer the fast path:

```text
stonewright-workflow-preflight
stonewright-elementor-v3-build-page-from-spec
stonewright-elementor-v3-batch-mutate
stonewright-content-bulk-upsert-posts
```

Those tools keep token use low by doing validation, backup, diagnostics,
timing, and many related writes in one guarded call.

## Optional Companion

Fastest MCP-client setup uses the versioned GitHub release tarball through
`npx`, with no global install or npm registry dependency:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.42/stonewright-companion-1.0.0-alpha.42.tgz"],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "your-wp-username",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

This starts the local Stonewright companion over stdio. The companion proxies
the WordPress MCP endpoint using the Application Password credentials.
The companion defaults to the compact `essential` tool profile, so new MCP
sessions stay on the Stonewright fast-path surface instead of registering every
specialized tool.

For local WordPress sites, add `STONEWRIGHT_WP_ROOT` when you want guarded
WP-CLI helper tools or LocalWP discovery. Call `stonewright-setup-profile` once
for copy-paste config, platform checks, credential status, and WP-CLI notes.

After adding the companion, restart or reload the AI client and verify the
tool list includes `stonewright-context-bootstrap` before the first WordPress
task.

`STONEWRIGHT_WP_ROOT` is optional. Add it only when you want the companion to run
WP-CLI helper tools or auto-discover LocalWP. Set it to the absolute WordPress
install folder that contains `wp-config.php`, for example
`D:\\Local Sites\\example\\app\\public` on Windows or
`/Users/me/Sites/example/app/public` on macOS. It is not a URL and it is not the
Stonewright plugin folder. If omitted, agents can pass an absolute `path` in
each WP-CLI tool call.

Most users do not need the companion HTTP bridge. Use **Local WP-CLI bridge
(advanced)** on the Configuration page only when you deliberately run a local
HTTP bridge for WordPress-side WP-CLI abilities.

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
If stonewright-context-bootstrap is not visible in the MCP tool list, stop and
ask me to reload or fix the Stonewright MCP config.
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

Example prompts:

```text
Use Stonewright to implement the attached Figma design in Elementor V3. Start
with stonewright-context-bootstrap and stonewright-workflow-preflight, extract
layout, spacing, colors, typography, and responsive behavior, render with
stonewright-elementor-v3-build-page-from-spec, then use
stonewright-elementor-v3-batch-mutate for polish. Verify desktop, tablet, and
mobile screenshots against the design.
```

```text
Use Stonewright to create an ACF field group for Case Studies with client logo,
industry, challenge, solution, results metrics, testimonial, gallery, and CTA
fields. Attach it to the case-study post type, add three sample entries, and
verify fields are available for dynamic Elementor templates.
```

```text
Use Stonewright with CPT UI to create a Projects post type and Project Type
taxonomy. Add labels, archive support, featured images, REST visibility, and
sensible rewrite slugs. Then seed sample projects and build a responsive
archive layout that can be filtered by taxonomy.
```

## Privacy Boundary

Built-in skill packs in `skills/` are public project assets. Site-specific
skills, memory, and custom instructions are stored in that WordPress install and
are not bundled into release ZIPs or the npm companion. Do not paste private
credentials, site memory, or client-specific instructions into public docs,
issues, commits, or release notes.

## Companion Environment

Copy `companion/.env.example` to `companion/.env`.

| Variable | Required | Description |
|---|---|---|
| `COMPANION_BEARER_TOKEN` | production | Token callers send to the companion HTTP server |
| `COMPANION_ALLOWED_ORIGINS` | production | Comma-separated allowed request origins |
| `PORT` | optional | Enables companion HTTP transport; use `8765` for WordPress-side WP-CLI abilities |
| `STONEWRIGHT_WP_URL` | recommended | WordPress site URL; companion derives `/wp-json/mcp/stonewright` |
| `STONEWRIGHT_WP_USERNAME` | recommended | WordPress username for Application Password auth |
| `STONEWRIGHT_WP_APP_PASSWORD` | recommended | WordPress Application Password |
| `STONEWRIGHT_MCP_TOOL_PROFILE` | optional | Compact proxied tool surface for new MCP sessions; defaults to `essential`; set `full` only when a specialist session needs every WordPress MCP tool |
| `STONEWRIGHT_MCP_URL` | optional | Explicit WordPress MCP endpoint override |
| `STONEWRIGHT_CREDENTIAL_STORE` | optional | Per-project JSON file for saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | optional | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | optional | Auto-create missing local credentials through guarded WP-CLI; default `local-only` |
| `STONEWRIGHT_WP_CLI_BIN` | optional | WP-CLI executable path; defaults to `wp` |
| `STONEWRIGHT_WP_ROOT` | optional | Absolute WordPress install folder containing `wp-config.php`; default WP-CLI working directory |
| `STONEWRIGHT_WP_ALLOWED_ROOTS` | optional | Comma- or semicolon-separated allowed WP-CLI roots |
| `MCP_PROXY_TARGET` | optional | Upstream MCP server to proxy requests to |
| `MCP_PROXY_TOKEN` | optional | Bearer token for the proxy target |

## Plugin Options

| Option | Values | Default | Description |
|---|---|---|---|
| `stonewright_mode` | `development`, `staging`, `production-safe` | `development` | Production-safe mode requires confirmation tokens for destructive writes |
| `stonewright_companion_url` | URL string | `http://127.0.0.1:8765` | Internal URL of the optional companion HTTP bridge |
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

If Stonewright helps your WordPress build workflow, star the repository and
share a real use case. It helps the project earn trust with WordPress and AI
tooling communities.

- [Plugin documentation](plugin/README.md)
- [Onboarding guide](docs/onboarding.md)
- [Installation guide](docs/installation.md)
- [Companion documentation](companion/README.md)
- [Skill packs](skills/README.md)
- [Plugin specializations](docs/specializations.md)
- [Documentation index](docs/index.md)
- [Security model](plugin/SECURITY.md)
