---
title: Add and delete attributes
source_url: https://elementor.com/help/add-and-delete-attributes/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

This feature enables managing custom HTML attributes on elements in Elementor Editor V4, enabling developers to add data attributes, ARIA labels, and other custom properties directly to rendered elements for enhanced functionality and accessibility. Attributes are set via the Advanced tab without requiring custom code.

## Use this when

- You need to attach custom data attributes to elements for JavaScript interactions
- Adding ARIA attributes for improved accessibility compliance
- Implementing microdata or schema markup on specific elements
- Working with third-party scripts that require custom HTML attributes
- Building dynamic interactions that depend on element-level metadata

## Settings highlights

- Access via the **Advanced tab** → Attributes section in element settings
- Add custom **key-value pairs** without requiring custom CSS or code snippets
- Support for standard HTML attributes and `data-*` prefixed custom attributes
- Real-time preview of attributes applied to rendered elements in the canvas
- **Delete** individual attributes with single-click removal (trash icon)
- Multiple attributes can be added per element
- Dynamic tags can be used as attribute values (e.g. `data-id` → post ID dynamic tag)
- Attributes persist across element style updates and revisions

## Limits / gotchas

- V4's atomic element architecture applies attributes to the element's root node, not nested child elements; use custom CSS selectors if deeper targeting is needed
- Custom attributes cannot override core Elementor functionality or protected HTML properties (e.g. `class`, `id` are managed separately)
- Attribute values require proper escaping to prevent breaking element rendering
- Some reserved attributes may be ignored or conflict with Elementor's internal property system
