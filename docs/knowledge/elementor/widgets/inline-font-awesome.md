---
title: Inline Font Icons
source_url: https://elementor.com/help/inline-font-awesome/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:inline-font-icons]
related_widgets: [icon, text-editor, heading]
---

## Purpose
Inline Font Icons enables Font Awesome icon glyphs to be embedded directly within text widgets (Heading, Text Editor) using a shortcode-like syntax. This allows a single icon to appear mid-sentence or as a leading inline element without placing a separate Icon widget in the layout.

## Use this when
- You need an icon inline within a sentence in a Heading or Text Editor widget
- Building bullet-point text with leading icons inside rich text without an Icon List widget
- Adding a small decorative glyph (star, arrow, checkmark) inline next to text
- Creating icon + text combos without the overhead of additional widget nesting

## Settings highlights
- **Activation**: enable the "Inline Icon" experiment in Elementor > Experiments or via Editor > Settings
- **Syntax**: insert icon via the icon picker inside the Text Editor toolbar (inline icon button)
- **Size**: inherits from surrounding text font-size; can be overridden with `font-size` CSS on the icon span
- **Color**: inherits text color by default; override with inline `style` or Custom CSS
- **Font Awesome classes**: rendered as `<i class="fa fa-..."></i>` inline within the paragraph
- Works in Heading widget and Text Editor widget; not available in all widget text fields
- Accessible via the TinyMCE toolbar or inline editing toolbar

## Limits / gotchas
- Requires the Inline Icon experiment to be enabled; not on by default in all versions
- Icon rendering depends on Font Awesome font files loading — if the icon font is disabled, icons show as empty boxes
- Cannot use SVG icons inline this way; only icon-font glyphs (Font Awesome classes) are supported
- Inline icon size cannot be set independently through the GUI — CSS required for size differences
