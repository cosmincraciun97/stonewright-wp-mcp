---
title: Inner Section widget
source_url: https://elementor.com/help/inner-section-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:inner-section]
related_widgets: [section, column, container]
---

## Purpose
The Inner Section widget (also called "Inner Columns") allows nesting a multi-column section inside an existing column, creating multi-level column layouts within the legacy sections-and-columns architecture. It is the V3 answer to complex layouts that containers handle natively in V4.

## Use this when
- Working within the legacy V3 sections/columns editor and need a column-within-a-column layout
- Building a two-column section where one column itself splits into sub-columns
- Creating sidebar + main content layouts with nested structure
- Migrating older pages that use inner sections before converting to Flexbox containers

## Settings highlights
- **Columns**: set the number of columns within the inner section (1–10)
- **Column Width**: adjustable via drag handles or percentage input per column
- **Gap**: column gap control (default, narrow, extended, wide, no gap)
- **Vertical Align**: top, middle, bottom alignment of column content
- **Background / Background Overlay**: color, image, gradient, video options on the inner section wrapper
- **Border Type / Radius / Shadow**: standard box model controls
- **Motion Effects, Responsive Visibility, Custom CSS**: all standard Advanced tab features apply

## Limits / gotchas
- Inner Section is a V3 legacy widget; Flexbox Containers supersede it for new builds — use containers for all new projects
- Nesting inner sections more than 2 levels deep creates editor performance issues and is unsupported
- Inner Section does not support the Container layout controls (flex-direction, justify-content, align-items)
- Cannot convert a V3 Inner Section to a Container via the automatic conversion tool — must be rebuilt
