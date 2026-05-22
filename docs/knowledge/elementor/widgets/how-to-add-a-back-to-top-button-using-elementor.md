---
title: How To Add A Back To Top Button Using Elementor
source_url: https://elementor.com/help/how-to-add-a-back-to-top-button-using-elementor/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:how-to-add-a-back-to-top-button-using-elementor]
related_widgets: [button, floating-button, scroll-snap]
---

## Purpose
Elementor's back-to-top button lets visitors jump instantly to the top of a long page, implemented via a Button or Floating Button widget anchored to the page-top section and configured with a smooth-scroll link. No custom code is required; the same Motion Effects sticky + anchor pattern drives the interaction.

## Use this when
- Long-scroll pages (blog posts, landing pages, one-pagers) where users lose orientation
- Pages with sticky footers or sidebars where returning to the top is a common user action
- Accessibility requirements demand a keyboard-reachable shortcut to page top
- Mobile UX patterns that expect a visible jump control after significant scroll depth

## Settings highlights
- **Anchor ID**: set `#top` or a custom ID on the topmost section/container
- **Button Link**: point the button's URL to `#top` (or the matching anchor ID)
- **Position (Advanced > Positioning)**: set to Fixed so the button floats over content
- **Z-index**: raise above other sticky elements (e.g. 9999)
- **Scroll Threshold**: via Motion Effects > Scrolling, make the button visible only after N px scroll
- **Entrance Animation**: fade-in when scroll threshold is reached
- **Responsive Visibility**: hide on desktop if desired, or size differently per breakpoint
- **Icon**: add an upward arrow icon from the icon library for clarity

## Limits / gotchas
- Elementor Free does not include a dedicated back-to-top widget; the pattern requires combining Button + anchor + custom positioning
- Fixed-position elements may overlap other sticky headers/footers — test stacking context carefully
- Smooth scroll requires the Elementor smooth-scroll experiment to be enabled or a custom CSS `scroll-behavior: smooth` rule on `html`
