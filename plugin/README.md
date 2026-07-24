# Stonewright Plugin

Version: 1.0.0-alpha.84
Requires WordPress: 6.7+
Requires PHP: 8.1+
License: AGPL-3.0-or-later

Stonewright registers WordPress Abilities as MCP tools through the official
`wordpress/mcp-adapter`. It supports Gutenberg, Full Site Editing, Elementor V3,
Elementor V4 atomic experiments, Design Spec rendering, Elementor widget
building, persistent skills/memory, direct PHP runtime execution, and
companion-backed WP-CLI.

For native repeated content, `stonewright/elementor-wire-loop` plans or adds a
Loop Grid/Carousel with live Pro schemas, a validated existing or newly staged
loop-item template, one page write, readback verification, and rollback.

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
The source-install `wp plugin activate stonewright` command is for humans with
WP-CLI already configured. Runtime agents should not recover by shelling out to
`wp ...` or by switching to another PHP adapter. They should use
`stonewright/php-execute` for direct WordPress runtime snippets and the
tokenized `stonewright-wp-cli-*` MCP tools for WP-CLI workflows.
Use `STONEWRIGHT_MCP_TOOL_PROFILE=low-tools` for Antigravity, Gemini API, or
other strict tool-cap clients; keep `essential` for normal fast-path sessions.

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
and `companion_wp_cli_run`, or the direct `stonewright-wp-cli-*` aliases.

### MCP surface (`stonewright_mcp_surface`)

Public tool surface for MCP clients: `bootstrap` | `essential` | `full`.

- **bootstrap** — minimal first-call set (task-start / profile / recovery).
- **essential** — compact day-to-day Elementor/content fast path (default for new installs when set on activation).
- **full** — entire enabled ability registry.

Toggle in **Stonewright → Setup**. Contracts for the public ability list live in
`docs/contracts/public-api-v1.json` (regenerate with `composer contracts:generate`).

### Verify connection

**Stonewright → Setup → Verify connection** runs an authenticated MCP loopback
(initialize → tools/list → task-start). Preflight alone does not prove a live
client session. Companion CLI: `npx @stonewright/companion doctor` checks Node,
credentials, REST index/namespaces, REST auth, and MCP initialize without
printing secrets.

### Prompt library

Searchable outcome-tagged prompts ship in `data/prompts/catalog.json` and appear
on Setup. Agents still start with `stonewright-task-start` (skill refs only) —
do not inline the full library into task-start payloads.

### Persistent Skills And Memory

The plugin stores site skills and memory in WordPress tables. Agents must call
MCP tool `stonewright-task-start` at the start of every task and follow the
returned skills, memory, custom instructions, capability gates, and next tool
path. `stonewright-context-bootstrap` and `stonewright-workflow-preflight`
remain compatibility paths.

If neither `stonewright-task-start` nor compatibility
`stonewright-context-bootstrap` is visible in the MCP tool list, the client did
not load Stonewright yet. Restart or reload the AI client and fix the MCP config
before WordPress work. Local agent skills, repository files, private
client config files, scratch scripts such as `query-mcp.js` or
`run-ability.js`, helper JSON argument files such as `bootstrap-args.json`,
`cli_command.json`, or `get_structure.json`, direct companion shell launch
scripts such as `query-local-stonewright.js`, action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, plugin/companion
source-code spelunking to reverse-engineer tool schemas, hand-rolled JSON-RPC
calls, and
`/wp-json/stonewright/v1/abilities/run` shell calls are not substitutes for live
Stonewright MCP tools.

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
For the canonical compact first pass, call `stonewright-task-start`; it returns
a context token, auth guidance, mode, compact capability summary, task-aware
`recommended_mcp_tools`, and a `call_sequence` with example args.

Admins using authenticated REST directly can call:

```http
POST /wp-json/stonewright/v1/abilities/run
```

with a JSON body containing `name` and `input`. Write abilities still require
the `stonewright_context_token` returned by `stonewright/task-start` or the
compatibility `stonewright/context-bootstrap` path.
