---
title: Icon widget
source_url: https://elementor.com/help/icon-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:icon]
related_widgets: [icon-box, icon-list, social-icons]
---

## Purpose
The Icon widget displays a single icon from Elementor's icon library (Font Awesome or custom sets) with full style control over size, color, background, border, and hover state. It is used for standalone decorative icons, accent marks, and inline icon-only buttons or links.

## Use this when
- Placing a single decorative icon as a section accent or separator
- Creating icon-only buttons or link targets (e.g. a search icon that opens a modal)
- Building social-media-style icon rows without the Social Icons widget's predefined network list
- Adding checkmarks, arrows, or symbols as inline visual cues within text areas

## Settings highlights
- **Icon**: picker with search, from any active icon library or uploaded SVG
- **Link**: URL wrapping the icon in an `<a>` tag; target (same/new tab) and rel controls
- **Size**: px slider (applies to font-size of the icon glyph)
- **Color**: fill color in normal and hover states
- **Icon Background Color**: solid or gradient behind the icon
- **Icon Background Type**: none / circle / square
- **Padding**: space between icon glyph and background shape
- **Border Type / Width / Color / Radius**: full border control
- **Hover Animation**: built-in presets (grow, float, pulse, etc.)
- **Rotate / Flip**: transform controls for orientation

## Limits / gotchas
- Icon widget does not include a text label — use Icon Box for icon + title + description combinations
- SVG icons render inline and respect CSS `fill`; icon-font icons respond to `color` — mixing both in a list requires separate styling
- Very small icons (under 16px) may lose legibility on retina displays if using icon fonts
