---
title: SVG element
source_url: https://elementor.com/help/svg-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The SVG element is a V4 atomic element for rendering inline or referenced SVG graphics with full CSS-based styling. It enables adding scalable vector graphics — icons, illustrations, logo marks, decorative shapes — with size controls, color overrides via CSS `fill`/`stroke`, and element-state-driven hover effects, all without the V3 Icon widget limitations.

## Use this when

- Adding a custom SVG icon or logo that isn't in Font Awesome
- Rendering an illustration that needs to respond to hover color changes via CSS `fill`
- Placing decorative geometric shapes that scale perfectly at any resolution
- Using inline SVG so that CSS variables (brand colors) can propagate into SVG `fill` values
- Adding animated SVG paths via Interactions or custom CSS `@keyframes`

## Settings highlights

- **src** prop — SVG file from WordPress Media Library; requires SVG support enabled in Elementor > Settings > Advanced
- **Inline rendering** — SVG is injected inline into the DOM (not as `<img>`), enabling CSS targeting of inner SVG elements
- **width** / **height** props — explicit dimensions in px or %; SVG scales proportionally if only one is set
- **color** prop — sets CSS `color` which cascades to `fill: currentColor` in SVGs that use `currentColor`
- **Style tab** — Opacity, CSS Filters (for color manipulation: hue-rotate, saturate), element states for hover color changes
- **Hover state** — change `color` on hover state to recolor the SVG without custom CSS
- **Custom CSS** — target specific SVG paths within the element via `.elementor-svg path { fill: #hex; }`
- **Attributes** — add `aria-label` for accessibility when SVG conveys meaning

## Limits / gotchas

- SVG files must be sanitized before upload; Elementor sanitizes SVG on upload but some complex SVGs with `<script>` tags will be stripped
- CSS `fill` override only works if the SVG uses `fill="currentColor"` or has no explicit fill; hard-coded fills in the SVG ignore CSS
- Animated SVGs (`<animate>`, `<animateTransform>`) require the SVG to define the animation internally; Elementor Interactions don't control SVG internal animations
- Very large or complex SVGs increase page weight significantly; optimize with SVGO before upload
