# Stonewright Plugin

Version: 1.0.0-beta.2
Requires WordPress: 6.7+
Requires PHP: 8.1+
License: AGPL-3.0-or-later

Stonewright registers WordPress Abilities as MCP tools through the official
`wordpress/mcp-adapter`. It supports Gutenberg, Full Site Editing, Elementor V3,
Elementor V4 atomic experiments, Design Spec rendering, Elementor widget
building, persistent skills/memory, direct PHP runtime execution, and
companion-backed WP-CLI. WooCommerce catalog work uses native product objects
and taxonomies through dry-run-first, permission-gated, audited abilities.

Local stdio means the AI client starts the Stonewright companion on the user's
computer and communicates with it through standard input/output. It is required
for Direct mode and local WP-CLI. Remote Streamable HTTP connects straight to
this plugin over HTTPS and needs no local companion.

For native repeated content, `stonewright/elementor-wire-loop` plans or adds a
Loop Grid/Carousel with live Pro schemas, a validated existing or newly staged
loop-item template, one page write, readback verification, and rollback.

## Quick Start

Release install:

1. Download `stonewright-<version>.zip` from GitHub Releases.
2. Upload it in WordPress Admin under **Plugins > Add New > Upload Plugin**.
3. Activate Stonewright and open **Stonewright > Setup**.
4. Enable AI Abilities, choose OAuth (recommended) or Application Password,
   and copy the MCP client setup instructions.

Source install:

```bash
cd plugin
composer install --no-dev
wp plugin activate stonewright

cd ../companion
npm install
npm run build
```

Release archives use a clean production Composer install. The bundled Jetpack
Autoloader manifests are verified against every referenced file before
publication, preventing stale development-only paths from breaking activation
beside WooCommerce or another Jetpack-Autoloader consumer.
CI installs the extracted release archive beside WooCommerce rather than
testing only the source checkout.

See [WooCommerce support](../docs/woocommerce.md) for the catalog and storefront
support matrix.
See [Updating Stonewright](../docs/updates.md) for plugin/companion version
matching, upgrade steps, and persistence guarantees.
The Connect update panel can read the latest trusted release, compare an
optional configured HTTP bridge, and copy a credential-free companion update
prompt. An explicit check bypasses the background release cache. A local stdio
process still has to be replaced and restarted inside its AI client.

Custom PHP/CSS/JS/HTML follows a human approval boundary. The agent runs the
typed dry-run, returns `approval_url`, exact target path, byte counts, and a
short summary, then stops. It must not open the approval page, issue or retrieve
the one-time grant, or apply `custom_code_grant` unless the user explicitly asks
the agent to perform that approval step. This applies to theme files,
Customizer CSS, WPCode, Code Snippets, and equivalent code surfaces.

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

A fresh activation creates the schema with no memory rows, no user-created
skills, and no audit events. Generic built-in skills and native rules are
versioned product assets. Upgrades run schema migrations in place and never
reset existing memory, user skills, audit history, or admin settings. Memory
and skill writes reject high-confidence credential material.

