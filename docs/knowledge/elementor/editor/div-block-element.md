---
title: Div Block element
source_url: https://elementor.com/help/div-block-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Div Block element is a V4 atomic element that renders a plain `<div>` (or any block-level HTML tag) with no default layout behavior — it is a structural wrapper element. Unlike Flexbox or Grid containers it has `display: block` by default, making it useful for wrapping inline elements, creating simple stacked block structures, or serving as a styled box without flex/grid complexity.

## Use this when

- Wrapping a group of inline elements that don't need flex alignment
- Creating a visually styled box (card, panel, callout) with background, border, padding
- Using as a positioned layer (absolute or sticky) inside a container
- Serving as a semantic HTML wrapper (article, aside, main) by changing the HTML tag
- Building custom-coded components that need a clean, unstyled wrapper

## Settings highlights

- **HTML tag** — `div`, `article`, `aside`, `section`, `header`, `footer`, `main`, `nav`, `figure`
- **display** — block by default; override to `inline-block` or `flex` via custom CSS if needed
- **Style tab** — Background, Border, Box Shadow, Opacity, CSS Filters, Blend Mode
- **Spacing** — Padding and Margin via the Spacing section of the Style tab (atomic props: `paddingTop/Right/Bottom/Left`, `marginTop/Right/Bottom/Left`)
- **Size** — Width, Height, Min/Max Width/Height controls
- **Position** — Static, Relative, Absolute, Fixed, Sticky with offset controls
- **Custom CSS** — write scoped CSS targeting the element's generated selector
- **Attributes** — add data-* or ARIA attributes in the Advanced tab

## Limits / gotchas

- A Div Block does not flex its children — if you need to align children horizontally use a Flexbox element instead
- V4 Div Block is different from the V3 "Inner Section" widget; it does not add structural columns automatically
- Setting `position: absolute` removes the element from document flow; parent must have `position: relative` or the element escapes to nearest positioned ancestor
- Div Blocks are not drag-and-drop multi-column containers; use Flexbox or Grid for that purpose
