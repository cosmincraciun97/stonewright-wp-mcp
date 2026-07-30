---
title: Nav Menu Widget
source_url: https://elementor.com/widgets/pro/nav-menu-widget/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:nav-menu]
related_widgets: [menu-anchor]
---

## Purpose

The Nav Menu widget lets you display WordPress navigation menus with extensive customization. It transforms standard WordPress menus (created via Appearance > Menus or stonewright/menu-create) into styled, responsive navigation experiences that match your design system while maintaining full control over layout, animations, and device-specific behavior. Requires Elementor Pro.

## Use this when

- Building site headers or footers requiring branded navigation linked to a WP menu object
- Creating horizontal navigation bars with hover animations and pointer indicators
- Designing vertical sidebars or stacked mobile navigation menus
- Needing dropdown or hamburger-style responsive menu behavior on smaller viewports
- Matching menu item typography and colors to a specific design system or brand guide

## Settings highlights

- **menu**: Select from existing WordPress menu objects (term_id reference); must create the menu first via WP admin or `stonewright/menu-create`
- **layout**: `horizontal` / `vertical` / `dropdown` — controls primary flow direction
- **align_items**: `start` / `center` / `end` / `stretch` — aligns items within the container axis
- **pointer**: `none` / `underline` / `overline` / `double-line` / `framed` / `background` / `text` — hover indicator style
- **indicator**: Arrow style for submenu parent items
- **toggle_button**: Shows hamburger toggle on mobile breakpoints
- **color_menu_item**: Default text color for menu items
- **color_menu_item_hover**: Text color on hover state
- **color_menu_item_active**: Text color for the active/current page item
- **typography controls**: Typography group for menu item labels (font, size, weight, spacing)
- **item_gap**: Space between top-level menu items

## Limits / gotchas

- Requires Elementor Pro; not available in the free version
- The widget displays an existing WP menu; it does not create menu items; call `stonewright-menu-create` and `stonewright-menu-add-item` first, then point this widget at the resulting menu ID
- Submenu display behavior varies by device breakpoint — always test dropdown behavior on mobile viewport
- Advanced animations and full pointer customization require higher-tier Pro plans in some configurations
