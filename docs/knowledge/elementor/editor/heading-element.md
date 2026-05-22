---
title: Heading element
source_url: https://elementor.com/help/heading-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Heading element is a V4 atomic element for rendering semantic heading text (H1–H6) with full typography and style control via the unified Style tab. It replaces the V3 Heading widget with an atomic prop schema where text, tag, link, and all visual properties are defined as individual prop keys rather than grouped control sections.

## Use this when

- Adding a page title, section title, or sub-heading to any Flexbox/Grid/Div Block container
- Assigning the correct semantic HTML heading level (H1 for main page title, H2 for sections, H3 for subsections)
- Applying typography styles (font, size, weight, transform) that match the design system
- Dynamically binding the heading text to a post title or custom field via dynamic tags
- Creating hover-state heading color changes via element states

## Settings highlights

- **text** prop — heading text content; supports inline dynamic tag binding
- **tag** prop — `h1`, `h2`, `h3`, `h4`, `h5`, `h6` (semantic HTML output)
- **link** prop — optional URL, anchor, or dynamic tag to wrap heading in `<a>` tag
- **Typography** — font-family, font-size, font-weight, font-style, line-height, letter-spacing, text-transform, text-decoration
- **Color** — text color with global color integration and state-aware overrides
- **Text Shadow** — offset X/Y, blur, color for shadow effects
- **Text Stroke** — border on text characters (V4 feature, not available in V3)
- **Alignment** — left, center, right, justify with per-breakpoint responsive overrides
- **Element states** — separate color/shadow/typography per Normal / Hover / Active

## Limits / gotchas

- Only one H1 should exist per page for SEO; use H2 or H3 for section titles
- V4 Heading element does not support the V3 "HTML tag" dropdown that showed inside the Content tab; tag is now in General tab
- Dynamic tag binding on the `text` prop replaces the entire heading content; mixing static and dynamic text requires nested span via custom HTML
- Text Stroke is a V4-only feature; it will not render in older browser versions that predate `webkit-text-stroke` support
