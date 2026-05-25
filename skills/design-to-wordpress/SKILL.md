---
name: design-to-wordpress
description: >
  Orchestrates the design-reference-to-WordPress pipeline from an image,
  written brief, or manually supplied Design Spec.
---

# Design to WordPress

Use this for building a live WordPress page from a design reference, image, or
manual Design Spec. Stonewright does not ingest external design-tool files; use
a separate design MCP when a design file must be inspected.

## Required bootstrap

1. Call MCP tool `stonewright-context-bootstrap` with the user request, surface, and intent.
2. Read all returned skill playbooks, memory entries, and followups.
3. If the task touches Elementor, call `stonewright-widget-intent-resolve` and
   `stonewright-elementor-widget-implementation-guide` before writing.
4. Use `stonewright-wp-cli-status` and `stonewright-wp-cli-discover` when WP-CLI
   can speed up debugging or site inspection.

## Pipeline

```
design reference / image / brief
  -> stonewright/design-import-image OR manual Design Spec
  -> stonewright/design-extract-tokens
  -> stonewright/design-build-spec
  -> stonewright/design-validate-spec
  -> stonewright/design-choose-renderer
  -> stonewright/design-normalize-assets
  -> stonewright/design-spec-to-gutenberg
     OR stonewright/design-spec-to-elementor-v3
     OR stonewright/design-spec-to-elementor-v4
  -> stonewright/content-create-page OR stonewright/content-update-page
```

## Elementor implementation discipline

- Use real Elementor widgets for the detected intent.
- Do not use HTML widgets unless the user explicitly asks for HTML and the call
  includes `allow_html_widget=true`.
- Configure Content, Style, and Advanced controls. Do not only insert widgets.
- Include responsive values for desktop, tablet, and mobile.
- Use native forms, galleries, nav menus, icon lists, social icons, countdowns,
  containers, Theme Builder templates, sticky settings, hamburger/dropdown
  navigation, background overlays, z-index/order, motion effects, transforms,
  attributes, display conditions, margin, and padding where the design requires.
- If internal docs are insufficient, research official Elementor documentation
  before configuring a widget.
- For section backgrounds, never use a full-page screenshot. Export the exact
  layer/section asset or recreate simple colors and gradients with Elementor
  controls; write an asset selection plan before uploading the media.
- Custom CSS requires explicit user approval and goes in the active theme
  `style.css`, not in an HTML widget.

## Backup and validation

- Validate every Design Spec before rendering.
- Snapshot existing Elementor pages or theme-backed content before writes.
- For destructive writes in production-safe mode, issue and verify a
  confirmation token.

## WP-CLI

Use `stonewright-wp-cli-run` for tokenized commands such as `post`, `option`,
`plugin`, `theme`, `rewrite`, `cache`, `media`, `menu`, `term`, and installed
plugin commands. Never use `wp eval`, `wp eval-file`, `wp shell`, `wp package`,
`--exec`, or `--require`.
