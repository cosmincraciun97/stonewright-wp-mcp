---
name: Mega menu
description: Build or restructure a multi-column navigation menu with locations and nested items.
enable_agentic: true
enable_prompt: true
---

# Mega menu

## Steps
1. `stonewright/menu-list` current menus and locations.
2. Create menu with `stonewright/menu-create` if needed.
3. Add items via `stonewright/menu-add-item` with correct parents.
4. Assign location with `stonewright/menu-assign-location`.
5. Verify front-end structure via site health/theme tools when available.

## Rules
- Prefer real pages/CPTs over custom links when targets exist.
