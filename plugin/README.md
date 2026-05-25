# Stonewright Plugin

Version: 1.0.0-alpha.2
Requires WordPress: 6.7+
Requires PHP: 8.1+
License: GPL-2.0-or-later

Stonewright registers WordPress Abilities as MCP tools through the official
`wordpress/mcp-adapter`. It supports Gutenberg, Full Site Editing, Elementor V3,
Elementor V4 atomic experiments, Design Spec rendering, Elementor widget
building, persistent skills/memory, and companion-backed WP-CLI.

Stonewright does not include design-tool ingestion or automated visual QA.

## Quick Start

```bash
cd plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
node dist/index.js
```

Set the companion URL:

```bash
wp option update stonewright_companion_url http://127.0.0.1:8765
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

### Persistent Skills And Memory

The plugin stores site skills and memory in WordPress tables. Agents must call
MCP tool `stonewright-context-bootstrap` at the start of every task and follow returned
skills, memory, custom instructions, and required followups.

Manual edits in the Stonewright admin Skills/Memory/Instructions pages persist
between sessions because they are stored in WordPress options/custom tables.

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
