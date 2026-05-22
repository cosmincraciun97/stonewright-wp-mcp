---
title: Loop Grid widget
source_url: https://elementor.com/help/loop-grid/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:loop-grid]
related_widgets: [loop-carousel, posts, archive-posts, taxonomy-filter]
---

## Purpose
The Loop Grid widget dynamically displays multiple posts, products, or custom post types in a customizable grid layout. It queries and renders content based on specified conditions, allowing designers to create flexible archive pages, product galleries, and content showcases without manual item management. Each item's appearance is defined by a reusable Loop Template.

## Use this when
- Building archive, category, or tag pages that need dynamic content filtering
- Creating product display grids for WooCommerce shops
- Displaying related posts or custom post type collections
- Requiring pagination or load-more functionality for content loops
- Needing to customize which items appear through query builders and taxonomy filters

## Settings highlights
- **Template**: choose which Loop Template defines each card's appearance
- **Query Builder**: define post types, taxonomies, author, date, order for displayed items
- **Columns**: number of grid columns per responsive breakpoint
- **Rows Gap / Columns Gap**: spacing between items
- **Alternate Template**: apply a different Loop Template to specific Nth items (e.g. first item gets hero layout)
- **Pagination**: choose between standard pagination, load-more button, or infinite scroll
- **Taxonomy Filter**: display filterable tag/category buttons allowing visitors to narrow results
- **No Results Message**: custom text/template when query returns empty
- **Off-Canvas Integration**: embed Off-Canvas widgets within loop items for interactive overlays

## Limits / gotchas
- Requires a pre-built Loop Template (Pro only) — cannot define card layout inline
- Complex queries or large datasets impact page performance; set per-page limits and use pagination
- Alternate template assignment is positional (1st, 2nd…) — no conditional logic per item content
- Display conditions apply globally across all instances, not per individual item
