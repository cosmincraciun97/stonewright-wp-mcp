---
title: Style tab - Layout
source_url: https://elementor.com/help/style-tab-layout/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Layout section of the V4 Style tab controls how elements flow, align, and distribute within their containers using flexbox and grid properties. This atomic control schema enables responsive, flexible positioning without legacy V3 nested control groups — all layout props are directly in the Style tab, not buried in a separate Layout tab.

## Use this when

- Arranging child elements within a flex or grid container
- Defining element distribution and spacing alignment along flex axes
- Creating responsive layouts that adapt across device breakpoints
- Controlling directional flow (row/column) and wrapping behavior
- Fine-tuning gaps, justification, and alignment properties

## Settings highlights

- **Direction** — set flex flow as `row` or `column` orientation (`flex-direction` prop)
- **Wrap** — enable/disable element wrapping within container bounds (`flex-wrap` prop)
- **Justify Content** — distribute elements along primary axis: `flex-start`, `flex-end`, `center`, `space-between`, `space-around`, `space-evenly`
- **Align Items** — align child elements perpendicular to direction axis: `flex-start`, `flex-end`, `center`, `stretch`, `baseline`
- **Align Content** — multi-line cross-axis distribution when wrap is active
- **Gap** — row-gap and column-gap in px/em/rem/%; replaces V3 column gutter
- **Atomic prop schema** — `direction`, `flexWrap`, `justifyContent`, `alignItems`, `gap` map directly to CSS flex properties
- **Responsive toggles** — apply different layout values per device (desktop, tablet, mobile)

## Limits / gotchas

- V4's atomic structure differs significantly from V3's nested control hierarchy; migrated layouts may require reconfiguration of flex properties
- Legacy section/column structures don't map directly to V4 flexbox/grid — conversion tools available but manual review is recommended
- Gap property applies uniformly to all children; for unequal child spacing use margin on individual children or custom CSS
- Some V3 advanced layout tricks (absolute positioning combos with flex children) need rethinking in V4's declarative model
