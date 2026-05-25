---
name: elementor-v3-builder
description: >
  Builds and edits Elementor V3 pages: containers, widgets, kit colors/
  typography, templates. Requires Elementor V3 active on the site.
---

# Elementor V3 Builder

Operates on the Elementor V3 widget tree using the container model. All write
operations take a backup snapshot before executing. Use `design-spec-to-elementor-v3`
for spec-driven builds; use individual element abilities for surgical edits.

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

## Frontend layout contract

- Use Elementor V3 containers and native widgets. Do not add HTML widgets unless
  the user explicitly requests HTML.
- Put every page section in a full-width outer container, then a centered inner
  container with the design max-width. Do not leave content floating at page
  edges or stacked as a single accidental column.
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
  exhausted. Approved CSS belongs in the active theme `style.css`.

## Kit changes

Kit color and typography changes are global and affect every page. Confirm
with the user before calling `update-kit-colors` or `update-kit-typography`.
These abilities do not take a post_id; they write to the active kit post.

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
| `stonewright/elementor-v3-get-page-structure` | Read full element tree |
| `stonewright/elementor-v3-get-element` | Read single element by ID |
| `stonewright/elementor-v3-add-container` | Add flex container |
| `stonewright/elementor-v3-add-widget` | Add widget to container |
| `stonewright/elementor-v3-update-element` | Update element settings |
| `stonewright/elementor-v3-move-element` | Reorder/reparent element |
| `stonewright/elementor-v3-remove-element` | Delete element |
| `stonewright/elementor-v3-backup-page` | Explicit snapshot |
| `stonewright/elementor-v3-save-template` | Save to Elementor library |
| `stonewright/elementor-v3-list-widgets` | List all registered widgets |
| `stonewright/elementor-v3-get-widget-schema` | Get widget control schema |
| `stonewright/elementor-v3-update-kit-colors` | Mutate kit color palette |
| `stonewright/elementor-v3-update-kit-typography` | Mutate kit typography |
| `stonewright/elementor-v3-update-page-settings` | Page-level settings |
| `stonewright/elementor-v3-build-page-from-spec` | Full spec-driven build |

## Confirmation token for destructive writes

Before calling `build-page-from-spec` with `replace: true` or
`remove-element`, emit:

```
"Confirm:
  post_id: 42
  snapshot_id: <id>
  action: <action description>
Reply YES to proceed."
```

See `references/widget-examples.md` for concrete widget payloads.
See `references/kit-examples.md` for kit mutation examples.
