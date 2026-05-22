---
title: Add custom CSS
source_url: https://elementor.com/help/how-to-add-custom-css/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v3, editor:v4]
related_widgets: []
---

## Purpose
Custom CSS allows you to apply specialized styling rules to specific elements, sections, or entire pages in Elementor without modifying the global stylesheet. This feature enables fine-grained design control and supports advanced styling techniques beyond the visual builder's standard options.

## Use this when
- You need to apply unique styling to individual elements not available in standard controls
- Implementing CSS animations, transforms, or advanced effects beyond the builder interface
- Creating responsive designs with media queries for specific breakpoints
- Adding vendor-specific properties or experimental CSS features
- Extending styling from global CSS to particular page sections or widgets

## Settings highlights
- **Advanced Tab**: Access custom CSS through the element's Advanced panel settings
- **Element-level Application**: Write CSS targeting the specific widget or container class
- **CSS Selectors**: Use proper selectors to target elements (`.elementor-widget-*`, custom IDs, classes)
- **Responsive Rules**: Include media queries to adjust styles across different device sizes
- **Specificity Management**: Balance CSS specificity to override default styles without conflicts
- **Preprocessor Support**: Some installations support SCSS or LESS syntax depending on setup
- **Code Editor**: Dedicated editor with syntax highlighting for writing and debugging CSS

## Limits / gotchas
- Custom CSS requires foundational CSS knowledge; improper syntax can break element styling
- Mobile responsiveness must be explicitly coded; visual preview updates may require page refresh
- Browser compatibility varies; test across target browsers for vendor-specific properties
- Performance impact increases with complex selectors or heavy animations on large pages
