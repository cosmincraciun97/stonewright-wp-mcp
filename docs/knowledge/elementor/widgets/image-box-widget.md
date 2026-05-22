---
title: Image Box widget
source_url: https://elementor.com/help/image-box-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:image-box]
related_widgets: [image, icon-box, call-to-action]
---

## Purpose
The Image Box widget combines an image with a title and description in a single linkable unit. It is the image-based equivalent of the Icon Box — used for team member cards, portfolio thumbnails, service tiles, or any pattern where a photo, a heading, and a brief text appear together as a clickable or static block.

## Use this when
- Building team member / staff profile grids (photo + name + role)
- Creating service or product tiles with a representative image
- Designing portfolio card layouts with caption text
- Building category showcase blocks with image + category name + excerpt
- Any pattern needing image + title + description as an atomic card unit

## Settings highlights
- **Image**: Media Library picker with size selector (thumbnail/medium/large/full/custom)
- **Title & Description**: separate text fields; title has HTML tag selector (h2–h6, div, span)
- **Link To**: URL applied to the whole box; same/new tab control
- **Image Position**: top (default), left, right, bottom — controls image placement relative to text
- **Image Width** (when position is left/right): percentage slider
- **Image Alignment**: left, center, right for top/bottom positions
- **Image Size**: crop/scale dimension controls
- **Title Typography / Color**: full font stack controls per state
- **Description Typography / Color**: independent from title
- **Hover Animation**: image and box hover transition presets

## Limits / gotchas
- The entire box is wrapped in a single link — individual elements (image only, or title only) cannot have separate links without Custom CSS
- Image dimensions should be consistent across a grid; mismatched image ratios break grid alignment — use a fixed image size setting or CSS `object-fit: cover`
- Left/right image positions stack vertically on mobile automatically, but text alignment may need explicit responsive adjustments
