---
title: Menu widget
source_url: https://elementor.com/help/the-menu-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:menu]
related_widgets: [nav-menu, menu-anchor, menu-cart]
---

## Purpose
The Menu widget (distinct from Nav Menu Pro) displays a WordPress navigation menu registered in the Customizer within an Elementor-built section. It allows flexible menu placement and styling beyond the default theme header area — suitable for footer menus, sidebar navigation, inline text menus, and mobile drawer contents.

## Use this when
- Displaying a secondary navigation menu (footer links, legal links, category nav) in a custom Elementor layout
- Placing a site menu inside a Popup or Off-Canvas widget for a mobile drawer pattern
- Building a simple horizontal or vertical nav without needing the full Nav Menu Pro widget
- Embedding a WordPress menu in a Flexbox container for precise positioning alongside other elements

## Settings highlights
- **Menu**: dropdown selector for any WordPress menu created in Appearance > Menus
- **Layout**: Horizontal or Vertical orientation
- **Alignment**: left, center, right
- **Pointer**: highlight style for active/hovered menu items (underline, overline, framed, filled, text)
- **Item Spacing**: gap between menu items
- **Typography** / **Color** / **Hover Color** / **Active Color**: per-state styling
- **Responsive Toggle**: show/hide hamburger icon on mobile with dropdown/flyout behavior
- **Submenu Indicator**: arrow or custom icon indicating items with children

## Limits / gotchas
- Requires a WordPress menu pre-created in the WP admin — cannot build menu items from within Elementor
- Advanced mega-menu layouts require the Nav Menu Pro widget (Elementor Pro), not this basic Menu widget
- Mobile hamburger toggle is basic — for fully styled mobile menus use Nav Menu Pro or an Off-Canvas widget
- Menu item styling (font, color) is global for the widget instance — per-item styling requires Custom CSS targeting `:nth-child`
