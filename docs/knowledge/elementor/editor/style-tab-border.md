---
title: Style tab - Border
source_url: https://elementor.com/help/style-tab-border/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Border section of the V4 Style tab controls border styling for any element — border type, width, color, and border-radius — using atomic CSS props. It consolidates what V3 split between the Style tab "Border" control and the Advanced tab border-radius into a unified Border section with per-side and per-corner controls and full element-state support.

## Use this when

- Adding a visible border outline to containers, cards, buttons, or images
- Creating rounded corners (border-radius) on elements for a modern card appearance
- Styling form input borders with focus-state color changes
- Creating CSS-only dividers using border-bottom on headings
- Building pill-shaped buttons with `border-radius: 50px`

## Settings highlights

- **Border type** — None, Solid, Double, Dotted, Dashed, Groove
- **Border width** — per-side (top/right/bottom/left) in px; linked or individual control
- **Border color** — color picker with global color integration; supports RGBA
- **Border radius** — per-corner (top-left, top-right, bottom-right, bottom-left) in px/%; linked or individual
- **Logical border properties** — `border-inline-start/end` and `border-block-start/end` for RTL-aware borders
- **Box Shadow** — also in the Border section (drop shadow on the element box); offset X/Y, blur, spread, color, inset option
- **Element states** — separate border color/width/radius per Normal / Hover / Active / Focus
- **Responsive** — all border props support per-breakpoint overrides

## Limits / gotchas

- `border-radius` clips the background and children; child elements that overflow will be clipped when `overflow: hidden` is also set
- Box shadow does not trigger layout reflow; use it instead of border for outline effects that shouldn't affect element sizing
- `outline` (accessibility focus outline) is separate from `border` and is not controlled here; use custom CSS for outline styling
- V4 moved border-radius from the Advanced tab (V3 "Border Radius" control) to the Style tab Border section — check both locations when debugging existing V3 pages
