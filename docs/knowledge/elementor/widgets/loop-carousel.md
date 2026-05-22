---
title: Loop Carousel
source_url: https://elementor.com/help/loop-carousel/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:loop-carousel]
related_widgets: [loop-grid, carousel, posts, media-carousel]
---

## Purpose
The Loop Carousel widget displays dynamically queried posts, products, or custom post type items in a horizontally scrollable carousel, using a reusable Loop Template to define each item's appearance. It combines the Loop Grid's query-builder power with a swipeable carousel UX — ideal for featured posts, product spotlights, and testimonial carousels populated from real WordPress content.

## Use this when
- Showing recent blog posts in a swipeable row instead of a static grid
- Creating a WooCommerce product spotlight carousel driven by query (featured, on-sale, category)
- Building a testimonials carousel backed by a CPT where content editors add entries
- Displaying related items in a single-post sidebar carousel

## Settings highlights
- **Template**: choose which Loop Template defines the card/item design
- **Query Builder**: post type, taxonomy filter, author, date range, order by
- **Slides to Show**: number of cards visible simultaneously per breakpoint
- **Slides to Scroll**: cards advanced per swipe/click
- **Autoplay** / **Autoplay Speed**: cycling interval in ms with pause-on-hover
- **Loop**: infinite cycling toggle
- **Navigation Arrows / Dots**: position, size, color styling
- **Spacing (Gap)**: gap between cards
- **Pagination**: none (carousel handles navigation via arrows/dots)

## Limits / gotchas
- Requires a pre-built Loop Template — cannot define card layout inline; must create template first in Theme Builder
- Requires Elementor Pro (Loop Items and Loop Templates are Pro features)
- Complex queries on large databases may slow initial render; set reasonable per-page limits
- Autoplay combined with CSS animations inside cards can cause visual glitches — test thoroughly
