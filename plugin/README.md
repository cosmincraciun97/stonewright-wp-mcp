# Stonewright Plugin

Version: 1.0.0-alpha.30
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
4. Enable AI Abilities, generate a WordPress Application Password, and copy the
   MCP client setup note.

Source install:

```bash
cd plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
```

Normal MCP clients launch the versioned companion release tarball with `npx`.
Use the admin **Local WP-CLI bridge (advanced)** controls only when you
deliberately run the optional HTTP bridge for WordPress-side WP-CLI abilities.

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

If `stonewright-context-bootstrap` is not visible in the MCP tool list, the
client did not load Stonewright yet. Restart or reload the AI client and fix the
MCP config before WordPress work. Local agent skills, repository files, private
client config files, and `/wp-json/stonewright/v1/abilities/run` shell calls
are not substitutes for live Stonewright MCP tools.

For visual work, connect external Playwright MCP before the first write:

```bash
claude mcp add playwright -- npx -y @playwright/mcp@latest --caps=testing,vision,devtools
```

Restart the AI client after adding Playwright. If the Playwright/browser tool is
not visible, the agent should stop before visual implementation and ask for the
client restart/setup instead of building blind.

Manual edits in the Stonewright admin Skills/Memory/Instructions pages persist
between sessions because they are stored in WordPress options/custom tables.
They are site-local and are not included in release ZIPs or the npm companion.
Do not publish credentials, private memory, or client-specific instructions in
public docs, commits, issues, or release notes.

### Client Setup In Admin

The Configuration page has a three-step setup flow: enable abilities, generate a
WordPress Application Password for the current user, then copy a client setup
note or a per-client JSON snippet. It also includes copyable real-world prompt
examples for Elementor, content modeling, WooCommerce, and Gutenberg/FSE work.

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
