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
   can speed up debugging or site inspection. In the Node companion MCP, these
   tools run directly and do not require the WordPress-side HTTP bridge.

## Pipeline

```
design reference / image / brief
  -> stonewright/design-import-image OR manual Design Spec
  -> stonewright/design-extract-tokens
  -> global-style preflight for Elementor/FSE when relevant
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
- Before the first page write, prepare a global-style plan from the measured
  tokens: reusable kit colors, reusable kit typography, and values that should
  stay local to this page. If the user has approved site-wide design changes,
  update Elementor kit colors/typography before building page elements; otherwise
  keep the values local in widget/container controls.
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
- Do not use the design canvas width as a fixed live page width. Convert it to
  responsive max-width, percentage width, and padding rules.
- Horizontal scroll is a hard failure. Before completion, verify
  `document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1`
  at desktop, tablet, and mobile viewports.
- If Elementor Canvas/no header/footer was requested, fail the pass if a
  WordPress page title or theme chrome is visible.
- If SVG upload is blocked, do not create sandbox or mu-plugin workarounds
  without explicit user approval. Use native Elementor icon controls when an
  equivalent icon is acceptable, or ask for a safe SVG enablement path.
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

If the companion MCP is installed, `stonewright-wp-cli-*` tools are direct
companion aliases. If a WordPress-proxied status call reports the companion
bridge offline on port `8765`, try the direct MCP tool before assuming WP-CLI is
missing.

If WP-CLI is still unavailable and the user approves installing it, call
`stonewright-wp-cli-install`. It downloads the official `wp-cli.phar` into the
Stonewright companion cache and does not modify system `PATH`.
