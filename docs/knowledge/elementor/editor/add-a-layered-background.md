---
title: Add a layered background
source_url: https://elementor.com/help/add-a-layered-background/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The layered background feature in Elementor V4 allows stacking multiple background types (color, gradient, image, video) on a single element. Each layer has independent opacity, blend mode, and positioning controls, enabling complex visual effects without custom CSS or external image editing.

## Use this when

- Combining a brand color overlay with a photo background
- Creating gradient-over-image effects for hero sections
- Stacking multiple semi-transparent images for depth effects
- Adding a solid color fallback beneath a video background
- Building textured overlays using pattern images over gradients

## Settings highlights

- **Add Layer button** — appends additional background layers above existing ones; processed top-to-bottom (painter's model)
- **Layer types** — Classic (solid color), Gradient (linear/radial), Image (with full position/size/repeat controls), Video (YouTube/self-hosted)
- **Layer opacity** — per-layer alpha slider independent of element opacity
- **Blend mode** — per-layer CSS blend mode (multiply, screen, overlay, luminosity, etc.)
- **Image position/size** — `background-position` (X/Y), `background-size` (cover/contain/custom), `background-repeat`
- **Attachment** — Fixed (parallax-like scroll) or Scroll for image layers
- **Layer order** — drag to reorder; top of list = topmost visual layer
- **Responsive** — each layer's visibility and properties have per-breakpoint overrides

## Limits / gotchas

- Video backgrounds do not play on iOS due to browser autoplay restrictions; provide image fallback layer
- Too many layers (5+) significantly increase paint complexity and GPU memory usage
- Blend modes apply between layers and between the element and page backdrop; test on actual background colors
- V4 layered backgrounds use atomic `backgroundLayers` prop array — differs structurally from V3 single-background controls
