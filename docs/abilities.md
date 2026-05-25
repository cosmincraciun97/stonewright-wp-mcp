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
| Media | 4 | Upload, inspect, optimize, and annotate media. |
| Gutenberg | 10 | Parse, render, serialize, insert, update, remove, and apply blocks. |
| Patterns | 2 | List and create block patterns. |
| Full Site Editing | 10 | Read/write theme.json, templates, template parts, and global styles. |
| Elementor | 28 | Elementor V3 structure editing, V4 atomic helpers, Theme Builder adjacent work. |
| Elementor Widget Builder | 98 | Generated per-widget builders and custom widget project helpers. |
| Design | 12 | Validate Design Spec, build specs from manual/image/brief input, choose renderers, normalize assets, and apply to Gutenberg or Elementor. |
| Knowledge | 5 | Elementor knowledge search, widget descriptions, implementation guidance, and refresh. |
| Memory | 5 | Persistent project memory, user corrections, and learning records. |
| System | 9 | Context bootstrap, instructions, ability list, and knowledge import/export. |
| WP-CLI | 3 | Companion-backed `wp cli info`, `wp cli cmd-dump`, and guarded command execution. |
| Sandbox | 8 | Admin-only generated code/artifact lifecycle. |
| Theme Builder | 5 | Elementor Theme Builder templates and conditions. |
| Menu | 5 | Menu creation, item management, locations, and deletion. |

## Removed Surfaces

Stonewright no longer exposes built-in design-tool ingestion or automated visual
QA tools. There are no `stonewright/qa-*` abilities and no Figma abilities in the
registry. Use a separate design MCP for Figma and use human feedback for visual
review.

## Context Requirement

Agents must call MCP tool `stonewright-context-bootstrap` at the start of every task. The
response returns the active system instructions, persistent memory, enabled
skills, relevant knowledge hints, and a short-lived `stonewright_context_token`.
Write abilities require that token.

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

Agents should prefer native Stonewright abilities for structured writes. Use
WP-CLI when it is faster, better documented by the installed plugin, or useful
for debugging and operational tasks.
