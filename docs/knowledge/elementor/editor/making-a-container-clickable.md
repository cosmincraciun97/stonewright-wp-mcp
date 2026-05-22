---
title: Make a Flexbox container clickable
source_url: https://elementor.com/help/making-a-container-clickable/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v3]
related_widgets: []
---

## Purpose
Make a Flexbox container function as a clickable link element, enabling the entire container and its contents to trigger navigation when clicked. This feature transforms containers into interactive components that behave like buttons or links while maintaining their layout structure.

## Use this when
- You want an entire card or section to be clickable rather than just a button within it
- Creating clickable product cards, service boxes, or portfolio items
- Building interactive navigation elements or tile-based layouts
- Designing call-to-action sections that need full-area click targets
- You need better mobile usability by expanding clickable surface area

## Settings highlights
- Container must be a Flexbox type to access this feature
- Link URL field in the container's advanced settings panel
- Option to open links in new tabs or same window
- "nofollow" attribute toggle for SEO control
- Custom CSS classes can be applied to the clickable container
- Interaction states (hover effects) available for visual feedback
- Z-index management for layered content within container
- Title/ARIA attributes for accessibility compliance

## Limits / gotchas
- Nested interactive elements (buttons, links) may conflict with container click behavior
- Some browser compatibility considerations with flex layouts on mobile devices
- Container must have defined dimensions for reliable click targeting
- Cannot combine clickable container with certain display conditions without testing
