---
title: Responsive editing
source_url: https://elementor.com/help/responsive-editing/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Responsive editing in Elementor V4 enables setting different style values for each device breakpoint (Desktop, Tablet, Mobile, and custom breakpoints) directly in the editor. Every style prop in the unified Style tab exposes per-breakpoint controls, and a visual breakpoint bar at the top of the canvas lets designers switch contexts and preview the layout at each screen width.

## Use this when

- Adjusting font size on mobile to improve readability
- Changing Flexbox direction from row (desktop) to column (mobile) for stacked layouts
- Hiding or showing elements at specific breakpoints
- Modifying padding, gap, or margin per device for consistent spacing
- Previewing how the live page will appear on phone, tablet, and desktop simultaneously

## Settings highlights

- **Breakpoint bar** — canvas-top controls for Desktop (default), Tablet (768px), Mobile (480px), plus custom breakpoints defined in Site Settings
- **Per-prop breakpoint icons** — each style control shows a device icon when a breakpoint-specific value exists; blue icon = overridden, grey = inheriting from larger breakpoint
- **Inheritance** — values cascade down: Desktop → Tablet → Mobile; only overridden props need values at smaller breakpoints
- **Custom breakpoints** — define additional breakpoints (e.g. 1024px, 375px) in Elementor > Site Settings > Layout
- **Display: none per breakpoint** — hide any element at a specific breakpoint via the Advanced tab → Responsive Visibility controls
- **Responsive typography** — font-size and line-height have per-breakpoint fields with optional clamp/fluid scaling
- **Container direction** — switch Flexbox direction from row to column at mobile breakpoint for stacked layouts
- **Image source** — swap image `src` per breakpoint for art-directed responsive images

## Limits / gotchas

- V4 responsive system uses min-width media queries in a mobile-first cascade; setting tablet value affects mobile too unless mobile is explicitly set
- "Inherited responsive values" icon helps identify which breakpoint a value comes from — critical for debugging unexpected responsive behavior [[what-are-inherited-responsive-values]]
- Custom breakpoints are global; adding one affects all pages on the site
- Older V3 templates may use pixel-fixed dimensions that don't flex responsively — review after migration to V4
