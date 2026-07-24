# Abilities Reference

> Category counts are generated from `docs/ability-truth-matrix.md` (**318** abilities). Categories include Comments, Users, Widgets, Settings, Themes, Plugins manage, Revisions, Search, WooCommerce, ACF, SEO, Content Model.
Stonewright registers WordPress abilities under the `stonewright/` prefix. MCP
clients call the same names with slashes converted to hyphens: ability
`stonewright/task-start` is MCP tool `stonewright-task-start`.
The source of truth is `Stonewright\WpMcp\Core\AbilityRegistry`; run
`cd plugin && composer docs:matrix` to regenerate the complete ability truth
matrix after changing the registry.

## Current Categories

| Category | Count | Scope |
|---|---:|---|
| Security | 2 | Confirmation tokens and one-time links. |
| Site | 11 | WordPress diagnostics, active theme, plugin list, shortcodes, revisions, front page settings. |
| Content | 8 | Create, update, duplicate, bulk upsert, and read posts/pages. |
| Content Model | 4 | CPT/ACF Loop Grid flow, CPT register/list, taxonomy register. |
| ACF | 5 | Field groups list/get/save and post field values get/update. |
| SEO | 3 | Multi-plugin SEO status and meta get/update (Yoast, Rank Math, AIOSEO, SEOPress). |
| Media | 6 | Upload, batch upload, inspect, optimize, list, and annotate media. |
| Gutenberg | 10 | Parse, render, serialize, insert, update, remove, and apply blocks. |
| Patterns | 2 | List and create block patterns. |
| Full Site Editing | 10 | Read/write theme.json, templates, template parts, and global styles. |
| Elementor V3 | 22 | Elementor V3 structure editing, document health, page specs, kit globals, capability preflight, and batch mutation. |
| Elementor V4 (Experimental) | 12 | Atomic nodes, variables, classes, and experimental V4 rendering. |
| Elementor Widget Builder | 98 | Deprecated generated per-widget compatibility builders plus custom widget project helpers. |
| Design | 13 | Validate Design Spec, build specs from manual input, choose renderers, normalize assets, intent routing, and apply to Gutenberg or Elementor. |
| Knowledge | 5 | Elementor knowledge search, widget descriptions, implementation guidance, and refresh. |
| Memory | 5 | Persistent project memory, user corrections, and learning records. |
| System | 8 | Context bootstrap, tool profiles, workflow preflight, instructions, ability list, and knowledge import/export. |
| Skills | 3 | Agent skill listing, reads, and saves. |
| Runtime | 1 | Direct PHP snippets inside the loaded WordPress runtime. |
| WP-CLI | 6 | Companion-backed status, command discovery, tokenized command execution, batch execution, and background jobs. |
| Sandbox | 8 | Admin-only generated code/artifact lifecycle. |
| Theme Builder | 6 | Elementor Theme Builder templates, conditions, and apply-template orchestration. |
| Menu | 5 | Menu creation, item management, locations, and deletion. |

## Context Requirement

Agents must call MCP tool `stonewright-task-start` at the start of every task. It
returns the same write token plus active mode, auth guidance, compact Elementor
capabilities, plugin specialization guidance, task-aware recommended tools,
hyphenated MCP tool names, compact `tool_profile` groups, next-best tool
recommendations, compact call examples, and the same visual-build gate in one
low-token response. Use the inlined `fast_path.tool_profile` before making a
separate profile or broad discovery call.

`stonewright-context-bootstrap` and `stonewright-workflow-preflight` remain
compatibility tools. `stonewright-context-bootstrap` is also the tool-list
sentinel: if it is missing, the Stonewright MCP server did not load.

Use `stonewright-tool-profile` when the MCP client has a strict tool limit or
the task needs to switch or verify a low-token execution profile. It returns
compact profiles such as `low-tools`, `elementor-design`, `content-model`,
`gutenberg`, and `wp-cli` with the hyphenated MCP tool names agents should keep
using before broad discovery. It also returns `tool_groups`,
`next_best_tools`, and `discovery_policy` so agents can pick the next Elementor,
content/media, Gutenberg/FSE, WP-CLI, or site-admin tool without reading the
full ability matrix. Use `low-tools` for Antigravity, Gemini API, or other
strict tool-cap clients before switching to a specialist profile.

For pixel-matching tasks, `visual_build_gate` is a blocking signoff checklist.
Agents must prepare a reference token table, existing media audit, and section
implementation plan before the first write. Before completion they must provide
desktop, tablet, and mobile screenshot deltas plus logged-out public viewport
checks.

Reference screenshots are the layout authority. Design-tool structure is useful
for tokens, text, styles, assets, and node hints, but agents should not copy a
broken layer tree into WordPress when the visible design needs a cleaner native
structure. For long designs, agents should capture multiple section reference
screenshots and compare section-by-section before final full-page signoff.

`stonewright/skills-list` can filter skills by exposure mode: `all`, `agentic`
for automatic matching, or `prompt` for explicit prompt/command entries.

