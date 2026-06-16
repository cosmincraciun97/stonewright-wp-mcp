# Abilities Reference

Stonewright registers WordPress abilities under the `stonewright/` prefix. MCP
clients call the same names with slashes converted to hyphens: ability
`stonewright/context-bootstrap` is MCP tool `stonewright-context-bootstrap`.
The source of truth is `Stonewright\WpMcp\Core\AbilityRegistry`; run
`cd plugin && composer docs:matrix` to regenerate the complete ability truth
matrix after changing the registry.

## Current Categories

| Category | Count | Scope |
|---|---:|---|
| Security | 2 | Confirmation tokens and one-time links. |
| Site | 10 | WordPress diagnostics, active theme, plugin list, revisions, front page settings. |
| Content | 7 | Create, update, duplicate, and read posts/pages. |
| Media | 5 | Upload, batch upload, inspect, optimize, and annotate media. |
| Gutenberg | 10 | Parse, render, serialize, insert, update, remove, and apply blocks. |
| Patterns | 2 | List and create block patterns. |
| Full Site Editing | 10 | Read/write theme.json, templates, template parts, and global styles. |
| Elementor | 30 | Elementor V3 structure editing, compact capability preflight, V4 atomic helpers, Theme Builder adjacent work. |
| Elementor Widget Builder | 98 | Generated per-widget builders and custom widget project helpers. |
| Design | 12 | Validate Design Spec, build specs from manual input, choose renderers, normalize assets, and apply to Gutenberg or Elementor. |
| Knowledge | 5 | Elementor knowledge search, widget descriptions, implementation guidance, and refresh. |
| Memory | 5 | Persistent project memory, user corrections, and learning records. |
| System | 11 | Context bootstrap, tool profiles, workflow preflight, instructions, ability list, and knowledge import/export. |
| WP-CLI | 6 | Companion-backed status, command discovery, guarded command execution, batch execution, and background jobs. |
| Sandbox | 8 | Admin-only generated code/artifact lifecycle. |
| Theme Builder | 5 | Elementor Theme Builder templates and conditions. |
| Menu | 5 | Menu creation, item management, locations, and deletion. |

## Context Requirement

Agents must call MCP tool `stonewright-context-bootstrap` at the start of every task. The
response returns the active system instructions, persistent memory, enabled
skills, relevant knowledge hints, `visual_quality_contract`,
`visual_build_gate`, and a short-lived `stonewright_context_token`. Write
abilities require that token.

Agents can call `stonewright-workflow-preflight` first when speed matters. It
returns the same write token plus active mode, auth guidance, compact Elementor
capabilities, plugin specialization guidance, task-aware recommended tools,
hyphenated MCP tool names, compact `tool_profile` groups, next-best tool
recommendations, compact call examples, and the same visual-build gate in one
low-token response. Use the inlined `fast_path.tool_profile` before making a
separate profile or broad discovery call.

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
ability handlers as the MCP surface. It is not a bypass for write safety and is
not a shell workaround for agents when the MCP tool list did not load.

## WP-CLI

The WP-CLI tools are:

| Ability | Purpose |
|---|---|
| `stonewright/wp-cli-status` (`stonewright-wp-cli-status`) | Checks that WP-CLI is available through the companion and returns `wp cli info --format=json`. |
| `stonewright/wp-cli-discover` (`stonewright-wp-cli-discover`) | Returns compact `wp cli cmd-dump` command paths by default; use `responseMode=full` only when the raw command tree is required. |
| `stonewright/wp-cli-run` (`stonewright-wp-cli-run`) | Runs a guarded WP-CLI command through the companion. It supports writes, but blocks arbitrary PHP and shell-like command groups such as `eval`, `eval-file`, `shell`, and `package`. |
| `stonewright/wp-cli-batch-run` (`stonewright-wp-cli-batch-run`) | Runs repeated guarded WP-CLI commands in one request for faster content, meta, term, media, option, and plugin-command work. |
| `stonewright/wp-cli-job-start` (`stonewright-wp-cli-job-start`) | Starts a guarded WP-CLI command or batch in the companion background queue for long operations. |
| `stonewright/wp-cli-job-status` (`stonewright-wp-cli-job-status`) | Polls a WP-CLI background job and returns the compact result when complete. |

In the Node companion MCP, the same MCP names `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, `stonewright-wp-cli-run`,
`stonewright-wp-cli-batch-run`, `stonewright-wp-cli-job-start`, and
`stonewright-wp-cli-job-status` are direct companion aliases. They do not
require the WordPress-side HTTP bridge explicitly enabled with
`STONEWRIGHT_HTTP_ENABLE=1` plus `PORT`. Use batch run for
repeated commands or Unicode-heavy values so agents do not need large inline
shell scripts. Use background jobs for long guarded WP-CLI work that should not
block one MCP request.
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
- `stonewright/elementor-v3-get-widget-schema` returns compact widget controls
  by default; use `responseMode=full` only when control defaults are required.
- `stonewright/blocks-list-registered` and `stonewright/blocks-get-schema`
  include third-party block inserter metadata such as keywords, examples,
  supports, attributes, and variations when WordPress exposes them.
