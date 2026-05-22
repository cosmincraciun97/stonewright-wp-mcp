---
title: Image widget
source_url: https://elementor.com/help/image-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:image]
related_widgets: [image-box, image-carousel, basic-gallery]
---

## Purpose
The Image widget places a single image from the Media Library onto the page with full control over size, alignment, caption, link target, hover effects, and CSS filters. It is the most fundamental media widget and the baseline for all image-related patterns in Elementor.

## Use this when
- Displaying a standalone photo, illustration, or graphic within a layout
- Placing a logo or brand mark in a container
- Creating a clickable image that links to a URL, lightbox, or attachment page
- Adding hover effects (opacity, CSS filter, scale) to a photo
- Using dynamic image tags to pull featured images or ACF image fields

## Settings highlights
- **Image**: Media Library selector with alt text override
- **Image Size**: thumbnail / medium / large / full / custom (width × height with crop)
- **Alignment**: left / center / right
- **Caption**: none / attachment / custom text
- **Link To**: none / custom URL / media file / attachment page; lightbox toggle
- **Hover Animation**: built-in CSS transition presets
- **Opacity**: normal and hover state
- **CSS Filters**: brightness, contrast, saturation, hue-rotate, blur — normal and hover states
- **Width / Max Width**: responsive sizing controls
- **Border Radius**: round corners on image directly

## Limits / gotchas
- Lazy loading is controlled by WordPress core and browser; Elementor does not override it on this widget
- Custom image size dimensions require the size to exist in WordPress (registered via `add_image_size` or via media settings) — "Custom" in the picker generates only a scaled inline style
- Lightbox opens in Elementor's built-in lightbox; cannot swap to a third-party lightbox without a plugin
