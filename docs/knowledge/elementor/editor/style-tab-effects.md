---
title: Style tab - Effects
source_url: https://elementor.com/help/style-tab-effects/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Effects section of the V4 Style tab controls CSS visual effects applied to the element as a whole — including opacity, CSS filters, box shadow, text shadow, blend mode, and CSS transform. These props replace scattered V3 controls (Advanced tab opacity, Motion Effects transforms) with a unified Effects section in the atomic Style tab.

## Use this when

- Applying a drop shadow to a card, image, or button for depth
- Adding blur, brightness, contrast, or grayscale CSS filters to images
- Setting element opacity for overlays or disabled states
- Applying a CSS `transform` (scale, rotate, skew, translate) for decorative positioning
- Blending an element with its background using CSS `mix-blend-mode`

## Settings highlights

- **Opacity** — 0–1 slider affecting the entire element and all its children
- **CSS Filters** — Blur (px), Brightness (%), Contrast (%), Saturate (%), Hue Rotate (deg), Invert (%), Grayscale (%); can be combined
- **Box Shadow** — offset-X, offset-Y, blur, spread, color; supports multiple shadows per element
- **Text Shadow** — applies to text-rendering elements; offset-X, offset-Y, blur, color
- **Blend Mode** — `normal`, `multiply`, `screen`, `overlay`, `darken`, `lighten`, `color-dodge`, `color-burn`, `hard-light`, `soft-light`, `difference`, `exclusion`, `hue`, `saturation`, `color`, `luminosity`
- **Transform** — scale (X/Y), rotate (Z), skew (X/Y), translate (X/Y/Z) in a unified transform builder
- **Element states** — all Effects props support hover/active/focus state variants

## Limits / gotchas

- `opacity` on a parent creates a stacking context; absolutely positioned children will clip to it
- CSS filters render on the GPU; overuse causes significant GPU memory consumption on mobile
- `mix-blend-mode` only blends the element with what is directly beneath it in the stacking context — `isolation: isolate` on a parent may prevent expected blending
- V4 Transform controls replace V3 Motion Effects static transforms; Motion Effects (scroll-driven) remain in the Advanced tab
