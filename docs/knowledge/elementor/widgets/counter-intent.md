---
title: Counter Widget
source_url: https://elementor.com/widgets/counter-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:counter]
related_widgets: [progress-bar]
---

## Purpose

The Counter Widget enables animated numerical counters that highlight performance metrics and statistics. It displays numbers that count up from a start value to an end value with a configurable animation, drawing attention to impressive achievement numbers in an engaging format. Use it to make key stats pop on hero sections, about pages, or stats bars without custom JavaScript.

## Use this when

- Showcasing key business metrics: client count, projects completed, revenue milestones, years in business
- Emphasizing statistical data in a visually compelling animated way on landing pages
- Creating stats sections that need to capture visitor attention with animated number reveals
- Displaying multiple counters side-by-side in a row (clients / projects / awards / countries)
- Building data visualization sections without requiring custom coding

## Settings highlights

- **Starting number**: The value the counter animates from (usually 0)
- **Ending number**: The target value the counter counts up to
- **Duration**: Animation speed in milliseconds (how long the count-up takes)
- **Prefix**: Text prepended to the number (e.g. `$`, `+`)
- **Suffix**: Text appended to the number (e.g. `K`, `%`, `+`)
- **Title**: Label displayed below the number (e.g. "Happy Clients")
- **Number typography**: Font family, size, weight for the numeric value
- **Title typography**: Separate typography controls for the label text
- **Number color**: Fill color for the animated digit display
- **Title color**: Color for the label text below

## Limits / gotchas

- Animation triggers on scroll-into-view (or page load depending on settings); returning visitors see the animation each time unless caching suppresses the re-render
- Number formatting is global per widget instance; independent format rules per digit require separate widget instances
- Dynamic counter values from custom fields require proper field configuration; incorrect setup results in blank or zero display
- Very large numbers (millions+) need careful suffix/prefix choices to remain readable at chosen font sizes; the widget does not auto-abbreviate
