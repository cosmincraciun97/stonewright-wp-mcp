---
title: Make gradient backgrounds for buttons
source_url: https://elementor.com/help/make-gradient-backgrounds-for-buttons/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:make-gradient-backgrounds-for-buttons]
related_widgets: [button, gradient-background]
---

## Purpose
Create visually appealing buttons with gradient color transitions instead of solid backgrounds. The Button widget's Style tab Background controls support linear and radial gradients via the standard Background Type selector, allowing multi-stop color blends across the button surface. Separate hover-state gradients can be configured for interactive feedback.

## Use this when
- You want buttons to stand out with more dynamic, modern styling than flat solid colors
- Implementing brand gradients from a design system on interactive CTA buttons
- Creating hover states where the gradient shifts or intensifies on mouse-over
- Building visually differentiated primary vs. secondary buttons using gradient vs. solid

## Settings highlights
- **Background Type** (Style > Button > Background): select "Gradient" instead of "Classic"
- **Color stops**: two or more color pickers with position (0–100%) and opacity sliders
- **Gradient Type**: Linear (with angle control 0–360°) or Radial (with position control)
- **Hover state**: separate Background Type and gradient config for `:hover`
- **Transition Duration**: controls speed of normal-to-hover animation (ms)
- **Border Radius**: round corners complement gradient backgrounds
- **Box Shadow**: add depth beneath gradient button
- **Text Color** / **Typography**: ensure contrast against gradient background

## Limits / gotchas
- Gradient backgrounds on buttons require CSS gradients — the Elementor color picker does not expose all CSS gradient functions (e.g. `conic-gradient` is not supported natively)
- High contrast between gradient stop colors can make text hard to read — always check WCAG contrast ratio
- IE11 (legacy) renders linear-gradient without vendor prefix support; not relevant for modern browsers
