<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>AI builder tools for WordPress MCP</strong><br />
  Elementor, Gutenberg, content models, PHP runtime execution, and tokenized
  WP-CLI for AI agents.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="release" src="https://img.shields.io/badge/version-1.0.0--alpha.64-blue" /></a>
  <img alt="plugin license" src="https://img.shields.io/badge/plugin-AGPL--3.0--or--later-green" />
  <img alt="companion license" src="https://img.shields.io/badge/companion-MIT-blue" />
  <img alt="php" src="https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4" />
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-%3E%3D6.7-21759b" />
  <img alt="abilities" src="https://img.shields.io/badge/MCP%20abilities-200%2B-6d5dfc" />
  <img alt="ci" src="https://img.shields.io/github/actions/workflow/status/cosmincraciun97/stonewright-wp-mcp/ci.yml?branch=main&label=CI" />
</p>

Stonewright gives MCP-compatible AI clients a fast WordPress building surface.
It covers Gutenberg, Full Site Editing, Elementor, Elementor widget
intelligence, content-model plugins, WooCommerce catalog work, media, menus,
persistent memory, skills, direct PHP runtime execution, and tokenized WP-CLI
debugging. It builds well-formed WordPress data with permissions, backups,
validation, context, and audit logs.

## Why Stonewright

Stonewright is built for agents that need to edit real WordPress sites quickly
without turning every task into broad shell access or dozens of tiny tool calls.

- **Broad WordPress surface:** Gutenberg, FSE, Elementor V3, experimental
  Elementor V4, WooCommerce, media, menus, content models, skills, memory, and
  tokenized WP-CLI.
- **Direct runtime access:** `stonewright/php-execute` runs short PHP snippets
  inside the loaded WordPress runtime when a plugin API call or `$wpdb` query is
  faster than many typed calls.
- **Operator controls:** permissions, backups, confirmation tokens,
  validators, audit logs, sandbox recovery, and production-safe mode are part
  of the ability layer.
- **Fast agent workflows:** context bootstrap, workflow preflight, compact tool
  profiles, Theme Builder apply-template orchestration, CPT/ACF Loop Grid
  flows, batch Elementor mutations, bulk content upserts, and background WP-CLI
  jobs keep MCP sessions small and useful.
- **Transparent releases:** release notes list shipped assets, compatibility
  work, operator-control changes, and verification commands.

## Components

| Capability | What Stonewright gives the agent |
|---|---|
| Elementor widget intelligence | Reads widget schemas by Content, Style, and Advanced tabs before writing settings. |
| Fast Elementor and content builds | Applies full page specs, real Theme Builder templates, CPT/ACF Loop Grid flows, Elementor batch mutations, and bulk upserts in a few compact calls instead of many tiny writes. |
| Block themes and Gutenberg | Works with core blocks, `theme.json`, templates, template parts, patterns, and FSE global styles. |
| Direct PHP runtime | Executes short PHP snippets through `stonewright/php-execute` inside WordPress with loaded plugins and `$wpdb`. |
| Persistent memory | Stores project conventions and repeatable lessons across sessions. |
| Tokenized WP-CLI | Runs WP-CLI commands through the companion with argv tokens for plugin/theme/content operations and long-running jobs. |

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | AGPL-3.0-or-later |
| Visual workspace | `visual/` | AGPL-3.0-or-later |
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
client config files, scratch scripts such as `query-mcp.js` or
`run-ability.js`, helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, direct companion shell launch
scripts such as `query-local-stonewright.js`, action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, plugin/companion
source-code spelunking to reverse-engineer tool schemas, hand-rolled JSON-RPC
calls, and
`/wp-json/stonewright/v1/abilities/run` shell calls are not substitutes for the
live Stonewright MCP server.
If the companion is visible but proxied WordPress tools are missing, call
`stonewright-wordpress-mcp-status`; setup-profile and direct `stonewright-wp-cli-*`
tools remain available while you fix credentials or endpoint URLs.
Do not recover by running `wp ...` commands in a normal shell or by switching to
another PHP adapter. Use `stonewright-php-execute` for short WordPress runtime
snippets, and use `stonewright-wp-cli-status`, `stonewright-wp-cli-discover`,
`stonewright-wp-cli-run`, `stonewright-wp-cli-batch-run`, or
`stonewright-wp-cli-install` for tokenized WP-CLI workflows.

