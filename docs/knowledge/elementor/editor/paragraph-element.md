---
title: Paragraph element
source_url: https://elementor.com/help/paragraph-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Paragraph element in Elementor Editor V4 is a fundamental atomic component for adding and styling text content within pages. It renders a `<p>` tag with streamlined controls for typography, spacing, and visual effects tailored to the V4 architecture's modern atomic prop schema rather than the V3 Text Editor widget control groups.

## Use this when

- Adding body text, descriptions, or longer-form copy to a page or template
- Applying consistent typography styling with global font controls and design tokens
- Requiring responsive text sizing across different device breakpoints
- Building with V4's atomic element structure rather than the V3 Text Editor widget
- Layering text effects like shadows, transforms, or advanced styling via element states

## Settings highlights

- **text** prop — paragraph text content; supports inline dynamic tag binding for post excerpt or custom field values
- **Typography** — font-family, font-size, font-weight, font-style, line-height, letter-spacing, text-transform, text-decoration
- **Color** — text color with global color picker and element-state-aware overrides
- **Text Shadow** — offset X/Y, blur, color
- **Alignment** — left, center, right, justify; per-breakpoint responsive
- **Spacing** — Padding and Margin via atomic props (`paddingTop/Right/Bottom/Left`)
- **Advanced effects** — CSS transforms (scale, rotate, translate) and CSS filters
- **Element states** — hover color, hover shadow, hover transform without custom CSS
- **Dynamic content** — bind text to post excerpt, custom field, or any text-returning dynamic tag
- **Z-index** — layering control for overlapping text over images

## Limits / gotchas

- V4 Paragraph element does not include a WYSIWYG editor; for rich text with inline bold/italic/links use the Text Editor widget instead
- Custom CSS targeting in V4 requires understanding V4's generated class structures (not `.elementor-text-editor`)
- Certain text-wrapping behaviors differ between V3 containers and V4 Flexbox element layouts; check `overflow` on parent
- Nested link functionality in the paragraph has distinct handling requirements in V4 compared to V3