Site memory is for what is true about **this** site. Rules that hold everywhere
belong in the shipped native rule registry instead — see [Native Rules](#native-rules).

### Generalizing Stored Memory

Memory written before the native rule registry existed can name a host, a URL, or
a site-local record id. `stonewright/memory-generalize` reports and — on request —
rewrites those references in bounded batches.

The workflow is deliberately incremental, because a bulk rewrite of memory is not
something to run blind:

1. Call it with a `limit` and no `apply`. It defaults to a **dry run** and returns
   the proposed change for each row it scanned.
2. Review the proposals. Nothing has been written yet.
3. Call again with `apply: true` and the same `limit` to write that batch.
4. Continue with the returned `next_cursor` until `done` is `true`. It reports a
   cursor rather than claiming one page covered everything.

In `production-safe` mode an apply requires a confirmation token issued for the
same `apply`, `limit`, and `cursor` values, so a token cannot be replayed against
a different batch.

### Native Rules

`plugin/data/global-rules.json` holds the rules Stonewright applies to every site.
Each record carries an id, a severity (`hard` / `strong` / `advisory`), a scope
(`all` / `elementor` / `design` / `code`), the rule text, why it exists, and an
`enforcement` block naming either the runtime guard that blocks violations or that
the rule is instruction-only.

`hard` rules are backed by a real runtime guard. `strong` rules are surfaced in
every task payload but cannot be mechanically checked in PHP, so they never claim
a guard — advertising enforcement that does not exist would be worse than
advertising none. Rule text must never name a host, a URL, or a site-local record
id; `GlobalRulesTest` enforces that.

Task start returns the registry digest and the tool that resolves it, not the
bodies. Read the bodies with `stonewright/rules-get`, filtered by `severity` or
`scope`, and pass `knownDigest` to skip them when nothing changed. The same
registry ships with the companion, so Direct mode reports identical rules.

Editing the JSON file is enough to change what task start says: the payload reads
the registry rather than restating rule text in PHP.

### Client Setup In Admin

The Configuration page guides enablement, authentication, client connection,
component updates, and live verification. Private client snippets may contain a
newly generated one-time Application Password; the paste-to-agent prompt always
uses placeholders. The dedicated Prompt Library labels Plugin/Direct support
and includes requirements plus verification.

### Design Studio And Visual Workspace

Design Studio stores design directions — validated design intent, versioned,
with provenance — and lets you create or edit one, mark it ready, activate it,
dry-run a sync against the Elementor Kit, apply that sync with a backup and
readback, inspect recorded quality evidence, and restore an earlier revision.
Capture and sanitized import remain typed ability workflows; this admin screen
does not pretend to expose controls it does not have.

Visual Workspace opens a post under
`admin.php?page=stonewright-visual-workspace&post_id=<id>`. It requires
`edit_posts`, plus edit rights on the target post. **Connect editor** opens the
real same-origin Elementor or block editor window. The workspace resolves the
adapter against that runtime — Elementor V4 atomic, then Elementor V3, then
Gutenberg — and enforces read → preview → confirm → apply → verify.

For Figma-derived work, call `stonewright-design-direction-brief` once and
reuse its compact tokens and translated density/variance/motion guidance across
section batches. Normalize official Figma MCP or Figma Console MCP output into
vendor-neutral DesignEvidence; Stonewright embeds neither client.

Neither page claims that a page looks correct. Design directions supply intent,
quality reports supply measurements with their own coverage, and a change
applied with no evidence behind it is reported as unverified. The browser bundle
is generated into `assets/visual/` at packaging time; when it is absent the page
says so and prints the build command. See
[docs/visual.md](../docs/visual.md) and
[docs/figma-to-elementor-workflow.md](../docs/figma-to-elementor-workflow.md).

The admin UI ships a single light theme. There is no dark mode and no theme
toggle: maintaining two token sets meant every contrast fix had to be made twice,
and one of the two was always the stale one.
The canonical tokens, component rules, responsive requirements, and complete
surface audit are maintained in [`../DESIGN.md`](../DESIGN.md).

## Code Payload Handling

Abilities that accept code — `stonewright/php-execute`,
`stonewright/sandbox-write`, `stonewright/theme-custom-css`,
`stonewright/theme-file-patch` — pass payloads through **unchanged** by default.

Some MCP clients deliver code with layout escaped into literal `\n` sequences. To
recover from that, pass `decode_escaped_layout: true`. It is opt-in and
deliberately conservative: decoding happens only outside PHP strings and comments,
so a `"\n"` that is part of the program's own data is left alone. When the decoder
cannot establish that a sequence is layout rather than content, it does nothing.

PHP payloads are validated with the PHP parser, not a regex heuristic, so valid
code is not rejected for looking unusual. Confirmation tokens bind to the
canonical form of the arguments, so a reformatted payload cannot reuse a token
issued for different content.

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

Plugin mode also supports browser-based OAuth at:

```text
https://your-site.example.com/wp-json/mcp/stonewright-oauth
```

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
