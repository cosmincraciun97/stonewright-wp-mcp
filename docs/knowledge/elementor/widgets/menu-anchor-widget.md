---
title: Menu Anchor widget
source_url: https://elementor.com/help/menu-anchor-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:menu-anchor]
related_widgets: [nav-menu, button, scroll-snap]
---

## Purpose
The Menu Anchor widget creates an invisible anchor point at a specific location on a page. When a navigation link or button targets the anchor's ID (e.g. `#services`), the browser scrolls the viewport to that element. It is the standard mechanism for one-page navigation, table-of-contents links, and in-page smooth scrolling in Elementor.

## Use this when
- Building single-page websites with a sticky nav menu that jumps to sections
- Creating a table of contents widget at the top of a long article
- Linking from a button or text to a specific page section below
- Implementing back-to-top targets (place anchor at the very top of the page)
- Setting scroll destinations for Elementor's smooth scroll feature

## Settings highlights
- **ID (Anchor Name)**: unique identifier without `#` prefix (e.g. `services`, `contact`, `about`)
- The widget renders as an invisible zero-height element — it takes up no visual space
- Place the widget immediately above the section it should scroll to
- Link to it from any URL field using `#anchor-name` syntax (same page) or `url#anchor-name` (cross-page)
- Works with Elementor smooth scroll (enable in Elementor Settings > Style > Page Scroll)
- Compatible with the Nav Menu widget's menu items if menu items link to `#id`

## Limits / gotchas
- Sticky headers offset the scroll destination — the anchor lands behind a sticky header; compensate with a negative `margin-top` or CSS scroll-margin-top on the anchor element
- Duplicate anchor IDs on the same page cause unpredictable scroll targets — IDs must be unique
- Smooth scrolling depends on Elementor's smooth scroll setting or CSS `scroll-behavior: smooth` on the `html` element
- Cross-page anchor links (`page-url/#section`) require the target page to load first, then scroll — cannot animate across page loads
