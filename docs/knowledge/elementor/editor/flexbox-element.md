---
title: Flexbox element
source_url: https://elementor.com/help/flexbox-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Flexbox element is the V4 atomic layout container equivalent to the Elementor Flexbox Container from V3.x, rendered as `display: flex`. It is the primary structural building block for arranging child atomic elements and widgets in one-dimensional rows or columns with full CSS flexbox control via the atomic prop schema.

## Use this when

- Creating horizontal rows of buttons, icons, cards, or text blocks
- Building vertical stacks (column direction) with controlled alignment
- Nesting Flexbox elements inside Grid containers for hybrid layouts
- Replacing V3 section/column structures with modern CSS-based layout
- Aligning mixed-type children (Heading + Button + Image) along one axis

## Settings highlights

- **direction** prop — `row` or `column` (maps to `flex-direction`)
- **flexWrap** prop — `nowrap`, `wrap`, `wrap-reverse`
- **justifyContent** prop — `flex-start`, `flex-end`, `center`, `space-between`, `space-around`, `space-evenly`
- **alignItems** prop — `flex-start`, `flex-end`, `center`, `stretch`, `baseline`
- **gap** prop — combined row-gap and column-gap (or individual `rowGap`/`columnGap`) in px/em/rem/%
- **minHeight** prop — ensures the element has a minimum height (critical for hero wrappers)
- **overflow** prop — `visible`, `hidden`, `auto`, `scroll`
- **HTML tag** — `div`, `section`, `article`, `header`, `footer`, `main`, `nav`
- **Clickable** — entire element can be linked via the Link prop without adding an anchor child
- **Responsive** — all props have per-breakpoint overrides (Desktop/Tablet/Mobile/Custom)

## Limits / gotchas

- Flexbox is one-dimensional; use the Grid element for two-axis layout control
- Children set to `position: absolute` or `position: fixed` are removed from flex flow and don't participate in gap
- `justifyContent: space-between` with a single child has no visible effect; needs 2+ children
- V4 Flexbox element and V3 Flexbox Container are architecturally similar but use different prop serialization; V3 templates don't auto-convert
