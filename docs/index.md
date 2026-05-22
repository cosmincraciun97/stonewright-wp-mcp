# Stonewright Documentation

Stonewright is a WordPress plugin that exposes 67 site-building primitives as MCP tools. AI agents use those tools to create and edit Gutenberg blocks, Elementor V3 pages, and Full Site Editing templates from a renderer-agnostic design spec without touching the database directly.

## Where to start

- [Getting started with Claude Code](getting-started/claude-code.md) — install the plugin and point Claude Code at it in five minutes.
- [All abilities by category](abilities.md) — reference for all 67 tools.
- [Design Spec reference](design-spec.md) — the JSON format that drives every renderer.
- [Security model](security.md) — how Stonewright enforces permissions, backups, and audit logging.
- [Companion bridge](companion.md) — when you need Figma ingestion or pixel QA.
- [Skill packs](skills.md) — pre-built Claude Code skills for common workflows.

## Sections

| Section | What is in it |
|---|---|
| `getting-started/` | Step-by-step setup for Claude Code and Codex. |
| `builders/` | Guides for Gutenberg, Elementor V3, and FSE workflows. |
| `reference/` | Full parameter docs for abilities, the REST API, and the Design Spec schema. |
| `security/` | Expanded threat model and hardening guide. |
| `concepts/` | Design Spec lifecycle, renderer selection, backup mechanics. |
| `tools/` | How each tool group works internally. |
| `recipes/` | Worked examples: design-to-page, bulk content creation, QA loops. |
| `internal/` | Architecture notes and decision records for contributors. |

## License

Documentation is licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
Plugin source is GPL-2.0-or-later.
Companion and skill packs are MIT.
