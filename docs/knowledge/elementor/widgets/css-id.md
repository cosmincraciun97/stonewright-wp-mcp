---
title: CSS ID
source_url: https://elementor.com/help/css-id/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v3, editor:v4]
related_widgets: []
---

## Purpose
CSS IDs are unique identifiers assigned to individual elements in Elementor, enabling targeted styling and functionality through custom CSS selectors. Each element can have one ID that distinguishes it from all other page elements, allowing developers to apply specific design rules and JavaScript interactions to that singular element.

## Use this when
- Creating custom CSS rules for a single, specific element on your page
- Setting up anchor links to jump to particular sections or elements
- Targeting an element with JavaScript for interactive functionality
- Building advanced styling that requires element-level precision
- Implementing display conditions tied to specific element identification

## Settings highlights
- ID field located in the Advanced tab of element settings
- Must follow CSS naming conventions (alphanumeric, hyphens, underscores)
- Appears as a unique attribute in the rendered HTML markup
- Can be combined with CSS classes for layered styling approaches
- Integrates with custom CSS tabs for element-specific declarations
- Compatible with anchor link widgets and menu navigation systems
- Supports dynamic functionality through custom code implementation

## Limits / gotchas
- Each ID must be unique per page; duplicating IDs creates invalid HTML
- IDs have higher CSS specificity than classes, potentially overriding broader styles
- Changing an ID after publication may break existing anchor links or custom code references
