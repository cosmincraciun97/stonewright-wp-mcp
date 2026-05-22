---
title: Multi Select
source_url: https://elementor.com/help/multi-select-feature/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:multi-select]
related_widgets: []
---

## Purpose
Multi Select is an editor productivity feature that allows selecting multiple Elementor elements simultaneously in the canvas or Navigator, then performing batch operations (move, duplicate, copy style, delete, align) on all selected items at once. It replaces the need to repeat the same action on each element individually.

## Use this when
- Aligning multiple widgets to the same edge or distributing them evenly
- Deleting a group of elements in one action
- Duplicating several widgets at once to build a repeating pattern
- Copying a shared style setting across multiple selected elements
- Moving a set of widgets from one container to another in the Navigator

## Settings highlights
- **Selection method**: hold `Ctrl` (Windows) or `Cmd` (macOS) and click elements in the canvas or Navigator
- **Selection box**: click and drag on empty canvas space to rubber-band select a group
- **Navigator multi-select**: `Ctrl/Cmd + click` rows in the Navigator panel
- **Batch actions available**: Delete, Duplicate, Copy / Paste Style, Save as Template, Align (left, center, right, top, middle, bottom), Distribute (horizontal/vertical spacing)
- **Edit panel**: when multiple elements are selected, the panel shows only settings that apply to all (padding, margin, responsive visibility)

## Limits / gotchas
- Cannot multi-select elements across different parent containers using canvas rubber-band — elements must be siblings or the rubber-band must encompass their common parent
- Style editing for multi-selected elements with different widget types shows only shared controls — widget-specific controls are not visible
- Undo (Ctrl+Z) reverts the entire batch action as one step
- Not available in the V4 editor alpha in early releases — check version compatibility
