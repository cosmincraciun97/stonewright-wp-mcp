---
title: Button element
source_url: https://elementor.com/help/button-element/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Button element is a V4 atomic element for rendering styled clickable buttons with text, optional icon, and a link target. Unlike the V3 Button widget (which used grouped controls), the V4 Button element uses an atomic prop schema where each visual property maps directly to a CSS declaration, enabling class-based reuse and state-aware styling.

## Use this when

- Adding a primary or secondary CTA button to any section or container
- Linking to internal pages, external URLs, email addresses, or anchors
- Applying icon + label combinations with controlled icon position
- Building button styles that respond to hover/active/focus states via element states
- Reusing consistent button styling via CSS classes across multiple pages

## Settings highlights

- **text** prop — button label text; supports inline dynamic tags
- **link** prop — URL, anchor, `mailto:`, `tel:` with optional `target: _blank` and `nofollow`
- **icon** prop — optional icon from Font Awesome / custom icon library; position: before or after text
- **icon_gap** — spacing between icon and text label in px/em
- **size** — predefined size presets (XS, S, M, L, XL) or custom via Typography + Spacing controls
- **Style tab** — Typography (font-family, size, weight, transform), Color, Background (solid/gradient), Border, Border Radius, Box Shadow
- **Element states** — separate style sets for Normal / Hover / Active / Focus / Disabled
- **Width** — auto (content-width), full-width, or custom pixel/percentage
- **Alignment** — inherits from parent flex container or overridden per-element

## Limits / gotchas

- V4 Button element replaces but does not auto-migrate V3 Button widget; existing pages with V3 buttons keep V3 markup
- Gradient backgrounds on buttons require the Background → Gradient sub-type in the Style tab; V3 used a separate gradient control group
- Icon SVG uploads need SVG support enabled in Elementor > Settings > Advanced
- `target: _blank` links should include `rel="noopener noreferrer"` — Elementor adds this automatically for external links
