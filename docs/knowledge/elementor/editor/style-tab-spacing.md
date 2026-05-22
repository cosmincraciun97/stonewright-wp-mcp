---
title: Style tab - Spacing
source_url: https://elementor.com/help/style-tab-spacing/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Spacing section of the V4 Style tab controls margin and padding for elements using individual atomic props for each side, with support for logical properties (RTL-aware), responsive breakpoint overrides, and units from px to vw. In V4 these controls live in the Style tab; V3 placed them in a separate "Advanced" tab.

## Use this when

- Adding internal space (padding) inside a container before its children render
- Adding external space (margin) between an element and its siblings
- Creating consistent vertical rhythm between sections using top/bottom margins
- Adjusting mobile-specific spacing that differs from desktop
- Using logical properties for RTL multilingual sites

## Settings highlights

- **Padding** — per-side controls: `paddingTop`, `paddingRight`, `paddingBottom`, `paddingLeft`; unit selector per side (px/em/rem/%)
- **Margin** — per-side controls: `marginTop`, `marginRight`, `marginBottom`, `marginLeft`; supports negative values for overlapping effects
- **Linked values** — lock icon links all four sides to the same value for quick uniform spacing
- **Logical properties** — toggle to use `padding-block-start/end` and `padding-inline-start/end` instead of physical top/right/bottom/left
- **Units** — px, em, rem, %, vw, vh; unit can differ per side
- **Auto margin** — set `margin-left: auto` + `margin-right: auto` to center a block-level element horizontally
- **Responsive** — each side has per-breakpoint overrides; common use: reduce padding on mobile

## Limits / gotchas

- Margin collapse: adjacent block elements' top/bottom margins collapse to the larger value; this affects vertical spacing between elements outside flex containers
- Margin collapse does NOT occur inside flex or grid containers — elements inside Flexbox containers behave as if `overflow: auto` is set
- V4 moved spacing from Advanced tab (V3) to Style tab; muscle memory from V3 will cause confusion
- Negative margins that create overlapping effects may cause scrollbar appearance if the parent doesn't have `overflow: hidden`