Authenticated admins can also execute registered Stonewright abilities through
the Stonewright REST runner when a client cannot call the MCP ability transport
directly:

```http
POST /wp-json/stonewright/v1/abilities/run
Content-Type: application/json

{
  "name": "stonewright/ping",
  "input": {}
}
```

The runner uses the same registry, permission callbacks, master toggle,
disabled-ability checks, UTF-8 sanitization, context-token gate, audit flow, and
ability handlers as the MCP surface. It is not a shell workaround for agents
when the MCP tool list did not load. Agents
must not inspect private AI-client config files, create `query-mcp.js` or
`run-ability.js`, create helper JSON argument files such as
`bootstrap-args.json`, `cli_command.json`, or `get_structure.json`, launch the
companion through `query-local-stonewright.js`, create action scripts such as
`run-loop-mutate.js` or `run-bootstrap-and-mutate.js`, inspect plugin/companion
source to reverse-engineer tool schemas, or hand-roll JSON-RPC to reach this
runner when `stonewright-context-bootstrap` is missing.

## Runtime

Use `stonewright/php-execute` (`stonewright-php-execute`) for short PHP snippets
inside the loaded WordPress runtime. It has access to WordPress functions,
loaded plugins, `$wpdb`, and normal PHP runtime APIs. Prefer typed Stonewright
abilities for common workflows, and use PHP execute when direct plugin API or
database inspection is the shorter correct path.

## WP-CLI

The WP-CLI tools are:

| Ability | Purpose |
|---|---|
| `stonewright/wp-cli-status` (`stonewright-wp-cli-status`) | Checks that WP-CLI is available through the companion and returns `wp cli info --format=json`. |
| `stonewright/wp-cli-discover` (`stonewright-wp-cli-discover`) | Returns compact `wp cli cmd-dump` command paths by default; use `responseMode=full` only when the raw command tree is required. |
| `stonewright/wp-cli-run` (`stonewright-wp-cli-run`) | Runs a tokenized WP-CLI command through the companion. It supports writes; use `stonewright/php-execute` for PHP snippets instead of WP-CLI eval or shell entry points. |
| `stonewright/wp-cli-batch-run` (`stonewright-wp-cli-batch-run`) | Runs repeated tokenized WP-CLI commands in one request for faster content, meta, term, media, option, and plugin-command work. |
| `stonewright/wp-cli-job-start` (`stonewright-wp-cli-job-start`) | Starts a tokenized WP-CLI command or batch in the companion background queue for long operations. |
| `stonewright/wp-cli-job-status` (`stonewright-wp-cli-job-status`) | Polls a WP-CLI background job and returns the compact result when complete. |

In the Node companion MCP, the same MCP names `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, `stonewright-wp-cli-run`,
`stonewright-wp-cli-batch-run`, `stonewright-wp-cli-job-start`, and
`stonewright-wp-cli-job-status` are direct companion aliases. They do not
require the WordPress-side HTTP bridge explicitly enabled with
`STONEWRIGHT_HTTP_ENABLE=1` plus `PORT`. Use batch run for
repeated commands or Unicode-heavy values so agents do not need large inline
shell scripts. Use background jobs for long WP-CLI work that should not block
one MCP request.
The companion also exposes `stonewright-wp-cli-install`, which downloads the
official `wp-cli.phar` into the Stonewright cache for users who do not have
`wp` on `PATH` or a LocalWP-provided phar.
Agents should not recover by running `wp cli info`, `wp plugin activate`,
`wp option update`, or other `wp` commands in a normal shell.

Agents should prefer native Stonewright abilities for structured writes. Use
WP-CLI when it is faster, better documented by the installed plugin, or useful
for debugging and operational tasks.

## Design And Elementor Contracts

- `stonewright/widget-intent-resolve` accepts `forbid_html_widget`. When true,
  any resolution to an Elementor HTML/raw-html widget returns
  `stonewright_html_widget_forbidden`; callers must choose native Elementor
  widgets and containers instead.
- `stonewright/elementor-v3-status` and `stonewright/elementor-v4-status`
  report Elementor version, Pro availability, active widget types, unsupported
  required native widgets, and V4 atomic support state.
- `stonewright/elementor-v3-get-kit-globals` returns a compact active-kit color
  and typography snapshot so agents can compare Figma tokens before updating
  Elementor kit globals or writing section specs.
- `stonewright/elementor-v3-get-page-structure` returns a compact Elementor
  outline by default; use `responseMode=full` only when the raw element tree is
  required.
- `stonewright/elementor-schema` lists/searches live widgets and returns compact
  widget controls with `mode=summary`; use `mode=control` for one complete
  control or paginated `mode=full` only when required.
- `stonewright/blocks-list-registered` and `stonewright/blocks-get-schema`
  include third-party block inserter metadata such as keywords, examples,
  supports, attributes, and variations when WordPress exposes them.
