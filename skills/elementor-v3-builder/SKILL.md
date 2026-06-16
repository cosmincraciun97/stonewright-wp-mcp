---
name: elementor-v3-builder
description: >
  Stonewright Elementor V3 builder for pages, containers, widgets, kit colors,
  typography, Loop Grid, templates, and responsive Elementor edits.
---

# Elementor V3 Builder

Operates on the Elementor V3 widget tree using the container model. All write
operations take a backup snapshot before executing. Use
`stonewright/elementor-v3-build-page-from-spec` for spec-driven builds and
`stonewright/elementor-v3-batch-mutate` for surgical edits to an existing tree.

## Pre-flight

Always call MCP tool `stonewright-elementor-v3-status` first. If Elementor is not active
or the post is not an Elementor page, stop and inform the user.

```json
{ "ability": "stonewright/elementor-v3-status", "args": {} }
```

Returns: `{ "active": true, "version": "3.x.x" }`.

## Backup before write

Every write ability that touches post meta calls
`Backup::snapshot_post( $post_id )` internally and returns a `snapshot_id`.
If the ability does NOT call it internally (e.g. `update-kit-colors`), call
`stonewright/elementor-v3-backup-page` explicitly first.

## Container model

Elementor V3 uses containers (flexbox) as the primary layout primitive. When
building from scratch: create container -> add child containers for columns ->
add widgets inside child containers.

Name only major parent containers semantically: `hero`, `header`, `team grid`,
`pricing section`, `product gallery`, `footer`, or similarly useful labels.
Do not name every small inner utility container; inner wrappers should stay
quiet unless their role matters during later edits.

For visual pages from Figma, images, prompts, or design systems, build in
section batches: one section per pass by default, or two sections only when
they are simple and tightly coupled. After each batch, verify desktop, tablet,
and mobile screenshots plus overflow, then auto-continue to the next batch
when checks pass. Do not wait for user approval between passing batches.

For the current section batch and repeated structures inside it, prefer the
spec renderer first. Use `dry_run: true` to validate, inspect diagnostics, and
count generated elements without writing; then repeat the call with
`dry_run: false` and `mode` set to `replace`, `append`, or `replace_section`.

```json
{
  "post_id": 42,
  "mode": "replace_section",
  "dry_run": true,
  "spec": {
    "version": "1.0.0",
    "page": { "title": "Team", "template": "elementor_canvas" },
    "sections": [
      {
        "id": "team",
        "width": "full",
        "layout": "grid",
        "gap": "24px",
        "blocks": [
          {
            "type": "card",
            "blocks": [
              { "type": "image", "id": 123, "alt": "Member name" },
              { "type": "heading", "level": 3, "text": "Member name" },
              { "type": "paragraph", "text": "Role" }
            ]
          }
        ]
      }
    ]
  }
}
```

If the spec validator rejects the payload, fix the spec shape first. Do not fall
back to dozens of single-widget calls until the first-pass renderer path has
been tried with a valid spec.

For an existing page where the task is to add, update, move, or remove several
elements, read the page with
`stonewright/elementor-v3-get-page-structure` in summary mode first. It returns
IDs, paths, widget types, labels, child counts, and setting keys without loading
the raw Elementor tree. Request `responseMode: "full"` only when raw settings
are required for the next edit. Then use one
`stonewright/elementor-v3-batch-mutate` call. Use `op_id` refs to chain
generated IDs inside the same request:

```json
{
  "post_id": 42,
  "dry_run": true,
  "operations": [
    { "action": "add_container", "op_id": "row", "settings": { "layout": "row" } },
    {
      "action": "add_widget",
      "parent_ref": "row",
      "op_id": "headline",
      "widget_type": "heading",
      "settings": { "title": "Fast native Elementor" }
    },
    {
      "action": "update_element",
      "element_ref": "headline",
      "settings": { "header_size": "h2" }
    }
  ]
}
```

For Loop Grid sections backed by CPT/custom fields, keep the data path compact:
confirm/register the CPT, write rows and meta with
`stonewright/content-bulk-upsert-posts`, create the loop item template, then add
or update the Loop Grid with one `stonewright/elementor-v3-batch-mutate` call.
Use Elementor dynamic tags (`__dynamic__`) for post title or custom-field
headings inside loop templates; do not rely on many manual meta updates.

## Frontend layout contract

- Use Elementor V3 containers and native widgets. Do not add HTML widgets unless
  the user explicitly requests HTML.
- Start visual tasks by measuring the reference screenshot: viewport/canvas size,
  section bounds, centered max-widths, typography, colors, spacing, and asset
  crop bounds. Then build, screenshot the live page with external Playwright MCP
  at the same viewport, compare deltas, and iterate.
- If no Playwright/browser MCP tool is visible, install/connect it with
  `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`, restart the AI
  client, and stop before the first visual write until the tool appears.
- Before capturing full-page screenshots, scroll through the page or otherwise
  preload lazy-loaded media so missing assets are not mistaken for layout
  failures.
- Before the first write, satisfy the returned `visual_build_gate`: token table,
  existing media audit, and section-by-section implementation plan.
- Treat the captured visual reference as the layout authority. Use design-tool
  layers for tokens, styles, text, and assets, but do not mirror broken layer
  nesting as Elementor containers when a cleaner native structure matches the
  screenshot better.
- For long reference pages, capture and compare section screenshots before
  judging the full page. Never implement more than two visual page sections in
  a single write-and-verify batch.
- Put every page section in a full-width outer container, then a centered inner
  container with the design max-width. Do not leave content floating at page
  edges or stacked as a single accidental column.
