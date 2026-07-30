---
title: Testimonial Widget
source_url: https://elementor.com/widgets/testimonial-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:testimonial]
related_widgets: [testimonial-carousel, reviews]
---

## Purpose

Display customer reviews and testimonials on WordPress sites to build trust and simplify purchasing decisions. The widget presents customer feedback with photos, names, job titles, and quotes to provide authentic social proof that influences visitor confidence. It is the single-item testimonial widget (one quote per instance); for multiple rotating quotes use Testimonial Carousel.

## Use this when

- Showcasing a featured customer success story prominently on a service or product page
- Building a testimonials section with multiple side-by-side single quotes in a grid layout
- Adding social proof to landing pages or checkout pages to reduce purchase hesitation
- Displaying a quote with the reviewer's photo, name, and company for full credibility context
- Pulling testimonials dynamically from custom post types or ACF fields

## Settings highlights

- **Review content**: The quote text (supports HTML inline formatting)
- **Image**: Reviewer photo with size and shape controls (circle, square, custom border-radius)
- **Name**: Reviewer's full name displayed below the quote
- **Job/Title**: Reviewer's role or company for added authority context
- **Link**: Optional URL wrapping the reviewer's name or image
- **Alignment**: Left / center / right alignment for quote and metadata
- **Image size**: Control rendered pixel dimensions of the avatar
- **Typography (content)**: Font controls for the quote text
- **Typography (name/title)**: Separate font controls for the reviewer's name line
- **Color controls**: Independent colors for quote text, name, and title

## Limits / gotchas

- Single testimonial only per widget instance; for carousels or grids use Testimonial Carousel or a loop with a custom template
- Dynamic testimonial sources (CPT + dynamic tags) require proper field setup; manual entry is simpler for one-off implementations
- Image aspect ratios need matching (square photos work best with circular crop); portrait or landscape photos require CSS adjustment to avoid distortion
- Responsive behavior on mobile compresses multi-column testimonial grids; preview across breakpoints before publishing
