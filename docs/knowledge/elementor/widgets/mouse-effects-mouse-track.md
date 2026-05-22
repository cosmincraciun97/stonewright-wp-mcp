---
title: Mouse Effects - Mouse Track
source_url: https://elementor.com/help/mouse-effects-mouse-track/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:mouse-effects-mouse-track]
related_widgets: [motion-effects, mouse-effects-3d-tilt, hover-animations]
---

## Purpose
Mouse Track is a motion effect that makes elements translate (move) in response to the user's cursor position across the page. Unlike 3D Tilt which rotates, Mouse Track physically shifts the element's X/Y position proportionally to where the mouse is, creating a parallax-within-the-viewport effect — making layered content feel responsive and alive.

## Use this when
- Creating interactive hero sections where background elements drift opposite to cursor movement (parallax depth)
- Building attention-grabbing landing pages with floating elements that follow the cursor
- Designing product showcases where a floating badge or shadow follows the mouse
- Adding subtle depth effects to images or decorative shapes above hero content
- Enhancing engagement on above-the-fold content with subtle motion response

## Settings highlights
- **Mouse Track**: enable/disable under Advanced > Motion Effects > Mouse Effects
- **Direction**: "Direct" (element moves toward cursor) or "Opposite" (element moves away — parallax feel)
- **Speed**: multiplier controlling how far the element moves relative to cursor displacement (0.1–10)
- **X Axis / Y Axis**: individual enable toggles for horizontal and vertical tracking
- **Breakpoint disable**: per-device toggle — should be disabled on touch/mobile
- Combine with Z-index layering for a multi-depth parallax hero

## Limits / gotchas
- Requires Elementor Pro
- Touch devices have no mouse position — effect does nothing on mobile; always disable on touch breakpoints
- Large speed values cause elements to fly far off their container, potentially clipping or overflowing — use overflow: hidden on the parent container
- Can cause accessibility concerns for users with motion sensitivity — Elementor does not automatically respect `prefers-reduced-motion`
- Performance cost is low (CSS transform) but stacking many tracked elements simultaneously may create jank on low-end hardware
