---
title: What is a Grid Container?
source_url: https://elementor.com/help/what-is-a-grid-container/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Grid Container is a CSS `display: grid` layout element in Elementor V4 that enables two-dimensional placement of widgets across explicitly defined rows and columns. Unlike the Flexbox container (one-dimensional), Grid allows precise control of both axes simultaneously — ideal for dashboards, image grids, and magazine-style layouts.

## Use this when

- Creating photo galleries, card grids, or feature-matrix layouts
- Placing elements at exact column/row intersections (explicit placement)
- Building layouts where items must align on both horizontal and vertical axes
- Designing dashboard-style UIs with named grid areas
- Needing items to span multiple columns or rows

## Settings highlights

- **Columns** — define number of columns; each column width uses `fr`, `px`, `%`, or `auto`
- **Rows** — define number of rows; row height can be `auto`, fixed `px`, or `fr`
- **Column Gap / Row Gap** — separate gap controls for X and Y axes
- **Justify Items / Align Items** — align cell contents within their grid areas (start, end, center, stretch)
- **Justify Content / Align Content** — align the entire grid track set within the container
- **Auto Rows** — fallback height for implicit rows created by overflow items
- **Cell span** — per-child controls: `column span` and `row span` to merge cells
- **Atomic prop schema** — `gridTemplateColumns`, `gridTemplateRows`, `columnGap`, `rowGap`, `justifyItems`, `alignItems`
- **Responsive** — all column/row definitions have per-breakpoint overrides

## Limits / gotchas

- Grid Containers require Elementor 3.7+ or V4; older installs show only Flexbox
- Auto-placement algorithm fills cells left-to-right; manual placement via span controls can cause overlap if misconfigured
- Grid does not support flex-specific props like `flex-grow`; items in a grid use `width/height` not `flex-basis`
- Masonry layouts require custom CSS or Loop Grid widget — pure grid containers only support uniform row heights
