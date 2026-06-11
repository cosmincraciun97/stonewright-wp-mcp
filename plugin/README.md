# Stonewright Plugin

Version: 1.0.0-alpha.12
Requires WordPress: 6.7+
Requires PHP: 8.1+
License: GPL-2.0-or-later

Stonewright registers WordPress Abilities as MCP tools through the official
`wordpress/mcp-adapter`. It supports Gutenberg, Full Site Editing, Elementor V3,
Elementor V4 atomic experiments, Design Spec rendering, Elementor widget
building, persistent skills/memory, and companion-backed WP-CLI.

## Quick Start

Release install:

1. Download `stonewright-<version>.zip` from GitHub Releases.
2. Upload it in WordPress Admin under **Plugins > Add New > Upload Plugin**.
3. Activate Stonewright and open **Stonewright > Configuration**.
4. Enable AI Abilities and create a WordPress Application Password for MCP auth.

Source install:

```bash
cd plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
set PORT=8765
set COMPANION_BEARER_TOKEN=change-this-long-random-token
set COMPANION_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1
node dist/index.js
```

Set the companion URL:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
wp option update stonewright_companion_token change-this-long-random-token
```

## Local Development

```bash
cd plugin
composer install
composer test
composer phpstan
composer phpcs
composer docs:matrix

cd ../companion
npm install
npm run build
npm test
```

## Configuration

### `stonewright_mode`

| Value | Behavior |
|---|---|
| `development` | All enabled abilities are available. |
| `staging` | All enabled abilities are available with extra operational caution. |
| `production-safe` | Destructive abilities require confirmation tokens. |

### `stonewright_companion_url`

Internal URL of the companion Node server. Required for WP-CLI abilities:

- `stonewright/wp-cli-status`
- `stonewright/wp-cli-discover`
- `stonewright/wp-cli-run`

When the companion HTTP bridge is not running, use direct MCP tools exposed by
the companion instead: `companion_wp_cli_status`, `companion_wp_cli_discover`,
and `companion_wp_cli_run`.

### Persistent Skills And Memory

The plugin stores site skills and memory in WordPress tables. Agents must call
MCP tool `stonewright-context-bootstrap` at the start of every task and follow returned
skills, memory, custom instructions, and required followups.

For visual work, connect external Playwright MCP before the first write:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart the AI client after adding Playwright. If the Playwright/browser tool is
not visible, the agent should stop before visual implementation and ask for the
client restart/setup instead of building blind.

Manual edits in the Stonewright admin Skills/Memory/Instructions pages persist
between sessions because they are stored in WordPress options/custom tables.

### Prompting Guide In Admin

The Configuration page includes a copyable bootstrap prompt and a copyable
prompting guide. Use them when onboarding a new AI client or explaining how a
site owner should brief an agent. The guide asks for the target surface,
allowed plugins, safety mode, design references, asset rules, and desktop,
tablet, and mobile acceptance checks.

## Adding An Ability

1. Create a class extending `AbilityKernel` in `includes/Abilities/<Category>/`.
2. Register the class in `AbilityRegistry::list()`.
3. Add success and error fixtures in `tests/fixtures/abilities/`.
4. Add PHPUnit coverage.
5. Run `composer test && composer phpstan && composer phpcs`.

Write abilities must use real permission callbacks, snapshots where required,
confirmation tokens in production-safe mode, and `Validator::validate()` before
rendering Design Specs.

## Ability Groups

- Content
- Design
- Elementor V3
- Elementor V4
- Elementor Widget Builder
- Full Site Editing
- Gutenberg
- Knowledge
- Media
- Memory
- Menu
- Patterns
- Sandbox
- Security
- Site
- Skills
- System
- Theme Builder
- WP-CLI

See [docs/ability-truth-matrix.md](../docs/ability-truth-matrix.md) for the full
reference.

## MCP Endpoint

```
https://your-site.example.com/wp-json/mcp/stonewright
```

Authentication uses WordPress Application Passwords.

MCP tool names are hyphenated by the WordPress MCP Adapter. Example:
`stonewright/context-bootstrap` is called as `stonewright-context-bootstrap`.
For a fast first pass, call `stonewright-workflow-preflight`; it returns a
context token, auth guidance, mode, compact capability summary,
task-aware `recommended_mcp_tools`, and a `call_sequence` with example args.

Admins using authenticated REST directly can call:

```http
POST /wp-json/stonewright/v1/abilities/run
```

with a JSON body containing `name` and `input`. Write abilities still require
the `stonewright_context_token` returned by `stonewright/context-bootstrap`.
