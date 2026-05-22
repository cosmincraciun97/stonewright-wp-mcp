---
title: What is a mega menu?
source_url: https://elementor.com/help/what-is-mega-menu/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

A mega menu is an expanded navigation dropdown that replaces a standard single-column sub-menu with a multi-column, widget-rich panel. In Elementor Pro, mega menus are built using containers placed inside nav menu items, allowing arbitrary widgets (images, icons, headings, CTAs) to appear on hover within the nav dropdown region.

## Use this when

- A top-nav menu item has many children and a simple dropdown becomes cluttered
- You need images, featured posts, or promo banners inside the navigation dropdown
- Building e-commerce sites with category navigation requiring visual hierarchy
- Creating "full-width" navigation overlays with multi-column layouts

## Settings highlights

- **Enable Mega Menu** — toggled per-menu-item in the WordPress Menu editor or via Astra/theme mega menu settings
- **Panel type** — "Mega Menu" dropdown type replaces default sub-menu for that item
- **Container-based content** — inner content is built with Elementor containers and widgets
- **Width** — Full Width (100vw) or Custom Width for the expanded panel
- **Columns** — define columns inside the mega panel using nested Flexbox/Grid containers
- **Background** — style the mega menu panel background independently
- **Trigger** — hover or click; configurable per item
- **Include sub-menus** — existing WordPress child menu items can be merged into mega panel as widget-driven columns
- **Responsive** — mega panel collapses to standard accordion or hamburger on mobile breakpoints

## Limits / gotchas

- Mega menus require Elementor Pro (Menu widget or Nav Menu widget with mega option)
- The mega panel is built in a separate Elementor "canvas" — changes there are independent from the page template
- CSS specificity conflicts can arise when theme stylesheet targets `.sub-menu` globally
- Mobile mega menus often need custom CSS to override desktop panel dimensions
