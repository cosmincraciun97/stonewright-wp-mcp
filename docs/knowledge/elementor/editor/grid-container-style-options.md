---
title: Style options for grid containers
source_url: https://elementor.com/help/grid-container-style-options/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The grid container style options in Elementor V4 define the visual presentation of CSS Grid-based containers, covering background, border, spacing, and responsive column/row track sizing directly in the Style tab. These controls use the atomic prop schema rather than legacy section/column overrides.

## Use this when

- Styling a grid container background (color, image, gradient) independently of its children
- Adding borders or box shadows to the grid wrapper element
- Controlling gap between grid cells via the unified gap control
- Applying responsive-specific background or border overrides per breakpoint
- Setting overflow behavior when grid items exceed container dimensions

## Settings highlights

- **Background** — solid color, gradient, image, or video via layered background picker
- **Border type/width/color/radius** — unified border control with per-side and corner overrides
- **Box shadow** — offset, blur, spread, color for drop-shadow effects on the grid element
- **Grid gap (row-gap / column-gap)** — separate row and column gap values supporting px/em/rem/% units
- **Padding** — inset spacing inside the grid container before cells render
- **Opacity** — element-level opacity distinct from background-color alpha
- **CSS Filters** — blur, brightness, contrast, hue-rotate on the container
- **Blend mode** — how the container composites with elements beneath it
- **Responsive overrides** — all controls expose per-breakpoint variants in V4

## Limits / gotchas

- V4 grid containers differ from V3 sections: there is no inner-section concept; gaps replace column gutters
- Gap controls apply to all cells uniformly; for unequal spacing use custom CSS `grid-template-columns` overrides
- Background video in V4 grid containers may require enabling SVG/video support in Site Settings
- Some style properties do not cascade to nested atomic elements — set styles at the correct scope
