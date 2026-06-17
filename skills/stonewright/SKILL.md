---
name: stonewright
description: >
  Stonewright WordPress MCP entrypoint. Use for WordPress, Elementor,
  Gutenberg, FSE, WooCommerce, ACF/CPT/content-model, PHP runtime, sandbox,
  memory, skills, and agent workflow tasks.
---

# Stonewright

Use this as the first Stonewright skill when the exact workflow is unclear.
It routes the agent to the right specialized skill and MCP tools.

## First Calls

1. Call `stonewright-context-bootstrap` with the task, surface, and intent.
2. Call `stonewright-workflow-preflight` when planning implementation work.
3. Use `fast_path.tool_profile` from workflow preflight before making a separate
   `stonewright-tool-profile` call. Call `stonewright-tool-profile` only when
   switching or verifying a compact profile.
4. If authentication or MCP visibility fails, call
   `stonewright-wordpress-mcp-status` and `stonewright-setup-profile`, then use
   direct `stonewright-wp-cli-*` tools only when WP-CLI is needed.

If `stonewright_essential_tools_mode` is enabled, expect a compact tool list.
Use the fast-path tools returned by preflight instead of rediscovering the full
ability surface. Use preflight's inlined tool profile first; use
`stonewright-tool-profile` only to switch or verify a compact low-tools,
Elementor, content-model, Gutenberg, WP-CLI, or site-admin profile. Use
`low-tools` for Antigravity, Gemini API, or other strict tool-cap clients; it
keeps the client-visible startup surface under 30 tools before the agent
switches to a specialist profile.

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
- Prefer one-call fast paths: `stonewright/elementor-v3-build-page-from-spec`
  with `dry_run`, `stonewright/elementor-v3-batch-mutate` for existing
  Elementor tree edits, `stonewright/content-bulk-upsert-posts` for repeated
  post/CPT/custom-field rows, `stonewright/media-upload-batch`,
  `stonewright/php-execute` for short runtime snippets, and tokenized
  `stonewright-wp-cli-run` for plugin/theme/content operations.
- For CPT rows plus many meta/custom-field values, use
  `stonewright/content-bulk-upsert-posts` after the post type exists. Do not
  fan out into dozens of `wp post meta update` calls unless the bulk ability is
  unavailable.
- Use `stonewright/php-execute` when a direct WordPress/plugin API snippet is
  faster than many typed calls.
- Use `stonewright-wp-cli-run` only with argv tokens.
- For repeated WP-CLI writes or non-ASCII values, use
  `stonewright-wp-cli-batch-run` with JSON argv arrays instead of large inline
  PowerShell/Node scripts.
- For long WP-CLI imports, plugin operations, cache rebuilds, or large content
  batches, use `stonewright-wp-cli-job-start` and poll with
  `stonewright-wp-cli-job-status` instead of blocking one MCP request.
- Do not run `wp ...` in a normal shell as Stonewright recovery, and do not use
  another PHP adapter to replace Stonewright tools.
- Never use `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, or
  `--require`.
- For visual work, implement one or two sections at a time and verify desktop,
  tablet, and mobile before continuing.
- For Elementor widgets, inspect schema or capability summary before writing.
