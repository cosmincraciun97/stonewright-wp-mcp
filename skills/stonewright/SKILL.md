---
name: stonewright
description: >
  Stonewright WordPress MCP entrypoint. Use for WordPress, Elementor,
  Gutenberg, FSE, WooCommerce, ACF/CPT/content-model, sandbox, memory,
  skills, and agent workflow tasks.
---

# Stonewright

Use this as the first Stonewright skill when the exact workflow is unclear.
It routes the agent to the right specialized skill and MCP tools.

## First Calls

1. Call `stonewright-context-bootstrap` with the task, surface, and intent.
2. Call `stonewright-workflow-preflight` when planning implementation work.
3. If authentication or MCP visibility fails, check companion credentials
   before falling back to WP-CLI.

## Route

- Design/image/Figma-to-WordPress: use `design-to-wordpress`.
- Elementor V3 pages/widgets/templates: use `elementor-v3-builder`.
- Gutenberg/FSE/theme.json/templates: use `gutenberg-fse-builder`.
- ACF, CPT UI, Meta Box, Pods, taxonomies: use `content-model-integrations`.
- WooCommerce catalog work: use `woocommerce-catalog`.
- Stonewright plugin development: use `wp-plugin-dev`.
- Review/polish generated pages: use `stonewright-review`.

## Rules

- Use native Stonewright abilities before ad hoc code.
- Use guarded `stonewright-wp-cli-run` only with argv tokens.
- For repeated WP-CLI writes or non-ASCII values, use
  `stonewright-wp-cli-batch-run` with JSON argv arrays instead of large inline
  PowerShell/Node scripts.
- Never use `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, or
  `--require`.
- For visual work, implement one or two sections at a time and verify desktop,
  tablet, and mobile before continuing.
- For Elementor widgets, inspect schema or capability summary before writing.
