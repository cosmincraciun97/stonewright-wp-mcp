<p align="center">
  <img src="assets/brand/stonewright-logo-512.png" alt="Stonewright" width="160" height="160" />
</p>

<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>Safe WordPress automation for AI agents</strong><br />
  Build and operate real WordPress sites through guarded Gutenberg, Elementor, REST and WP-CLI tools—with backups, validation and audit logs.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="Latest release" src="https://img.shields.io/github/v/release/cosmincraciun97/stonewright-wp-mcp?include_prereleases&label=release" /></a>
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/cosmincraciun97/stonewright-wp-mcp/ci.yml?branch=main&label=CI" /></a>
  <img alt="plugin license" src="https://img.shields.io/badge/plugin-AGPL--3.0--or--later-green" />
  <img alt="companion license" src="https://img.shields.io/badge/companion-MIT-blue" />
  <img alt="php" src="https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4" />
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-%3E%3D6.7-21759b" />
</p>

Stonewright is a WordPress MCP stack for AI coding agents. In **Plugin mode** it exposes guarded abilities for Gutenberg and Full Site Editing, Elementor, content and media, menus and site operations, persistent project memory, skills, and tokenized WP-CLI. In **Direct mode**, the companion can work against core WordPress REST with an Application Password without installing the plugin—on a smaller capability surface.

> “Safe” here is a **design goal**: operating modes, permissions, confirmations, backups, validation, readback, and audit logging make agent-driven changes more recoverable. It is not an absolute security guarantee. Use staging, review changes, and keep normal WordPress backups.

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases">Download latest release</a>
  ·
  <a href="docs/installation.md">Installation</a>
  ·
  <a href="SECURITY.md">Security</a>
  ·
  <a href="docs/ability-truth-matrix.md">Capability matrix</a>
  ·
  <a href="docs/direct-mode-e2e.md">Direct mode</a>
  ·
  <a href="CONTRIBUTING.md">Contributing</a>
</p>

<!-- Maintainer: add the Stonewright workflow demo here. Do not remove this comment until the asset is available. -->

## What you can do with Stonewright

- Inspect an existing WordPress site before changing it
- Create or update Gutenberg content and block-theme structures (Plugin mode; partial Direct mode for core posts/pages)
- Build and modify Elementor documents through validated DesignSpec workflows (**Plugin mode**)
- Manage content, media, navigation, and selected site settings
- Create snapshots or revisions before supported mutations
- Validate DesignSpec payloads and read back important changes
- Restore supported changes when something goes wrong (**Plugin mode** audit/restore paths)
- Preserve project conventions and learned corrections (**Plugin mode** memory/skills)
- Perform guarded WP-CLI-assisted operations via the companion
- Use core REST workflows without installing the plugin through **Direct mode**

## Why Stonewright

- **Elementor widget and schema intelligence** — live controls and typed writes (Plugin mode)
- **Gutenberg, FSE, templates, patterns, and `theme.json`**
- **Persistent project memory and learned corrections** (Plugin mode)
- **Validation and readback** on DesignSpec and major write paths
- **Audit logging and change history** (Plugin mode)
- **Backups and restore workflows** for supported post mutations
- **Tool-surface and token-budget management** (profiles, priorities, client caps)
- **Plugin-less Direct mode** for core REST
- **Explicit operating modes** (`development`, `staging`, `production-safe`) and confirmation tokens for destructive work

## Choose your setup

Capabilities differ between modes. Prefer Plugin mode when you need Elementor, blueprints, memory, skills, audit, or full DesignSpec engines.

### Plugin mode — recommended for full capabilities

Install the Stonewright plugin for advanced Elementor workflows, blueprints and brand kits, memory and skills, audit/restore, DesignSpec validation, `php-execute`, and the broader ability surface.

### Direct mode — plugin-less core REST workflows

The companion authenticates with a WordPress Application Password and exposes supported **core REST** tools (content, media, menus, and related site reads) without installing Stonewright. Elementor DesignSpec engines, plugin audit, memory stores, and many plugin-only abilities are **not** available. See the honest matrix in [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md).

## Quick Start

**Plugin mode (about five steps):**

1. Download the latest `stonewright-*.zip` from [GitHub Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases) (includes prereleases).
2. In WordPress: **Plugins → Add New → Upload Plugin** → activate **Stonewright**.
3. Open **Stonewright → Setup**, enable abilities, and create an Application Password.
4. Configure your MCP client to run the companion (see below).
5. Call `stonewright-task-start` (or `stonewright-context-bootstrap` as a compatibility path) before WordPress work.

<details>
<summary>MCP client config (Plugin mode companion)</summary>

