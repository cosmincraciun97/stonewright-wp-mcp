---
title: Style tab - Size
source_url: https://elementor.com/help/style-tab-size/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Size section of the V4 Style tab controls the dimensions of an element — width, height, minimum and maximum dimensions — using CSS sizing props via the atomic prop schema. This replaces the scattered V3 sizing controls spread across Layout tab and Advanced tab with a single focused section.

## Use this when

- Setting explicit pixel or percentage widths for elements inside a Flexbox container
- Defining a fixed height for a hero container or card thumbnail area
- Constraining maximum width to prevent elements from stretching too wide on large screens
- Setting minimum height to ensure empty or dynamic containers don't collapse
- Making an element fill its parent's full width or height

## Settings highlights

- **width** prop — `auto`, px, %, em, rem, vw (viewport width), custom; `auto` respects flex-grow
- **height** prop — `auto`, px, %, vh (viewport height), em, rem; `auto` = content height
- **min-width** / **max-width** — constrain width range; `max-width` critical for readability on large displays
- **min-height** / **max-height** — constrain height range; `min-height: 100vh` for full-viewport sections
- **flex-grow** / **flex-shrink** — per-element controls in Size section when element is a flex child; controls how element grows/shrinks relative to siblings
- **flex-basis** — initial size before grow/shrink is applied; complements width in flex context
- **overflow** — `visible`, `hidden`, `auto`, `scroll` per axis
- **Responsive** — all size props have per-breakpoint overrides

## Limits / gotchas

- `width: 100%` on a flex child and `flex-grow: 1` are not identical; `width: 100%` ignores flex distribution while `flex-grow` participates in it
- Setting an explicit `height` on a container that contains dynamic content (Loop, Posts) may clip content at smaller breakpoints — use `min-height` instead
- V4 separates flex-specific sizing (flex-grow, flex-basis) from general CSS sizing (width, height) in the same Size section; V3 mixed them across tabs
- Viewport units (vw/vh) can cause layout shifts on mobile when the browser address bar appears/hides