Use `stonewright-workflow-preflight` for fast task setup. It returns a context
token, active mode, auth reminders, compact Elementor capability data,
task-aware MCP tool names, and a compact call sequence. For ACF, ACPT, Meta
Box, ASE, Pods, WooCommerce, or custom field tasks, it also returns compact
specialization guidance.

For Elementor or design-to-WordPress work, prefer the fast path:

```text
stonewright-workflow-preflight
stonewright-elementor-v3-build-page-from-spec
stonewright-theme-builder-apply-template
stonewright-content-model-loop-grid-flow
stonewright-elementor-v3-batch-mutate
stonewright-content-bulk-upsert-posts
```

Those tools keep token use low by doing validation, backup, diagnostics,
timing, and many related writes in one compact call.
For Theme Builder display conditions, call `stonewright-theme-builder-apply-template`
instead of editing Elementor condition meta by hand. For editable repeated
content sections, call `stonewright-content-model-loop-grid-flow` instead of
creating static cards or fanning out CPT, ACF, post, meta, and Loop Grid calls.

## Optional Companion

Fastest MCP-client setup uses the versioned GitHub release tarball through
`npx`, with no global install or npm registry dependency:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.64/stonewright-companion-1.0.0-alpha.64.tgz", "stonewright-mcp"],
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
Do not point IDE MCP configs at `node companion/dist/index.js`; `dist` is a
source build artifact and is intentionally not committed. Use the `npx` release
tarball above, or for source development use
`npm --prefix <repo>/companion run mcp:source`.
Do not configure generic WordPress MCP adapters such as
`@automattic/mcp-wordpress-remote` as the `stonewright` server. Use the
Stonewright companion so setup, status, compact profiles, php-execute, and
WP-CLI tools stay visible even while the WordPress endpoint is being fixed.
The companion defaults to the compact `essential` tool profile, so new MCP
sessions stay on the Stonewright fast-path surface instead of registering every
specialized tool.
For strict tool-cap clients such as Antigravity or Gemini API, set
`STONEWRIGHT_MCP_TOOL_PROFILE=low-tools`. It keeps the whole client-visible
startup surface under 30 tools by hiding legacy duplicate aliases while
preserving composite page, content, media, Gutenberg, Elementor, and direct
WP-CLI batch/background-job paths.

Codex uses TOML instead of JSON. Add this to `~/.codex/config.toml` or a
trusted project `.codex/config.toml`, then restart Codex or reload the IDE MCP
session:

```toml
[mcp_servers.stonewright]
command = "npx"
args = ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.64/stonewright-companion-1.0.0-alpha.64.tgz", "stonewright-mcp"]

[mcp_servers.stonewright.env]
STONEWRIGHT_WP_URL = "https://your-site.example.com"
STONEWRIGHT_WP_USERNAME = "your-wp-username"
STONEWRIGHT_WP_APP_PASSWORD = "xxxx xxxx xxxx xxxx xxxx xxxx"
STONEWRIGHT_MCP_TOOL_PROFILE = "essential"
```

After every release or skill sync, run `stonewright-setup-profile` and
`stonewright-wordpress-mcp-status`. Check `companion_version`,
`expected_companion_package`, and `refresh_required_tool_names`; stale values
mean the MCP client is still running an old companion process or cached tool
list.

Antigravity 2.0, Antigravity IDE, and Antigravity CLI use the shared
`~/.gemini/config/mcp_config.json` location. See
[Antigravity setup](docs/getting-started/antigravity.md) for a copy-paste
config, refresh steps, and troubleshooting when Stonewright does not appear in
`/mcp`.

