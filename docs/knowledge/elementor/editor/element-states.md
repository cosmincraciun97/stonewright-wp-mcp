---
title: Element states
source_url: https://elementor.com/help/element-states/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Element states in Elementor V4 allow defining separate style sets for CSS pseudo-states (Normal, Hover, Active, Focus, Disabled) directly in the editor without writing custom CSS. Each state stores its own values for color, background, border, typography, and shadow props — enabling rich interactive styling driven by user action.

## Use this when

- Creating hover effects on buttons, cards, or images (color change, shadow, scale)
- Styling focused form fields with visible outline for accessibility
- Differentiating active navigation menu items visually
- Implementing disabled-state appearance for form submit buttons
- Designing link hover transitions on text or icon elements

## Settings highlights

- **State selector** — dropdown in the Style tab header switching between Normal / Hover / Active / Focus / Disabled
- **Per-state overrides** — any Style tab prop can have a state-specific override; only differences from Normal state need to be set
- **Transition** — global transition duration/easing controls the CSS `transition` applied between state changes
- **Color state** — most commonly used; change background-color, text color, border-color per state
- **Transform state** — scale, translate, rotate on hover without JavaScript (uses CSS `transform`)
- **Shadow state** — add or remove box-shadow on hover/active
- **Atomic prop schema** — state props are stored as `{propName}_{state}` keys in the element's style object
- **Preview** — click the state name in the editor panel to simulate that state in canvas

## Limits / gotchas

- V4 element states are distinct from V3 where hover styles required separate "Style" → "Hover" tabs per control group — the unified state switcher is new to V4
- States apply only to the element itself; to style child elements on parent hover, custom CSS (`:hover > child`) is still required
- Focus styles are critical for accessibility; do not remove them without providing an alternative visible focus indicator
- Disabled state does not actually disable interaction — it only changes appearance; use `pointer-events: none` via custom CSS for functional disabling
