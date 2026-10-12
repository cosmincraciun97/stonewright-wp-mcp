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
3. Add items via `stonewright/menu-add-item` (`menu_id`, `title`, `url`, optional `parent_id` and `position`) with correct parents.
4. Assign location with `stonewright/menu-assign-location`.
5. Verify front-end structure via site health/theme tools when available.

## Rules
- `menu-add-item` adds custom-link items: pass the real permalink of an existing page/CPT as `url` rather than an invented path.