Use the latest release companion package URL from [Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases) (do not hardcode a stale alpha):

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": [
        "-y",
        "--package",
        "https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz",
        "stonewright-mcp"
      ],
      "env": {
        "STONEWRIGHT_WP_URL": "https://your-site.example.com",
        "STONEWRIGHT_WP_USERNAME": "admin",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

Replace `VERSION` with the release tag without a leading `v` in the filename (for example `1.0.0-alpha.70`). Site MCP endpoint when using the WordPress MCP adapter directly:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

HTTP local sites are supported; Setup treats plain HTTP as informational, not a hard failure.

</details>

<details>
<summary>Direct mode (plugin-less)</summary>

1. Create a WordPress Application Password for an admin user. On plain HTTP local sites, set `WP_ENVIRONMENT_TYPE` to `local` in `wp-config.php` if Application Passwords require it.
2. Run `npx @stonewright/companion init` (or configure env vars) and paste the MCP JSON into your client.
3. First calls: `stonewright-site-discover`, `stonewright-setup-profile`.
4. Read [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md) for the capability matrix and smoke script.

Example env for Direct mode:

```json
{
  "mcpServers": {
    "stonewright": {
      "command": "npx",
      "args": ["-y", "@stonewright/companion"],
      "env": {
        "STONEWRIGHT_MODE": "direct",
        "STONEWRIGHT_WP_URL": "http://your-local-site.local",
        "STONEWRIGHT_WP_USERNAME": "admin",
        "STONEWRIGHT_WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "STONEWRIGHT_MCP_TOOL_PROFILE": "essential"
      }
    }
  }
}
```

</details>

## How Stonewright makes agent changes safer

Stonewright is designed to make agent-driven WordPress changes safer and more recoverable, not to provide a perfect security sandbox. Most typed mutation workflows pass through combinations of permission checks, operating modes, confirmations, backups, validation, readback, and audit logging.

`stonewright/php-execute` is an advanced full WordPress-runtime capability. It is permission- and mode-gated, audited, and subject to targeted restrictions, but it is not a strict sandbox and does not receive the same structural guarantees as typed DesignSpec or validated mutation workflows.

Typed mutation paths may use combinations of:

- Stonewright operating modes (`development`, `staging`, `production-safe`)
- WordPress permissions and capability checks
- Confirmation tokens for destructive operations in production-safe mode
- Backups or revisions before supported Elementor/theme/content mutations
- Schema and DesignSpec validation before render
- Readback verification on selected write paths
- Audit logging
- Rollback or restore workflows where supported

Not every surface uses every gate. Prefer typed abilities over unrestricted PHP when a typed path exists. Read [SECURITY.md](SECURITY.md) and [docs/security.md](docs/security.md).

## Architecture

```mermaid
flowchart LR
  Client[AI / MCP client]
  Companion[Stonewright Companion]
  Plugin[Stonewright plugin]
  REST[WordPress REST API]
  WP[WordPress core]
  Guten[Gutenberg / FSE]
  Elem[Elementor]
  Content[Content / media / menus]
  Mem[Memory / skills]
  Gates[Backup / validation / readback / audit]

  Client --> Companion
  Companion -->|Plugin mode| Plugin
  Companion -->|Direct mode| REST
  Plugin --> Gates
  Plugin --> Guten
  Plugin --> Elem
  Plugin --> Content
  Plugin --> Mem
  REST --> WP
  WP --> Content
  Plugin --> WP
```

Direct mode has a **smaller** capability surface (core REST). Not every request passes through every component.

## Supported workflows and clients

Stonewright speaks standard MCP (stdio via the companion, and HTTP MCP when the WordPress MCP adapter is active). Configuration samples in this repository follow the common MCP server JSON shape used by several clients.

| Area | Status | Notes |
|---|---|---|
| Companion stdio MCP | Documented | Primary install path in docs |
| WordPress MCP endpoint `/wp-json/mcp/stonewright` | Documented | Plugin + MCP adapter |
| Direct mode core REST | Documented + smoke script | [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md) |
| Specific desktop/CLI AI clients | Not uniformly verified | Use generic MCP config; do not assume a client is verified without a dedicated setup doc |

## Admin interface

Plugin mode admin pages include Setup, Dashboard (Site Pulse), Abilities, Blueprints, Skills, Memory, Sandbox, and Audit Log. Theme toggle lives in the admin shell header.

<!-- Maintainer: add the Dashboard or Site Pulse screenshot here. Do not remove this comment until the asset is available. -->
<!-- Maintainer: add the Blueprints or brand-kit screenshot here. Do not remove this comment until the asset is available. -->
<!-- Maintainer: add the Audit Log or restore screenshot here. Do not remove this comment until the asset is available. -->
<!-- Maintainer: add an Elementor or Gutenberg agent workflow screenshot here. Do not remove this comment until the asset is available. -->

## Requirements

- WordPress 6.7+ (plugin mode uses `wordpress/mcp-adapter` where applicable)
- PHP 8.1+
- Node.js 20+ for the companion
- Elementor 3.21+ only when using Elementor abilities
- WP-CLI optional for tokenized companion CLI workflows

## Current project status and limitations

Stonewright ships **alpha** prereleases. APIs, tools, configuration, and behavior may still change. Test on staging or local environments first. Keep site backups independent of Stonewright. Report security issues privately per [SECURITY.md](SECURITY.md).

This project is **not** marketed as production-ready in the sense of a frozen stable API. Use production-safe mode and review when operating on live sites.

## Documentation

- [Installation](docs/installation.md)
- [Direct mode capability matrix](docs/direct-mode-e2e.md)
- [Companion](docs/companion.md)
- [Security](docs/security.md) · [SECURITY.md](SECURITY.md)
- [Ability truth matrix](docs/ability-truth-matrix.md)
- [Licensing](docs/licensing.md)
- [Upstream code reuse ledger](docs/upstream-code-reuse.md)
- [Release notes](docs/releases/) (five-release retention)

## Development and testing

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

## Components and licenses

| Component | Path | License |
|---|---|---|
| Plugin | `plugin/` | AGPL-3.0-or-later |
| Visual workspace | `visual/` | AGPL-3.0-or-later |
| Companion | `companion/` | MIT |
| Skill packs | `skills/` | MIT |
| Documentation | `docs/` | CC BY 4.0 |

## Support, security, and contributing

- Bugs and features: [GitHub Issues](https://github.com/cosmincraciun97/stonewright-wp-mcp/issues) using the templates
- Security: [SECURITY.md](SECURITY.md) (private disclosure)
- Support guide: [SUPPORT.md](SUPPORT.md)
- Contributing: [CONTRIBUTING.md](CONTRIBUTING.md)
- Code of conduct: [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
