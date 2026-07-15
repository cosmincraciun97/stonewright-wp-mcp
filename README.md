<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>AI builder tools for WordPress MCP</strong><br />
  Full plugin fidelity, or plugin-less Direct mode over core REST + companion.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="release" src="https://img.shields.io/badge/version-1.0.0--alpha.67-blue" /></a>
  <img alt="plugin license" src="https://img.shields.io/badge/plugin-AGPL--3.0--or--later-green" />
  <img alt="companion license" src="https://img.shields.io/badge/companion-MIT-blue" />
  <img alt="php" src="https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4" />
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-%3E%3D6.7-21759b" />
</p>

Stonewright is the MCP surface agents use to build and operate real WordPress sites: Gutenberg/FSE, Elementor, content, media, menus, memory, skills, direct PHP runtime, and tokenized WP-CLI — with permissions, backups, validation, and audit logs.

## Why Stonewright

- **Elementor widget intelligence** — schema by Content, Style, and Advanced before writes.
- **Block themes and Gutenberg** — core blocks, `theme.json`, templates, patterns, FSE.
- **Persistent memory** — project conventions and learned corrections across sessions.
- **Direct PHP runtime** — `stonewright/php-execute` for short WordPress-runtime snippets.
- **Plugin-less Direct mode** — companion + Application Password against core REST when the plugin is not installed.

## Two equal install paths

| Path | When to use | Needs plugin? | HTTP local OK? |
|---|---|---|---|
| **Plugin mode** | Full fidelity (Elementor DesignSpec, PHP execute, skills/memory, audit, backups) | Yes | Yes |
| **Plugin-less / Direct mode** | Core WordPress REST only via companion Application Password | No | Yes |

Honest capability matrix: [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md).

### Path A — Plugin mode (ZIP → Setup wizard)

1. Download `stonewright-<version>.zip` from [GitHub Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases).
2. **Plugins → Add New → Upload Plugin** → activate **Stonewright**.
3. Open **Stonewright → Setup** (`admin.php?page=stonewright`), enable abilities, create an Application Password.
4. Point your MCP client at the companion (below) or the site MCP endpoint:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

HTTP local URLs work. Setup treats HTTP as informational, not a hard fail.

### Path B — Plugin-less / Direct mode (companion + App Password)

No plugin install required. The companion talks to core WordPress REST.

1. Create a WordPress Application Password for an admin user.  
   On plain HTTP local sites, add to `wp-config.php`:

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
```

2. Install/run the companion (npx release tarball or local `npm run build`).

3. Example `~/.stonewright/sites.json` (HTTP allowed):

```json
{
  "sites": {
    "local": {
      "url": "http://transavia-local.local",
      "username": "admin",
      "applicationPassword": "xxxx xxxx xxxx xxxx xxxx xxxx"
    }
  }
}
```

4. MCP client config (Claude Desktop / Code style JSON):

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "--package", "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/v1.0.0-alpha.67/stonewright-companion-1.0.0-alpha.67.tgz", "stonewright-mcp"],
      "env": {
        "STONEWRIGHT_MODE": "direct",
        "STONEWRIGHT_WP_URL": "http://transavia-local.local",
        "STONEWRIGHT_WP_USERNAME": "admin",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

5. First probe calls:

```text
stonewright-site-discover
stonewright-setup-profile
```

Then try content list/create via Direct tools. Elementor/DesignSpec tools require the plugin — they fail with a clear install message, not a cryptic crash.

Repeatable smoke: `cd companion && npm run build && node scripts/e2e-direct.mjs` (see [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md)).

## Admin pages (plugin mode)

| Slug | UI label |
|---|---|
| `stonewright` | Setup |
| `stonewright-abilities` | AI Abilities |
| `stonewright-blueprints` | Blueprints |
| `stonewright-sandbox` | Sandbox |
| `stonewright-skills` | Skills |
| `stonewright-memory` | Memory |
| `stonewright-audit-log` | Audit Log |
| `stonewright-status` | Dashboard |

Dark/light theme toggle lives in the admin shell header. Theme preference is per-user.

## Notable abilities (plugin)

- **Site pulse** — hardening/performance score on Dashboard
- **Blueprints / brand kits / playbooks** — design catalogs + AI prompts
- **Change timeline / restore** — post mutation history
- **Page digest / build-tree** — structure introspection
- **Learning records + feedback-capture** — persistent corrections
- **Recurring audit errors** — auto learning + task-start warnings
- **MCPB bundle, kill switch, updater** — packaging and safety

Start every task with:

```text
stonewright-task-start
```

Compatibility: `stonewright-context-bootstrap`, `stonewright-workflow-preflight`.

If Stonewright MCP tools are missing from the client tool list, reload the client or fix MCP config. Do not bypass with scratch scripts or direct REST ability runners.

## Components & licenses

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | AGPL-3.0-or-later |
| Visual workspace | `visual/` | AGPL-3.0-or-later |
| Companion | `companion/` | MIT |
| Skill packs | `skills/` | MIT |
| Documentation | `docs/` | CC BY 4.0 |

## Requirements

- WordPress 6.7+ (plugin mode: `wordpress/mcp-adapter`)
- PHP 8.1+
- Node.js 20+ for the companion
- Elementor 3.21+ only if you use Elementor abilities
- WP-CLI optional for tokenized companion CLI workflows

## Build & test

```bash
cd plugin
composer install
composer test
composer phpstan
composer phpcs

cd ../companion
npm install
npm run typecheck
npm test
npm run build
```

## Docs

- [Installation](docs/installation.md)
- [Direct mode E2E matrix](docs/direct-mode-e2e.md)
- [Companion](docs/companion.md)
- [Security](docs/security.md)
- [Abilities truth matrix](docs/ability-truth-matrix.md)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Feature work lands on topic branches; `main` stays release-ready.
