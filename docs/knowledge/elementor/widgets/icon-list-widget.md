---
title: Icon List widget
source_url: https://elementor.com/help/icon-list-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:icon-list]
related_widgets: [icon, icon-box, bullet-list]
---

## Purpose
The Icon List widget displays a repeater-based list where each item pairs an icon with a text label and optional link. It is the go-to widget for feature checklists, contact info rows (phone/email/address), nav link lists, and any content where short labeled icons repeat vertically or horizontally.

## Use this when
- Building feature or benefit checklists (checkmark icons + text)
- Displaying contact details (phone icon + number, envelope icon + email)
- Creating a mini navigation list in a footer column
- Showing step indicators or ordered process lists with numbered icons
- Designing horizontal icon-tag rows (tags, categories) within cards

## Settings highlights
- **Items (Repeater)**: each item has Icon, Text, and Link fields; reorder via drag
- **Layout**: Inline (horizontal) or Default (vertical stacked)
- **Icon Size**: uniform px slider for all icons
- **Icon Color** / **Icon Color Hover**: per icon or globally
- **Text Typography** / **Text Color**: shared across all items; per-item override via Custom CSS
- **Space Between Items**: gap control
- **Divider**: optional horizontal rule between items with color, weight, style controls
- **Alignment**: left, center, right for the whole list
- **Icon Alignment**: flex-start / center / flex-end relative to multi-line text

## Limits / gotchas
- Per-item text color differences require CSS targeting `.elementor-icon-list-item:nth-child(n)`
- Inline layout may wrap awkwardly on mobile — set a responsive column break or switch to vertical on small screens
- Icon List has no built-in animation per item; entrance animations apply to the whole widget
- Cannot mix icon sizes per item without Custom CSS
