---
title: How to fix a missing header or footer in Elementor?
source_url: https://elementor.com/help/how-to-fix-a-missing-header-or-footer-in-elementor/
fetched_at: 2026-05-22T16:00:00Z
content_hash: sha256-pending
applies_to: [theme-builder]
related_widgets: []
---

## Purpose
The Theme Builder is Elementor Pro's system for designing reusable header, footer, and template parts that apply globally across your site. It enables you to create consistent layouts without manually adding them to every page, and control which templates display on specific post types, archives, or conditions.

## Use this when
- You need a consistent header/footer across all pages without duplicating work
- You want different headers for specific post types, categories, or device breakpoints
- You're building archive pages (blog, products, search results) with custom layouts
- You need to create single post/product templates with dynamic content
- You want to apply conditional display rules — showing different headers for logged-in users or specific pages

## Settings highlights
- **Site Parts**: Header, Footer, Single Post/Page, Archive templates all configured independently
- **Display Conditions**: Target templates by post type, taxonomy, URL, user role, and more
- **Sticky Headers**: Lock navigation in place while users scroll with built-in sticky options
- **Theme Styles**: Global typography, colors, and spacing that sync across all templates
- **Responsive Control**: Adjust layouts per device breakpoint (desktop, tablet, mobile)
- **Template Library**: Save custom templates for reuse across projects
- **Dynamic Tags**: Pull post titles, featured images, excerpts automatically into templates
- **Archive Templates**: Customize how blog archives, product listings, and search results appear

## Limits / gotchas
- If header/footer templates don't display, verify conditions are set correctly and no conflicting theme code is hiding them
- Changes to global templates affect all pages matching the conditions — test on staging first
- Some legacy themes may conflict; check compatibility before applying Theme Builder site-wide
