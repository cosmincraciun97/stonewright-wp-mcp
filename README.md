<p align="center">
  <img src="assets/brand/stonewright-logo-512.png" alt="Stonewright" width="160" height="160" />
</p>

<h1 align="center">Stonewright</h1>

<p align="center">
  <strong>AI agents that design and build Elementor pages safely</strong><br />
  Plus guarded Gutenberg, full-site REST, WP-CLI, and self-improving skills — with or without a plugin.
</p>

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases"><img alt="Latest release" src="https://img.shields.io/github/v/release/cosmincraciun97/stonewright-wp-mcp?include_prereleases&label=release" /></a>
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/actions/workflows/ci.yml"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/cosmincraciun97/stonewright-wp-mcp/ci.yml?branch=main&label=CI" /></a>
  <img alt="plugin license" src="https://img.shields.io/badge/plugin-AGPL--3.0--or--later-green" />
  <img alt="companion license" src="https://img.shields.io/badge/companion-MIT-blue" />
  <img alt="php" src="https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4" />
  <img alt="wordpress" src="https://img.shields.io/badge/WordPress-%3E%3D6.7-21759b" />
</p>

Stonewright is a WordPress MCP stack for AI coding agents. **Elementor** is a first-class surface in Plugin mode (typed engines, DesignSpec, kit globals), and **WooCommerce** has a native, guarded catalog surface. **Direct mode** works against core REST with an Application Password without installing the plugin: companion-local skills/memory, read-only WooCommerce access, and Elementor document edits **without opening the editor** via WP-CLI (local) or REST meta when registered (remote). Full batch-mutate and WooCommerce catalog-write engines remain plugin-only.

> “Safe” here is a **design goal**: operating modes, permissions, confirmations, backups, validation, readback, and audit logging make agent-driven changes more recoverable. It is not an absolute security guarantee. Use staging, review changes, and keep normal WordPress backups.

<p align="center">
  <a href="https://github.com/cosmincraciun97/stonewright-wp-mcp/releases">Download latest release</a>
  ·
  <a href="docs/installation.md">Installation</a>
  ·
  <a href="docs/install-prompts.md">AI install prompts</a>
  ·
  <a href="SECURITY.md">Security</a>
  ·
  <a href="docs/ability-truth-matrix.md">Capability matrix</a>
  ·
  <a href="docs/direct-mode-e2e.md">Direct mode</a>
  ·
  <a href="docs/woocommerce.md">WooCommerce</a>
  ·
  <a href="CONTRIBUTING.md">Contributing</a>
</p>

<!-- Maintainer: add the Stonewright workflow demo here. Do not remove this comment until the asset is available. -->

## Capabilities

Counts are derived from `docs/ability-truth-matrix.md` (plugin) and `DIRECT_TOOL_NAMES` (Direct). Do not hand-edit totals without regenerating the matrix.

### Plugin mode — **346** abilities

Counts below are grouped by the `includes/Abilities/` subdirectory each ability
lives in, and sum to the total. Regenerate with `composer docs:matrix`.

| Category | Count | Highlights |
|---|---:|---|
| Elementor widgets (compat) | 98 | Generated per-widget builders |
| Elementor V3 | 29 | Structure edit, batch-mutate, kit globals, build-from-spec, transactions |
| Elementor V4 | 14 | Atomic nodes, variables, classes (experimental) |
| Design | 25 | DesignSpec validate/render, native plan, intent, versioned Design Directions, Elementor kit capture, guarded kit sync, rendered quality checks |
| Site | 17 | Snapshot, inventory, health, pulse, plugins, theme, shortcodes |
| Gutenberg + FSE + patterns | 23 | Blocks, theme.json, templates, global styles |
| Content + media | 20 | Pages/posts, bulk upsert, content model, upload, stock |
| ACF + SEO | 8 | Field groups/values, multi-plugin SEO |
| Comments / users / widgets / settings / themes / theme builder / plugins / revisions | 35 | REST-parity admin ops |
| WP-CLI | 6 | Status, discover, run, batch, jobs |
| Memory + skills + expertise + knowledge | 20 | Learning, memory generalization, skills, expertise packs |
| Security + sandbox | 10 | Tokens, one-time links, sandbox lifecycle |
| System | 11 | Task start, native rules, tool profiles, ability list |
| Menus, blueprints, brand kits, runtime, search, WooCommerce | 30 | Native Woo catalog CRUD/audit; see full [matrix](docs/ability-truth-matrix.md) |

