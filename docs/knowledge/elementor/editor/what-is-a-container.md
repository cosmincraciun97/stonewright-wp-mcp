---
title: What is a Flexbox Container?
source_url: https://elementor.com/help/what-is-a-container/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Flexbox Container is Elementor's primary layout element, replacing the legacy section/column model. A container is a single `<div>` rendered with CSS `display: flex`, enabling flexible one-dimensional layout of any child widgets or nested containers. It supports styling, background, responsive overrides, and atomic prop controls from V4.

## Use this when

- Building any page layout in Elementor V4 (containers are the default building block)
- Creating horizontal rows of cards, icons, buttons, or text blocks
- Building vertical stacks (column direction) for mobile-first designs
- Nesting containers inside containers for complex multi-column layouts
- Replacing legacy sections and columns for better performance and CSS purity

## Settings highlights

- **Layout tab** — Direction, Wrap, Justify Content, Align Items, Gap, Min Height, Overflow
- **Style tab** — Background (solid/gradient/image/video), Border, Box Shadow, CSS Filters, Opacity, Blend Mode
- **Advanced tab** — Margin, Padding, Z-Index, CSS ID, CSS Classes, Custom CSS, Motion Effects, Positioning
- **Atomic prop schema** — `direction`, `flexWrap`, `justifyContent`, `alignItems`, `gap`, `minHeight`, `overflow` map directly to CSS flex properties
- **Width control** — Boxed (max-width constrained) or Full Width; inner content width set separately
- **Clickable container** — entire container can be linked via the Link option without wrapping an anchor widget
- **Responsive overrides** — each layout/style prop has per-breakpoint (Desktop/Tablet/Mobile) variants
- **HTML tag** — container renders as `div` by default; can be changed to `section`, `article`, `header`, etc.

## Limits / gotchas

- Containers are one-dimensional (flex); for two-dimensional grid use the Grid Container
- Nesting too many containers (5+ levels) adds DOM depth and can hurt performance
- Legacy "inner section" widget is deprecated; replace with nested container
- Position: Absolute on a child removes it from flex flow; parent needs `position: relative`
