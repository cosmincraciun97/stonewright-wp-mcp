---
title: Media Carousel widget
source_url: https://elementor.com/help/media-carousel-widget-pro/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:media-carousel]
related_widgets: [image-carousel, testimonial-carousel, slides, gallery]
---

## Purpose
The Media Carousel widget (Elementor Pro) displays a mixed-media slideshow combining images and video items in a single swipeable carousel. Each slide can be an image, a video embed (YouTube, Vimeo, self-hosted), or a custom content block, with full lightbox support for expanding media on click.

## Use this when
- Creating a portfolio or press gallery that mixes photos and video embeds
- Building a product showcase where images and a demo video coexist in one carousel
- Designing testimonial slideshows that include both quote cards and video testimonials
- Presenting mixed media assets (event photos + highlight reel) in a single continuous slider

## Settings highlights
- **Slide Type**: image or video per individual slide
- **Image Size**: thumbnail / medium / large / full / custom
- **Video Source**: YouTube, Vimeo, or self-hosted file URL
- **Autoplay** / **Autoplay Speed**: cycling interval; auto-stops on interaction
- **Pause on Hover**: halts autoplay when cursor is over carousel
- **Loop**: infinite cycling
- **Slides to Show**: number of visible slides simultaneously
- **Lightbox**: enable full-screen lightbox for image/video on click
- **Navigation Arrows / Dots**: toggle, position, size, and color
- **Ken Burns Effect**: subtle pan/zoom on image slides for cinematic feel

## Limits / gotchas
- Requires Elementor Pro
- Video slides do not autoplay with sound in browsers by default (user gesture required); muted autoplay requires explicit `muted` attribute via Custom HTML
- Ken Burns and other image animation effects increase GPU compositing cost on mobile
- Lightbox video playback on iOS Safari has known limitations (full-screen restrictions)