### Direct mode — **100** tools (pluginless)

| Area | Tools (group) | Notes |
|---|---|---|
| Content & Gutenberg | list/get/create/update + compose + **validate** | Round-trip heuristics after writes |
| Elementor (local WP-CLI) | **status / data-get / data-update** | Mandatory file backup; CSS flush best-effort |
| Media, menus, taxonomy, templates, global styles | REST | Core endpoints |
| Comments, users, app passwords, widgets | REST | Write-gated |
| Plugins, themes, settings, health | REST | Destructive confirms |
| WooCommerce | products/orders/sales | Read-only; catalog writes require Plugin mode |
| ACF / SEO | fields get/update, seo-head | REST when plugins expose them |
| Self-improvement | skill-*, memory, learning, **task-start**, **agents-md-sync** | `~/.stonewright/` storage |
| Native rules | **rules-get** | Same shipped registry as Plugin mode; cache by digest |
| WP-CLI | status/discover/run/batch/jobs | Tokenized `execFile` argv |
| Safety | write gating, confirm, audit JSONL, backups | Task-start required before writes (default) |

## What you can do with Stonewright

- Inspect an existing WordPress site before changing it
- Create or update Gutenberg content and block-theme structures (Plugin mode; partial Direct mode for core posts/pages)
- Build and modify Elementor documents through validated DesignSpec workflows (**Plugin mode**)
- Wire native Elementor Pro Loop Grid/Carousel widgets transactionally from an
  existing loop-item template or a validated template spec (**Plugin mode**)
- Manage content, media, navigation, and selected site settings
- Manage WooCommerce products, variations, catalog terms, global attributes,
  and shipping classes through dry-run-first native abilities
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
- **Controlled schema learning** — only verified repairs become active, scoped
  to matching Elementor runtimes
- **Validation and readback** on DesignSpec and major write paths
- **Audit logging and change history** (Plugin mode)
- **Backups and restore workflows** for supported post mutations
- **Tool-surface and token-budget management** (profiles, priorities, client caps)
- **Native WooCommerce catalog workflows** with dry-run, permission,
  production confirmation, audit, and readback gates
- **Plugin-less Direct mode** for core REST and read-only WooCommerce
- **Explicit operating modes** (`development`, `staging`, `production-safe`) and confirmation tokens for destructive work

## Choose your setup

Capabilities differ between modes. Prefer Plugin mode when you need Elementor, blueprints, memory, skills, audit, or full DesignSpec engines.

### Plugin mode — recommended for full capabilities

Install the Stonewright plugin for advanced Elementor workflows, blueprints and brand kits, memory and skills, audit/restore, DesignSpec validation, `php-execute`, and the broader ability surface.

### Direct mode — plugin-less core REST + local Elementor data

The companion authenticates with a WordPress Application Password and exposes **100** tools without installing Stonewright. Elementor documents can be edited **without the Elementor editor** via `stonewright-elementor-data-get` / `data-update` (local WP-CLI preferred; remote Direct falls back to core REST meta when `_elementor_data` is registered, with a file backup under `~/.stonewright/backups/`). This path has no Elementor schema validation — use Plugin mode `elementor-v3-batch-mutate` for production engines. DesignSpec, php-execute, and site-hosted skills remain plugin-only; Direct keeps private local memory and user skills under `~/.stonewright/`. See [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md) and [docs/install-prompts.md](docs/install-prompts.md).

## Quick Start

**Plugin mode (about five steps):**

1. Download the latest `stonewright-*.zip` from [GitHub Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases) (includes prereleases).
2. In WordPress: **Plugins → Add New → Upload Plugin** → activate **Stonewright**.
3. Open **Stonewright → Setup**, enable abilities, and choose OAuth
   (recommended) or create an Application Password.
4. Follow the client-specific OAuth instructions shown in Setup. Use the
   companion configuration below only for Application Password or local
   WP-CLI workflows.
5. In Setup, run **Verify connection** (live MCP loopback). Optionally run `npx @stonewright/companion doctor` from a shell.
6. Call `stonewright-task-start` (or `stonewright-context-bootstrap` as a compatibility path) before WordPress work.

