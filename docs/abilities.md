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
| System | 10 | Context bootstrap, workflow preflight, instructions, ability list, and knowledge import/export. |
| WP-CLI | 3 | Companion-backed `wp cli info`, `wp cli cmd-dump`, and guarded command execution. |
| Sandbox | 8 | Admin-only generated code/artifact lifecycle. |
| Theme Builder | 5 | Elementor Theme Builder templates and conditions. |
| Menu | 5 | Menu creation, item management, locations, and deletion. |

## Context Requirement

Agents must call MCP tool `stonewright-context-bootstrap` at the start of every task. The
response returns the active system instructions, persistent memory, enabled
skills, relevant knowledge hints, and a short-lived `stonewright_context_token`.
Write abilities require that token.

Agents can call `stonewright-workflow-preflight` first when speed matters. It
returns the same write token plus active mode, auth guidance, compact Elementor
capabilities, plugin specialization guidance, and first-pass tool choices in one
low-token response.

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
ability handlers as the MCP surface. It is not a bypass for write safety.

## WP-CLI

The WP-CLI tools are:

| Ability | Purpose |
|---|---|
| `stonewright/wp-cli-status` (`stonewright-wp-cli-status`) | Checks that WP-CLI is available through the companion and returns `wp cli info --format=json`. |
| `stonewright/wp-cli-discover` (`stonewright-wp-cli-discover`) | Returns `wp cli cmd-dump` data so agents can discover WordPress, Elementor, Gutenberg, ACF, CPT UI, and other installed command groups. |
| `stonewright/wp-cli-run` (`stonewright-wp-cli-run`) | Runs a guarded WP-CLI command through the companion. It supports writes, but blocks arbitrary PHP and shell-like command groups such as `eval`, `eval-file`, `shell`, and `package`. |

In the Node companion MCP, the same MCP names `stonewright-wp-cli-status`,
`stonewright-wp-cli-discover`, and `stonewright-wp-cli-run` are direct companion
aliases. They do not require the WordPress-side HTTP bridge on port `8765`.
The companion also exposes `stonewright-wp-cli-install`, which downloads the
official `wp-cli.phar` into the Stonewright cache for users who do not have
`wp` on `PATH` or a LocalWP-provided phar.

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
- `stonewright/blocks-list-registered` and `stonewright/blocks-get-schema`
  include third-party block inserter metadata such as keywords, examples,
  supports, attributes, and variations when WordPress exposes them.
