---
title: What are logical properties?
source_url: https://elementor.com/help/what-are-logical-properties/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Logical properties provide a CSS-based approach to styling that adapts automatically to different text directions (LTR/RTL) and writing modes, eliminating the need for separate directional overrides. In Elementor V4 the atomic Style tab exposes logical property options for margin, padding, border, and positioning — replacing the physical `left/right/top/bottom` with logical `inline-start/end` and `block-start/end` equivalents.

## Use this when

- Building multilingual sites supporting RTL languages (Arabic, Hebrew, Farsi)
- Creating responsive layouts that need to adapt to different writing systems
- Standardizing spacing and alignment across language variants without duplicate CSS
- Reducing CSS complexity by using directional-agnostic properties
- Designing components that must maintain visual consistency regardless of locale direction

## Settings highlights

- **Block Start/End** — replace `top`/`bottom` margins and padding (`margin-block-start`, `padding-block-end`)
- **Inline Start/End** — replace `left`/`right` margins and padding (`margin-inline-start`, `padding-inline-end`)
- **Logical border** — `border-inline-start/end` replaces `border-left`/`border-right`
- **Logical insets** — `inset-inline-start/end` for absolute/fixed positioning offset
- **Text alignment** — `start` and `end` values instead of `left`/`right` in Typography alignment
- **V4 toggle** — logical property mode toggled per-prop via a direction icon in the Spacing/Position style controls
- **Sizing logical props** — `inline-size` (width in LTR), `block-size` (height in LTR) used in Size section
- **Auto-flip** — when WordPress site language changes direction, logical-property-based layouts flip without CSS changes

## Limits / gotchas

- V3 used physical properties exclusively; V4 requires explicit logical property adoption per element — no auto-migration
- Browser support for all logical properties requires Chrome 87+, Firefox 63+, Safari 14.1+; test older browser targets
- Mixing logical and physical properties in the same ruleset on the same prop causes conflicts; pick one convention per element
- Some older third-party widgets use physical CSS internally and don't respond to V4 logical property changes
