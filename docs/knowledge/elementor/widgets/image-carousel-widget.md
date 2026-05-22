---
title: Image Carousel widget
source_url: https://elementor.com/help/image-carousel-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:image-carousel]
related_widgets: [basic-gallery, media-carousel, slides]
---

## Purpose
The Image Carousel widget displays a horizontally scrollable series of images with navigation arrows and optional dots, auto-play, and caption support. It is designed for photo galleries, client logo strips, portfolio previews, or any scenario requiring a multi-image slideshow without full-screen lightbox behavior.

## Use this when
- Showing a portfolio or product photo gallery inline in a page section
- Displaying client logos in a horizontal rotating strip
- Presenting team photos or testimonial headshots in a swipeable row
- Creating an image slideshow within a contained section without the full Slides widget
- Building mobile-friendly image galleries where swipe gesture is expected

## Settings highlights
- **Images**: multi-image picker from Media Library; reorder via drag
- **Slides to Show**: number of images visible simultaneously (1–10)
- **Slides to Scroll**: how many advance per navigation click
- **Image Size**: thumbnail/medium/large/full/custom
- **Caption**: none / title / caption — displayed below or over image
- **Link**: each image links to custom URL, media file, or attachment page
- **Autoplay**: on/off with pause-on-hover and speed (ms) control
- **Pause on Interaction**: stops autoplay when user interacts
- **Arrows / Dots**: navigation control toggles with custom styling
- **Animation Speed**: transition duration in milliseconds
- **Loop**: infinite cycling toggle

## Limits / gotchas
- "Slides to Show > 1" on mobile requires explicit responsive breakpoint overrides — often needs to be set to 1 on mobile to avoid cropping
- Caption styling options are limited; extensive caption customization requires Custom CSS
- Lightbox/zoom requires the Basic Gallery widget or a third-party plugin — Image Carousel does not support native lightbox