For local WordPress sites, add `STONEWRIGHT_WP_ROOT` when you want path-scoped
WP-CLI helper tools or LocalWP discovery. Call `stonewright-setup-profile` once
for copy-paste config, platform checks, credential status, and WP-CLI notes.
Do not set `PORT` for normal stdio MCP clients. `PORT` enables the optional HTTP
bridge only; if that port is already in use, stdio MCP should keep working and
the HTTP bridge is skipped unless `STONEWRIGHT_HTTP_REQUIRED=1` is set.

After adding the companion, restart or reload the AI client and verify the
tool list includes `stonewright-context-bootstrap` before the first WordPress
task. If it is missing, stop and fix the MCP client config. Do not inspect
private client config files, create scratch scripts such as `query-mcp.js` or
`run-ability.js`, create helper JSON argument files such as
`bootstrap-args.json`, `cli_command.json`, or `get_structure.json`, launch the
companion through ad hoc scripts such as `query-local-stonewright.js`, create
action scripts such as `run-loop-mutate.js` or `run-bootstrap-and-mutate.js`,
inspect plugin/companion source to reverse-engineer tool schemas, hand-roll
JSON-RPC, call the REST ability runner from shell, or run shell `wp ...`
commands as a Stonewright MCP workaround.

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
allowed editor surface; the operating mode; visual references or content
sources; and the acceptance checks.

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
| `PORT` | optional | Enables optional companion HTTP transport; leave unset for stdio-only MCP clients |
| `STONEWRIGHT_HTTP_REQUIRED` | optional | Set to `1` only when a failed HTTP bridge bind should make startup fail |
| `STONEWRIGHT_WP_URL` | recommended | WordPress site URL; companion derives `/wp-json/mcp/stonewright` |
| `STONEWRIGHT_WP_USERNAME` | recommended | WordPress username for Application Password auth |
| `STONEWRIGHT_WP_APP_PASSWORD` | recommended | WordPress Application Password |
| `STONEWRIGHT_MCP_TOOL_PROFILE` | optional | Compact client-visible tool surface for new MCP sessions; defaults to `essential`; use `low-tools` for strict tool-cap clients; aliases like `antigravity`, `gemini`, `elementor`, `design`, `acf`, `cpt-ui`, `fse`, and `wp cli` normalize to canonical compact profiles; set `full` only when a specialist session needs every WordPress MCP tool |
| `STONEWRIGHT_MCP_URL` | optional | Explicit WordPress MCP endpoint override |
| `STONEWRIGHT_CREDENTIAL_STORE` | optional | Per-project JSON file for saved Application Password fallback |
| `STONEWRIGHT_CREDENTIAL_DIR` | optional | Directory for generated per-project credential files |
| `STONEWRIGHT_WP_APP_PASSWORD_AUTO` | optional | Auto-create missing local credentials through tokenized WP-CLI; default `local-only` |
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

The companion can write to WordPress through tokenized WP-CLI commands. Use
`stonewright/php-execute` for PHP runtime snippets; the companion still blocks
WP-CLI PHP and shell entry points such as `wp eval`, `wp eval-file`,
`wp shell`, `wp package`, `--exec`, and `--require`.

## Further Reading

If Stonewright helps your WordPress build workflow, share a real use case or
open an issue with what worked and what did not. Practical feedback is the best
way to harden the tool.

- [Plugin documentation](plugin/README.md)
- [Onboarding guide](docs/onboarding.md)
- [Installation guide](docs/installation.md)
- [Antigravity setup](docs/getting-started/antigravity.md)
- [Companion documentation](companion/README.md)
- [Skill packs](skills/README.md)
- [Plugin specializations](docs/specializations.md)
- [Release notes](docs/releases/README.md)
- [Documentation index](docs/index.md)
- [Security model](plugin/SECURITY.md)
