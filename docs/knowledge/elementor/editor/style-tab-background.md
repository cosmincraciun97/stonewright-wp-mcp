---
title: Style tab - Background
source_url: https://elementor.com/help/style-tab-background/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Background section of the V4 Style tab configures background styling for elements including colors, gradients, images, and layered effects. This V4 section consolidates background properties using atomic schema-based controls instead of legacy V3 widget panel groupings, and supports multi-layer stacking via the layered background system.

## Use this when

- Applying solid colors or gradients to element or container backgrounds
- Adding background images with positioning and sizing options
- Creating layered backgrounds with multiple visual effects (color + image overlay)
- Adjusting background behavior on responsive breakpoints (different image per device)
- Controlling opacity and blend modes for background layers

## Settings highlights

- **Color picker** — solid color with global color integration; RGBA alpha channel support
- **Gradient builder** — linear and radial gradient types with multi-stop color pickers and angle/position controls
- **Background image** — Media Library picker with `background-position` (X/Y), `background-size` (cover/contain/custom px), `background-repeat`, `background-attachment` (scroll/fixed)
- **Layered backgrounds** — stack multiple color and image layers; each layer has independent opacity and blend mode
- **Background hover** — separate Background state for hover via element states switcher
- **Blend mode** — per-layer CSS mix-blend-mode for creative compositing effects
- **Opacity** — element-level opacity (affects entire element including children) vs. layer-level alpha (background only)
- **Responsive** — all background props have per-breakpoint overrides; swap images for different devices

## Limits / gotchas

- V4 separates background from the "Advanced" tab that V3 used; background is always in the Style tab
- Background video (YouTube or self-hosted MP4) is a separate layer type; iOS does not autoplay background videos — provide a fallback image layer
- Layered backgrounds may have paint performance impact on mobile; limit to 2–3 layers maximum
- `background-attachment: fixed` (parallax) does not work on iOS Safari; test fallback appearance
