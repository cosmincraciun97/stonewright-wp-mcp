---
name: design-to-wordpress
description: >
  Stonewright design-reference-to-WordPress pipeline from Figma, image,
  written brief, screenshot, prompt, or manually supplied Design Spec.
---

# Design to WordPress

Use this for building a live WordPress page from a design reference, image, or
manual Design Spec. Stonewright does not ingest external design-tool files; use
a separate design MCP when a design file must be inspected.

## Required task start

1. Call MCP tool `stonewright-task-start` with the user request, surface, and intent.
2. Read all returned skill playbooks, memory entries, `visual_quality_contract`,
   `visual_build_gate`, and followups.
3. For visual work, verify an external Playwright/browser MCP tool is visible
   before the first write. If missing, tell the user to add
   `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`, restart the
   AI client, and stop until the tool appears.
4. If the task touches Elementor, call `stonewright-widget-intent-resolve` and
   `stonewright-elementor-widget-implementation-guide` before writing.
5. Use `stonewright-wp-cli-status` and `stonewright-wp-cli-discover` when WP-CLI
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

When the design also needs repeated CPT/post rows with custom fields, create or
confirm the post type first, then use `stonewright/content-bulk-upsert-posts`
for the rows and meta in one call. Each row needs `slug` and `title`; use
`status` or the WordPress-shaped alias `post_status` for draft/publish/private
state. Avoid many `wp post meta update` commands
for page-section libraries, team cards, pricing tables, locations, testimonials,
or similar structured content.

## Fast Elementor first pass

For visual pages from Figma, an image, a written prompt, or a design system,
implement one section at a time. Use two sections in one pass only when they
are simple and tightly coupled. After each section batch, verify desktop,
tablet, and mobile screenshots plus overflow, then auto-continue to the next
batch when the checks pass. Do not wait for user approval between passing
batches.

For repeated structures inside the current batch, prefer one validated spec
write over many single-widget calls. Minimal shape:

```json
{
  "post_id": 42,
  "replace": true,
  "spec": {
    "version": "1.0.0",
    "page": { "title": "Team", "template": "elementor_canvas" },
    "sections": [
      {
        "id": "hero",
        "width": "full",
        "layout": "stack",
        "background": { "color": "#130d39" },
        "padding": { "top": "96px", "right": "24px", "bottom": "96px", "left": "24px" },
        "blocks": [
          { "type": "heading", "level": 1, "text": "Team" },
          { "type": "paragraph", "text": "Intro copy." }
        ]
      }
    ]
  }
}
```

Call `stonewright-design-validate-spec` before rendering when building the spec
manually. Use `stonewright-elementor-v3-build-page-from-spec` with `dry_run`
for the current one- or two-section batch, then write the same spec. Use
`stonewright-elementor-v3-batch-mutate` for screenshot deltas on an existing
Elementor tree.

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
- For repeated cards, logos, sponsor grids, galleries, or pricing blocks, build
  the current section batch with `stonewright-elementor-v3-build-page-from-spec`
  dry-run/write; reserve `stonewright-elementor-v3-batch-mutate` for
  screenshot-driven corrections.
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
  equivalent icon is acceptable, or ask for an approved SVG enablement path.
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
Do not run `wp ...` in a normal shell as Stonewright recovery, and do not use
another PHP adapter to replace Stonewright tools. Use `stonewright/php-execute`
for short WordPress runtime snippets when direct inspection is faster.

For repeated writes or strings with diacritics, prefer
`stonewright-wp-cli-batch-run`; do not paste large inline PowerShell/Node
scripts with raw non-ASCII text.
For long WP-CLI work such as imports, cache rebuilds, plugin
maintenance, or large content batches, use `stonewright-wp-cli-job-start` and
poll `stonewright-wp-cli-job-status` so one MCP request does not block.

If the companion MCP is installed, `stonewright-wp-cli-*` tools are direct
companion aliases. If a WordPress-proxied status call reports the companion
bridge offline on port `8765`, try the direct MCP tool before assuming WP-CLI is
missing.

If WP-CLI is still unavailable and the user approves installing it, call
`stonewright-wp-cli-install`. It downloads the official `wp-cli.phar` into the
Stonewright companion cache and does not modify system `PATH`.

## Visual build gate

Pixel-matching tasks must not move straight from design extraction to page
writes. Before the first write, produce and keep current:

- A reference token table with section bounds, max widths, colors, typography,
  spacing, and asset crop bounds for each target viewport.
- An existing media audit by filename, alt text, dimensions, and visible crop so
  matching WordPress media is reused instead of uploaded again.
- A section implementation plan mapping reference nodes to native Elementor or
  Gutenberg structures, responsive breakpoints, and any user-approved CSS
  classes.
- Section reference screenshots when the design is long or hard to compare as
  one image.
- A section batch plan with `max_sections` no higher than `2`, the exact
  `section_ids` in the current pass, and the desktop/tablet/mobile checks that
  must pass before the next batch starts.

The visible reference screenshots are the source of truth for layout. Design
tool structure is only a source for text, tokens, styles, assets, and useful
node hints. If the layer tree or grouping conflicts with what the screenshot
shows, build the cleaner native WordPress structure that matches the screenshot.

Before completion, provide screenshot deltas for every section batch at
desktop, tablet, and mobile, plus logged-out public viewport checks. Admin
bars, editor chrome, or authenticated-only UI do not count as responsive proof.
Do not claim pixel-perfect when any delta is unclassified; each delta must be
fixed, accepted by the user as a limitation, or blocked by missing approval.
