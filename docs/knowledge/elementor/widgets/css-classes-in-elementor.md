---
title: CSS classes in Elementor
source_url: https://elementor.com/help/css-classes-in-elementor/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v3, editor:v4]
related_widgets: []
---

## Purpose
CSS classes allow you to assign custom identifiers to elements within Elementor, enabling targeted styling through custom CSS. This feature connects elements to external stylesheets or inline CSS rules, providing developers and designers greater control over element appearance and behavior without modifying element settings directly.

## Use this when
- You need to apply consistent styling across multiple elements on different pages
- Integrating custom CSS that targets specific components
- Leveraging third-party CSS frameworks or libraries
- Creating reusable design patterns within your site
- Building complex layouts requiring precise style control

## Settings highlights
- Access CSS class fields in the "Advanced" tab of element settings
- Add multiple classes to a single element by separating with spaces
- Classes follow standard CSS naming conventions (alphanumeric, hyphens, underscores)
- Classes remain tied to elements across page edits and updates
- Global classes can be exported and imported across sites
- Class Manager available in the advanced settings interface
- Compatible with custom CSS editor for targeted rule application

## Limits / gotchas
- Class names must be unique identifiers; duplicates don't create issues but waste specificity
- CSS classes alone don't affect layout — they require accompanying CSS rules to function
- Changes to class names require manual updates if CSS rules reference old names
- Class inheritance doesn't apply; child elements won't automatically inherit parent classes
