---
title: Style tab - Typography
source_url: https://elementor.com/help/style-tab-typography/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Typography section of the V4 Style tab controls all text rendering properties for text-capable atomic elements (Heading, Paragraph, Button, etc.) using direct CSS prop mapping. It consolidates font-family, font-size, font-weight, font-style, line-height, letter-spacing, text-transform, text-decoration, and text-align into a single section with global typography preset integration.

## Use this when

- Setting the primary font family and weight for headings or body text
- Adjusting font size with responsive breakpoint overrides for mobile readability
- Applying text transforms (uppercase, capitalize) to button or nav labels
- Using global typography presets to maintain design system consistency
- Setting precise line-height for display headings or tight body text

## Settings highlights

- **Font family** — Google Fonts, Adobe Fonts, system fonts, or uploaded custom fonts; integrated with Global Typography presets
- **Font size** — value + unit (px, em, rem, vw, custom); responsive overrides common; supports CSS `clamp()` for fluid sizing
- **Font weight** — 100–900 numeric or named (light, regular, medium, bold, extra-bold, black)
- **Font style** — normal, italic, oblique
- **Text transform** — none, uppercase, lowercase, capitalize
- **Text decoration** — none, underline, line-through, overline
- **Line height** — unitless multiplier, px, em, or rem; unitless recommended for accessibility
- **Letter spacing** — px or em; negative values allowed for tight display text
- **Text align** — left, center, right, justify; per-breakpoint responsive
- **Global typography preset** — link to a named preset defined in Site Settings > Global Typography; updates propagate everywhere

## Limits / gotchas

- Variable fonts (fonts with axes beyond weight) require enabling Variable Font support in Elementor settings before axis controls appear
- `font-size` in `em` is relative to the parent element's font-size — can cascade unexpectedly in nested containers; `rem` is safer for predictable sizing
- Global typography presets override element-level font settings unless the element has an explicit inline override; understand the cascade [[prioritize-conflicting-styles]]
- Google Fonts are loaded externally; for performance use system fonts or self-host via a plugin like OMGF
