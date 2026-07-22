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

1. Call `stonewright-task-start` with the task, surface, and intent. Treat
   `configured_mcp_surface` as the user's active Setup preference; a suggested
   task profile is guidance, not permission to overwrite it.
   For Elementor work, pass `post_id` of the page you will edit so document
   architecture is detected automatically and V4-runtime writes are not blocked
   as ambiguous. If the document is already corrupt (double-encoded meta or
   duplicate ids), call `stonewright/elementor-v3-repair-document` before editing.
2. Use `stonewright-context-bootstrap` or `stonewright-workflow-preflight` only
   as compatibility paths when task-start is unavailable.
3. Use `fast_path.tool_profile` from task-start before making a separate
   `stonewright-tool-profile` call. Call `stonewright-tool-profile` only when
   switching or verifying a compact profile.
4. Read `expertise_packs`; load only the matching section with
   `stonewright-expertise-get`. Never activate draft, stale, retired, or
   version-incompatible guidance.
5. If authentication or MCP visibility fails, call
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

### Profile self-upgrade loop

When a needed tool is missing or an ability returns a gated/missing-tool error:

1. Read `configured_mcp_surface` first. Do not activate a broader site-wide
   profile than the user selected. Ask the user to change Setup, or use
   `action: "activate"` only when they explicitly authorize that preference
   change. Otherwise follow the ordered tools from `stonewright-task-start`.
2. If the result has `tools_changed: true` or a non-empty
   `re_list_instruction`, re-list tools (`tools/list`) before continuing.
   stdio companion sessions emit `notifications/tools/list_changed` and refresh
   their proxy registration; HTTP MCP already serves a fresh list per request.
3. Retry the original work with the newly visible tools. If the client still
   shows the old list after notification, restart the MCP session.

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
  Elementor tree edits, `stonewright/theme-builder-apply-template` for real
  Elementor Theme Builder templates with display conditions,
  `stonewright/content-model-loop-grid-flow` for CPT/ACF-backed Loop Grid
  sections, `stonewright/content-bulk-upsert-posts` for repeated
  post/CPT/custom-field rows, `stonewright/media-upload-batch`,
  `stonewright/php-execute` for short runtime snippets, and tokenized
  `stonewright-wp-cli-run` for plugin/theme/content operations.
- For Theme Builder work, do not edit `_elementor_conditions` directly. Use
  `stonewright/theme-builder-apply-template`; it renders the spec, snapshots,
  applies conditions, clears/warms relevant state, and returns repair hints.
- For admin-editable repeated cards, default to
  `stonewright/content-model-loop-grid-flow` when the section needs CPT rows,
  custom fields, a loop item template, and a Loop Grid widget contract.
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
