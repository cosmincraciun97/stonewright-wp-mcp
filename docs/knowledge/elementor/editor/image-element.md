---
title: Image element
source_url: https://elementor.com/help/image-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Image element is a V4 atomic element for rendering a single image with full control over source, sizing, link, alt text, caption, and style properties. Unlike the V3 Image widget it uses atomic props (`src`, `width`, `height`, `objectFit`, `objectPosition`) instead of grouped control sections, enabling precise CSS-driven image behavior within Flexbox and Grid containers.

## Use this when

- Adding hero images, product shots, team photos, or decorative graphics to a layout
- Displaying dynamic featured images in Loop templates via dynamic tag binding on the `src` prop
- Creating clickable image links (lightbox, external URL, or anchor)
- Controlling image aspect ratio via `objectFit: cover` within a fixed-size container
- Applying hover effects (scale, opacity, CSS filter) via element states

## Settings highlights

- **src** prop — image URL or media library picker; supports dynamic tags (Featured Image, ACF Image Field)
- **alt** prop — alternative text for accessibility and SEO; supports dynamic tags
- **link** prop — URL, lightbox, or dynamic tag; optional `target: _blank`
- **caption** prop — rendered below image; optional
- **width / height** props — explicit dimensions in px or % (affects rendered `<img>` size)
- **objectFit** prop — `fill`, `contain`, `cover`, `none`, `scale-down` (controls image within its box)
- **objectPosition** prop — X/Y focal point for `cover` cropping (e.g. `50% 20%` keeps top portion visible)
- **Style tab** — Border, Border Radius, Box Shadow, Opacity, CSS Filters, Blend Mode
- **Element states** — hover opacity, hover CSS filter (grayscale/blur), hover scale via Transform

## Limits / gotchas

- Lazy loading is applied by default via `loading="lazy"`; above-the-fold hero images should have `loading="eager"` set via custom attribute
- V4 Image element uses `objectFit` for cropping — V3 Image widget used a separate "Image Size" dropdown controlling WordPress thumbnail sizes; these are different mechanisms
- Large original image files are served unless you configure WordPress image sizes and select the appropriate size in the `src` picker
- SVG files need SVG support enabled in Elementor settings; use the SVG element for inline SVGs
