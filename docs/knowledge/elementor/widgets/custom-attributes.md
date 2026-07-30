---
title: Custom attributes
source_url: https://elementor.com/help/custom-attributes-pro/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:custom-attributes]
related_widgets: []
---

## Purpose
Custom attributes allow developers to add HTML attributes to wrappers of sections, columns, and widgets in Elementor Pro. This feature enables deeper customization and integration with third-party tools, JavaScript frameworks, and data attributes without modifying code directly.

## Use this when
- You need to attach data attributes for JavaScript functionality
- Integrating with third-party libraries or analytics tools
- Adding ARIA attributes for accessibility improvements
- Creating custom styling hooks via CSS selectors
- Building dynamic interactions requiring element identification

## Settings highlights
- Access custom attributes panel in Advanced tab of sections, columns, widgets
- Add multiple attributes simultaneously to single elements
- Define attribute names and their corresponding values
- Support for data attributes (data-*), ARIA attributes, and standard HTML attributes
- Attributes apply to element wrappers, not inner content
- Preview attribute implementation before publishing

## Limits / gotchas
- Custom attributes only available in Elementor Pro (premium feature)
- Attributes added to wrapper elements, not the widgets themselves in some contexts
- Requires understanding of HTML attribute syntax and target integration requirements
- Invalid attribute syntax may cause rendering issues or be stripped by security filters
