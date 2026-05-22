---
title: Lottie widget
source_url: https://elementor.com/help/lottie-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:lottie]
related_widgets: [image, animated-headline, video]
---

## Purpose
The Lottie widget renders JSON-based Lottie animations (from LottieFiles or custom exports from After Effects via Bodymovin) directly on the page. It supports autoplay, loop, scroll-triggered playback, and hover-triggered playback, enabling high-quality vector animations without video file overhead.

## Use this when
- Placing animated illustrations (product explainers, icon animations, loading states) on a page
- Adding scroll-triggered animation that plays as the element enters the viewport
- Creating hover-activated micro-animations on feature cards or CTA sections
- Replacing heavy GIF or MP4 animations with lightweight scalable JSON animations
- Building interactive animated icons that respond to user scroll or mouse events

## Settings highlights
- **Source**: upload JSON file from Media Library, or external URL (e.g. LottieFiles CDN)
- **Start Frame / End Frame**: trim the animation to a specific range
- **Loop**: replay indefinitely or once
- **Autoplay**: start on page load vs. on scroll/hover trigger
- **Speed**: playback multiplier (0.1×–3×)
- **Reverse**: play animation in reverse direction
- **Trigger**: viewport enter, scroll progress, or hover interaction
- **Scroll Scrub**: map animation frames to scroll position (scroll-driven animation)
- **Link**: wrap animation in an `<a>` tag

## Limits / gotchas
- Lottie animations must be exported correctly from After Effects via Bodymovin; incompatible exports (missing assets, rasterized layers) appear broken
- External hosted animations depend on third-party CDN uptime — host the JSON locally for reliability
- Complex animations with many layers increase file size and CPU rendering cost; keep JSON under 200KB
- Scroll scrub animation requires Elementor Pro Motion Effects; basic autoplay works in Free
