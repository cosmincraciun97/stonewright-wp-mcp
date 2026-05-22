---
title: Style tab - Position
source_url: https://elementor.com/help/style-tab-position/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Position section of the V4 Style tab controls how elements are positioned in the document flow and relative to their containing block. It exposes CSS `position`, `top/right/bottom/left` offset props, `z-index`, and logical offset variants — replacing the V3 Advanced tab's positioning controls with a dedicated atomic prop section.

## Use this when

- Absolutely positioning a badge, overlay, or icon on top of a card image
- Making an element sticky so it stays in view during scroll
- Fixing an element to the viewport (sticky header, floating button)
- Using `z-index` to control layering when elements overlap
- Removing an element from document flow to position it relative to a parent

## Settings highlights

- **position** prop — `static` (default), `relative`, `absolute`, `fixed`, `sticky`
- **Offset props** — `top`, `right`, `bottom`, `left` in px/em/rem/%; only active when position is not `static`
- **Logical offsets** — `inset-inline-start/end`, `inset-block-start/end` for RTL-aware positioning
- **z-index** prop — integer stacking order; higher values render above lower ones
- **Sticky threshold** — for `sticky`, the `top` value defines when the element sticks relative to the scroll viewport
- **Responsive** — all position props have per-breakpoint overrides (e.g. `position: fixed` on desktop, `relative` on mobile)
- **Parent requirement** — `absolute` positioning is relative to the nearest ancestor with `position: relative/absolute/fixed/sticky`

## Limits / gotchas

- An element with `position: absolute` is removed from flex/grid flow; parent container gap and alignment no longer affect it
- `position: fixed` elements are relative to the browser viewport, not the container; they escape all Elementor container wrappers
- V4 position controls are in the Style tab; V3 placed them in the Advanced tab — a common source of confusion during migration
- Sticky elements require a scrollable ancestor; if the parent has `overflow: hidden`, sticky will not work
