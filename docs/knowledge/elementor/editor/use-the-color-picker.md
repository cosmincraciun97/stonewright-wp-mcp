---
title: Use the color picker
source_url: https://elementor.com/help/use-the-color-picker/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Elementor V4 color picker is the universal color selection tool used across all color-accepting props in the Style tab. It provides HEX, RGB, HSL, and RGBA input modes, a global colors palette linked to the design system, a recently-used colors tray, and the ability to save new global colors — all accessible inline without leaving the element panel.

## Use this when

- Selecting a specific color for text, background, border, shadow, or any color prop
- Applying a global brand color from the design system palette to maintain consistency
- Creating a new global color on the fly while working in an element
- Checking or adjusting a color's opacity (alpha channel) without switching to RGBA hex
- Picking a color from anywhere on the screen using the eyedropper

## Settings highlights

- **Color modes** — HEX, RGB, HSL, RGBA; toggle via format selector in the picker
- **Alpha channel** — RGBA mode exposes an opacity slider separate from element-level opacity
- **Global colors palette** — swatches for all globally defined colors (Site Settings > Global Colors); click to apply
- **Recently used** — last 8 recently picked colors shown for quick reuse
- **Eyedropper** — sample any color from the editor canvas or the browser viewport
- **Add to Global Colors** — "+" button saves the current color as a new global color with a custom name
- **Variable binding** — in V4, color fields can also accept a CSS variable reference from the Variables Manager instead of a fixed value

## Limits / gotchas

- The color picker does not support LCH, LAB, or P3 color spaces natively; use hex or RGB for maximum browser compatibility
- Global colors are site-wide; changing a global color updates every element using it — always preview before saving
- The eyedropper tool requires a browser that supports the EyeDropper API (Chrome 95+, Edge 95+); Firefox and Safari do not yet support it
- V4 color props can reference CSS variables (`var(--color-primary)`) but this requires manual entry in the HEX field; the picker does not have a dedicated variable picker UI beyond the Global Colors palette
