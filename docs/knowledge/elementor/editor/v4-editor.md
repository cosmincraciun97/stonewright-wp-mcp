---
title: Editor Version 4
source_url: https://elementor.com/help/build-with-the-editor/v4-editor/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Elementor Editor V4 represents a modernized website building interface introducing atomic element components, enhanced styling controls, and improved responsive design workflows compared to the legacy V3 architecture. V4 replaces the widget-panel control model with a unified Style tab and atomic prop schema mapped directly to CSS properties.

## Use this when

- Building new sites with contemporary component-based design patterns
- Requiring advanced responsive editing across multiple device breakpoints
- Implementing CSS-based styling with logical properties and modern layout systems
- Creating reusable atomic elements (Button, Heading, Image, Flexbox, SVG, Tabs)
- Needing interaction and animation capabilities with element states

## Settings highlights

- **Atomic element prop schemas** — replacing legacy widget control systems; props map 1:1 to CSS
- **Style tab organization** — Background, Border, Effects, Layout, Position, Size, Spacing, Typography all in one panel
- **Element states** — manage hover, active, focus, and disabled style variations per element
- **Class Manager** — named CSS classes with reusable style sets across the design
- **Variables and Variables Manager** — design tokens (color, spacing, font) for system-wide consistency
- **Dynamic tags in V4** — updated API for binding post meta, site info, and custom fields
- **Nested links** — enabling complex interaction hierarchies within atomic elements
- **Interactions panel** — trigger animations and state changes without JavaScript custom code
- **Responsive editing** — viewport controls with desktop/tablet/mobile/custom breakpoints
- **Custom CSS at element level** — selector support with scoped cascade

## Limits / gotchas

- V4 fundamentally restructures control architecture from V3; legacy widget workflows require migration
- Sections and columns from V3 are not available as new additions in pure V4 mode
- Some third-party plugins may lack V4 compatibility; verify before implementing
- Logical properties and CSS transform features differ significantly from V3 control methodologies
