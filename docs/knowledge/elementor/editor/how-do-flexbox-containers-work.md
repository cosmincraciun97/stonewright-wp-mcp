---
title: Understanding how Flexbox containers work
source_url: https://elementor.com/help/how-do-flexbox-containers-work/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

This article explains the CSS Flexbox model as implemented in Elementor's container element, covering how flex direction, alignment, wrapping, and gap interact to produce responsive one-dimensional layouts. Understanding flexbox is foundational for all V4 container-based design work.

## Use this when

- Building horizontal or vertical stacks of widgets and elements
- Controlling how child items shrink or grow to fill available space
- Centering elements both horizontally and vertically within a container
- Creating responsive nav bars, card rows, or feature grids with wrapping
- Replacing legacy V3 column percentage layouts with flex-based sizing

## Settings highlights

- **Direction** (`flex-direction`) — Row (horizontal) or Column (vertical); governs the main axis
- **Wrap** (`flex-wrap`) — No Wrap / Wrap / Wrap Reverse; controls overflow into new lines
- **Justify Content** — Start, End, Center, Space Between, Space Around, Space Evenly on main axis
- **Align Items** — Start, End, Center, Stretch, Baseline on cross axis
- **Align Content** — applies when wrap is active; controls multi-line cross-axis distribution
- **Gap** — unified row-gap + column-gap control in px/em/rem/%; replaces V3 gutter
- **Flex Grow / Shrink / Basis** — per-child controls exposed in the child element's own Layout tab
- **Min Height** — prevents collapse when children are empty; critical for hero sections
- **Overflow** — Hidden clips children; Visible allows absolute-positioned children to escape

## Limits / gotchas

- Flex containers arrange children on one axis only; use Grid containers for two-axis layouts
- Justify-content Space Between on a Row container with a single child has no visible effect
- Children set to `position: absolute` are removed from flex flow and do not participate in gap
- V3 column widths (e.g. 33.33%) do not translate directly; use flex-basis or percentage width on children instead