MCP surface modes (`bootstrap` / `essential` / `full`) control how many plugin abilities appear to clients. Public ability and Direct-tool contracts live under [docs/contracts/](docs/contracts/). Elementor multi-step edits use the [transaction envelope](docs/transactions.md).

<details>
<summary>MCP client config (Plugin mode companion)</summary>

Use the latest companion package URL from [Releases](https://github.com/cosmincraciun97/stonewright-wp-mcp/releases) (do not hardcode a stale version):

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

Replace `VERSION` with the exact release version without the leading `v`, as
shown on the GitHub Releases page. Site MCP endpoint when using the WordPress
MCP adapter directly:

```text
https://your-site.example.com/wp-json/mcp/stonewright
```

HTTP local sites are supported; Setup treats plain HTTP as informational, not a hard failure.

</details>

<details>
<summary>Direct mode (plugin-less)</summary>

1. Create a WordPress Application Password for an admin user. On plain HTTP local sites, set `WP_ENVIRONMENT_TYPE` to `local` in `wp-config.php` if Application Passwords require it.
2. Run the companion `init` command from the latest release package (or configure env vars) and paste the MCP JSON into your client:

   ```bash
   npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright-companion init
   ```
3. First call: `stonewright-task-start`. Use `stonewright-site-discover` for
   endpoint and capability details, and `stonewright-setup-profile` for setup
   diagnostics.
4. Read [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md) for the capability matrix and smoke script.

Example env for Direct mode:

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

Replace `VERSION` with the latest release version (the companion is distributed through GitHub Releases, not the npm registry).

</details>

Fresh installs start with no user memory, user-created skills, or audit events.
Generic built-in skills and native rules are product assets. Updates preserve
existing plugin and Direct state. See [Updating Stonewright](docs/updates.md)
for the plugin/companion matrix and exact steps.

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

### Native rules

Stonewright ships a registry of operating rules that apply to **every** site
rather than to one project's memory. Each record states the rule, why it exists,
its severity, its scope, and how it is enforced:

| Severity | Meaning |
|---|---|
| `hard` | A runtime guard makes the violation fail. `enforcement.guard` names that guard. |
| `strong` | Surfaced in every task payload. Deviation must be justified. Not mechanically checkable, so these never claim a runtime guard. |
| `advisory` | Surfaced on matching tasks only. |

The distinction is deliberate: advertising enforcement that does not exist is
worse than advertising none. Rules that PHP cannot mechanically verify say so.

`stonewright-task-start` returns only the registry **digest** and the name of the
tool that resolves it, because rule bodies would consume most of a compact task
payload. Fetch the bodies once with `stonewright-rules-get`, cache them by digest,
and refetch only when task start reports a different digest. Filter by `severity`
or `scope`; a scoped request still includes the globally scoped rules, since those
apply everywhere. Pass `knownDigest` to get `unchanged: true` with the bodies
omitted.

Both modes serve the same registry: Plugin mode from `plugin/data/global-rules.json`,
Direct mode from the copy shipped with the companion. `stonewright-rules-get`
exists in both, and in Direct mode it is on the bootstrap surface so a cold client
can resolve the digest task start just handed it.

### Cheaper reads

Two optional inputs cut payload without losing precision:

- **`stonewright_fields`** — available on every ability. Give it dot-separated
  response paths, as a list or a comma-separated string
  (`"meta.title, outline.id"`), and the response carries only those branches.
  Unknown paths are ignored rather than raising an error, the `ok` envelope is
  never removed, and errors come back unprojected so you can still see why a call
  failed.
- **`knownHash`** on `stonewright-elementor-v3-get-page-structure` — pass the
  `hash` a previous read returned. If the document has not moved, the answer is
  `{ post_id, active, hash, unchanged: true }` and no outline or tree is built.
  The hash is taken from the decoded tree, so a re-save that only reorders JSON
  keys does not read as a change, and both response modes report the same value.

## Architecture

```mermaid
flowchart LR
  Client[AI / MCP client]
  Companion[Stonewright Companion]
  CompFilter[Companion profile filter]
  Plugin[Stonewright plugin]
  Surface[Surface gate: bootstrap / essential / full]
  Session[Per-session tool profile]
  Rev[surface_revision -> tools/list_changed]
  REST[WordPress REST API]
  Rules[Native rules + digest cache]
  WP[WordPress core]
  Guten[Gutenberg / FSE]
  Elem[Elementor]
  Integrity[Elementor integrity gate: double-encode / size-collapse / widgetType-remap / delta-scoped validate]
  Content[Content / media / menus]
  Mem[Memory / skills]
  Users[Users / comments / widgets]
  WC[WooCommerce native catalog]
  Gates[Backup / validation / readback / audit]

  Client --> Companion
  Companion --> CompFilter
  Companion --> Rules
  CompFilter -->|Plugin mode| Plugin
  CompFilter -->|Direct mode| REST
  Companion --> Skills[Local skills / memory]
  Plugin --> Surface
  Surface --> Session
  Session --> Rev
  Rev -. re-list .-> Client
  Plugin --> Gates
  Plugin --> Rules
  Plugin --> Guten
  Plugin --> Elem
  Elem --> Integrity
  Plugin --> Content
  Plugin --> Mem
  Plugin --> Users
  Plugin --> WC
  REST --> Users
  REST --> WP
  WP --> Content
  Plugin --> WP
```

Tool visibility is filtered twice before a client sees it: the plugin’s **surface gate** (`bootstrap` / `essential` / `full`) and optional **per-session tool profile** decide which abilities the MCP endpoint exposes, then the **companion profile filter** narrows that set again for the client. A monotonic `surface_revision` on every gateway response drives `tools/list_changed` so clients re-list when the surface changes.

Direct mode has a **smaller** capability surface: core REST, read-only WooCommerce, local Elementor data, and skills/memory across **100 tools**. Plugin mode exposes **346 abilities**. Direct mode skips the plugin’s typed schema validator; Elementor writes in both modes pass an integrity gate that blocks double-encoding, mass size-collapse, and `widgetType` remaps. WooCommerce catalog writes require Plugin mode; see [WooCommerce support](docs/woocommerce.md).

See [docs/install-prompts.md](docs/install-prompts.md) for copy-paste AI client setup (plugin and Direct).

## Supported workflows and clients

Stonewright speaks standard MCP (stdio via the companion, and HTTP MCP when the WordPress MCP adapter is active). Configuration samples in this repository follow the common MCP server JSON shape used by several clients.

| Area | Status | Notes |
|---|---|---|
| Companion stdio MCP | Documented | Primary install path in docs |
| WordPress MCP endpoint `/wp-json/mcp/stonewright` | Documented | Plugin + MCP adapter |
| Direct mode core REST | Documented + smoke script | [docs/direct-mode-e2e.md](docs/direct-mode-e2e.md) |
| Specific desktop/CLI AI clients | Not uniformly verified | Use generic MCP config; do not assume a client is verified without a dedicated setup doc |

## Admin interface

Plugin mode admin pages include Setup, Dashboard (Site Pulse), Abilities,
Blueprints, Design Studio, Visual Workspace, Skills, Memory, Sandbox, and Audit
Log. The admin ships one supported light theme; there is no theme toggle.

Design Studio holds design directions: validated site-wide design intent with
provenance and revisions. Visual Workspace opens the real Elementor or block
editor in a same-origin companion window, resolves the live adapter there, and
walks read → preview → confirm → apply → verify with the active direction on
screen. Neither page certifies that a page looks right; a change applied without
evidence is reported as unverified. See [docs/visual.md](docs/visual.md) and
[docs/figma-to-elementor-workflow.md](docs/figma-to-elementor-workflow.md).

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

Stonewright ships a **public beta**. APIs, tools, configuration, and behavior
may still change before 1.0 stable. Test on staging or local environments first.
Keep site backups independent of Stonewright. Report security issues privately
per [SECURITY.md](SECURITY.md).

This project is **not** marketed as production-ready in the sense of a frozen stable API. Use production-safe mode and review when operating on live sites.

## Documentation

- [Installation](docs/installation.md)
- [Updating Stonewright](docs/updates.md)
- [Direct mode capability matrix](docs/direct-mode-e2e.md)
- [WooCommerce support and safety](docs/woocommerce.md)
- [Companion](docs/companion.md)
- [Security](docs/security.md) · [SECURITY.md](SECURITY.md)
- [Ability truth matrix](docs/ability-truth-matrix.md)
- [Licensing](docs/licensing.md)
- [Upstream code reuse ledger](docs/upstream-code-reuse.md)
- [Release notes](docs/releases/)

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
