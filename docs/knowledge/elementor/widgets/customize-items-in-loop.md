---
title: Customize which items appearing your loop
source_url: https://elementor.com/help/customize-items-in-loop/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:loop-grid]
related_widgets: [loop-grid, loop-carousel]
---

## Purpose
Control which items display in your loop by filtering and customizing query results. This feature allows you to selectively show or hide specific posts based on criteria like category, tags, custom fields, and other parameters without manually editing individual items.

## Use this when
- You need to display only certain posts in a Loop Grid or Loop Carousel widget
- You want to exclude specific content types or taxonomies from appearing
- You're building filtered archive pages that show relevant items to visitors
- You need dynamic control over which posts render in repetitive layouts
- Creating category or tag pages with customized item visibility

## Settings highlights
- Query builder with post type and taxonomy filtering options
- Include/exclude controls for specific categories and tags
- Custom field filtering for advanced conditional display
- Offset and posts per page limits to manage query results
- Dynamic tag integration for contextual item selection
- Order and sort parameters (date, title, custom fields)
- Advanced rules engine for complex filtering scenarios

## Limits / gotchas
- Performance may degrade with overly complex query filters on large post libraries
- Custom field filters require properly configured ACF or similar plugin fields
- Pagination behavior depends on correctly set offset and per-page values; misconfigurations can skip or duplicate items
