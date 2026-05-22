---
title: Tabs Element
source_url: https://elementor.com/help/tabs-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Tabs element is a V4 atomic element for organizing content into switchable tab panels. Unlike the V3 Tabs widget (which required all content to be defined inside widget control fields), the V4 Tabs element uses nested atomic elements as tab panel content — any V4 element (Heading, Paragraph, Image, Flexbox) can be placed inside each tab panel as a true nested element.

## Use this when

- Organizing multiple content sections (features, FAQs, pricing tiers) into horizontally switchable tabs
- Building product detail pages with tabs for Description, Specifications, Reviews
- Creating tabbed navigation for service or portfolio categories
- Replacing V3 Tabs widget for pages being migrated to V4 atomic architecture

## Settings highlights

- **tab items** prop — array of tab label/panel pairs; labels are plain text (support dynamic tags)
- **active_tab** prop — index of the initially active tab (0-based)
- **Nested panel content** — each tab panel is a full container accepting any atomic element; drag-and-drop in the canvas
- **Tab labels style** — Typography, Color, Background, Border per tab label; separate active-state overrides
- **Panel style** — Background, Border, Padding applied to the panel wrapper
- **Orientation** — horizontal (top) or vertical (side) tab layout
- **Transition** — panel switch animation (fade, slide); duration configurable
- **Accessibility** — generates ARIA `role="tablist"`, `role="tab"`, `role="tabpanel"`, `aria-selected` automatically
- **Responsive** — tab labels can collapse to accordion on mobile breakpoint

## Limits / gotchas

- V4 Tabs element's nested content model is fundamentally different from V3 Tabs widget where content was a textarea; existing V3 Tabs widgets don't auto-migrate
- Very deep nesting inside tab panels (Flexbox > Grid > elements) can cause reflow performance issues on tab switch
- Tab panel lazy loading (only rendering active tab's content) is not supported; all panels render in DOM, hidden with CSS
- Keyboard navigation (arrow keys between tabs) follows ARIA authoring practices but may need custom focus styling for visual clarity