- Use dedicated `stonewright/elementor-add-*` abilities for known Elementor
  widgets. Use `stonewright/elementor-v3-add-widget` only for unknown or
  third-party widgets after schema lookup.
- For every widget you intend to write, call
  `stonewright/elementor-v3-get-widget-schema` in summary mode and inspect
  Content, Style, and Advanced controls before choosing settings. Request
  `responseMode: "full"` only when default values are required for the next
  write. If the schema, local harvested docs, or implementation guide are
  incomplete, research official Elementor documentation online before writing
  that widget.
- Use exact control keys from widget schemas. For example, Icon Box uses
  `selected_icon`, `primary_color`, and `secondary_color`; do not invent
  aliases like `icon`, `icon_primary_color`, or `icon_background_color`.
- Configure all relevant tabs. Content holds source data, items, media, links,
  and semantic choices. Style holds typography, colors, spacing, states,
  borders, shadows, and widget-specific presentation. Advanced can use
  position absolute/fixed, z-index, motion effects, transform, background,
  background overlay, border, mask, responsive visibility, custom attributes,
  order, align self, width, padding, margin, CSS ID, and CSS classes.
- For repeated cards, logos, sponsor grids, galleries, or pricing blocks, build
  the current section batch with `stonewright/elementor-v3-build-page-from-spec`
  dry-run/write. Use `stonewright/elementor-v3-batch-mutate` for focused fixes
  after screenshot comparison.
- If a full-page spec is too complex, split the work into one- or two-section
  specs before falling back to many single-element updates.
- When debugging Elementor V3 boxed containers, inspect the rendered DOM before
  writing CSS. Boxed containers usually render children under `.e-con-inner`, so
  direct-child selectors can miss the actual flex container.
- Use flex row containers for desktop two-column designs and responsive
  direction/visibility settings for tablet and mobile.
- Sticky headers must be sticky on desktop and mobile when requested. Mobile
  navigation must use the native nav-menu hamburger/dropdown controls.
- Use nav-menu for header menus, form for newsletter/contact forms,
  image-gallery/gallery for photo galleries, social-icons for social rows, and
  icon-list or linked text widgets for footer columns.
- Preserve exported artwork. If a speaker/card image already contains the visual
  border, do not add an Elementor border.
- For section labels, copy typography, letter spacing, alignment, and underline
  or border decorations from the design instead of approximating them with plain
  text.
- Custom CSS requires explicit user approval after widget/settings options are
  exhausted. Approved CSS belongs in the active theme `style.css`, and should
  target semantic classes or named containers instead of unstable generated
  element IDs whenever possible.
- Before signoff, capture desktop, tablet, and mobile screenshots on the
  logged-out public page and report the visible deltas. Admin/editor chrome does
  not count as viewport evidence.

## Kit changes

For design-derived builds, prepare the kit plan before the first page element
write: call `stonewright/elementor-v3-get-kit-globals`, compare reusable colors
and typography against the design, then decide page-local exceptions. If the
user has approved site-wide design changes, call `update-kit-colors` and
`update-kit-typography` before building the page so later element payloads can
reuse global tokens instead of repeating raw values. If approval is missing or
the design is one-off, keep those values local in widget/container controls.
Mutation abilities do not take a post_id; they write to the active kit post.

## Save as template

After building a page, optionally save it as a reusable template:

```json
{
  "ability": "stonewright/elementor-v3-save-template",
  "args": {
    "post_id": 42,
    "title": "Home Hero",
    "template_type": "section"
  }
}
```

Returns `{ "template_id": 150 }`.

## Ability summary

| Ability | Purpose |
|---|---|
| `stonewright/elementor-v3-status` | Check Elementor active + version |
| `stonewright/elementor-v3-get-page-structure` | Read compact page outline by default; use `responseMode: "full"` for raw tree |
| `stonewright/elementor-v3-get-element` | Read single element by ID |
| `stonewright/elementor-v3-add-container` | Add flex container |
| `stonewright/elementor-add-*` | Add known native widgets with schema validation |
| `stonewright/elementor-v3-add-widget` | Escape hatch for unknown/third-party widgets |
| `stonewright/elementor-v3-update-element` | Update element settings |
| `stonewright/elementor-v3-move-element` | Reorder/reparent element |
| `stonewright/elementor-v3-remove-element` | Delete element |
| `stonewright/elementor-v3-backup-page` | Explicit snapshot |
| `stonewright/elementor-v3-save-template` | Save to Elementor library |
| `stonewright/elementor-v3-list-widgets` | List all registered widgets |
| `stonewright/elementor-v3-get-widget-schema` | Get compact widget controls by default; use `responseMode: "full"` for defaults |
| `stonewright/elementor-v3-get-kit-globals` | Read active kit colors and typography |
| `stonewright/elementor-v3-update-kit-colors` | Mutate kit color palette |
| `stonewright/elementor-v3-update-kit-typography` | Mutate kit typography |
| `stonewright/elementor-v3-update-page-settings` | Page-level settings |
| `stonewright/elementor-v3-build-page-from-spec` | Spec-driven build with dry_run, metrics, append/replace modes |
| `stonewright/elementor-v3-batch-mutate` | Many add/update/move/remove operations in one guarded write |
| `stonewright/elementor-v3-apply-bundle` | Multi-post spec bundle |

## Confirmation token for destructive writes

Before calling `build-page-from-spec` with `mode: "replace"` or
`mode: "replace_section"`, or before `batch-mutate` with `remove_element`, emit:

```
"Confirm:
  post_id: 42
  snapshot_id: <id>
  action: <action description>
Reply YES to proceed."
```

See `references/widget-examples.md` for concrete widget payloads.
See `references/kit-examples.md` for kit mutation examples.
