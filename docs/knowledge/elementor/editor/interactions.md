---
title: Interactions
source_url: https://elementor.com/help/interactions/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Interactions in Elementor V4 are event-driven animation and action rules attached to individual elements. They allow triggering CSS transitions, scroll animations, or state changes on events such as scroll-into-view, mouse enter/leave, click, or page load — without writing JavaScript. Interactions replace and extend V3 Motion Effects and Entrance Animations.

## Use this when

- Animating an element into view as the user scrolls down the page
- Triggering a class toggle on click to show/hide other elements
- Playing a CSS animation when the viewport reaches an element
- Adding parallax-like scroll effects to background or foreground elements
- Creating interactive micro-animations on button or card hover

## Settings highlights

- **Trigger types** — Scroll into view, Mouse enter, Mouse leave, Click, Page load, Viewport percentage
- **Action types** — Play animation, Toggle class, Set style, Scroll to section, Show/hide element
- **Animation library** — built-in CSS animation presets (fade, slide, zoom, flip, bounce) with duration and easing
- **Scroll progress** — bind element properties (opacity, transform) to scroll percentage within a range
- **Delay / Duration** — per-interaction timing controls in milliseconds
- **Replay** — whether the interaction re-triggers on re-enter or only fires once
- **Stagger** — apply the same interaction to all children of a container with a time offset between each
- **Interaction panel** — accessed via the Advanced tab → Interactions section in V4 editor

## Limits / gotchas

- V4 Interactions replace V3 Entrance Animations and Motion Effects; both appear in the Advanced tab but V4 Interactions offer a superset of controls
- Complex multi-step animation sequences requiring precise JS timing are not achievable with built-in Interactions; use custom JavaScript or a library like GSAP
- Scroll-progress interactions may cause performance issues on mobile; test frame rate on target devices
- Toggle-class interactions require the target class to be defined in the Class Manager or custom CSS beforehand
