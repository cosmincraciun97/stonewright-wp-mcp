---
title: Considerations for Theme Builder compatibility
source_url: https://elementor.com/help/considerations-for-theme-builder-compatibility/
fetched_at: 2026-05-22T16:00:00Z
content_hash: sha256-pending
applies_to: [theme-builder]
related_widgets: []
---

## Purpose
Theme Builder compatibility considerations guide developers and site owners on ensuring their custom themes, plugins, and design systems work seamlessly with Elementor's Theme Builder feature, which enables full site design through the visual editor rather than traditional theme files.

## Use this when
- Integrating third-party themes with Elementor's header, footer, and template systems
- Troubleshooting missing headers or footers after Theme Builder implementation
- Setting up display conditions and theme styles across multiple site sections
- Creating archive, single post, and custom template layouts
- Migrating from traditional theme development to Theme Builder workflows

## Settings highlights
- **Display Conditions** — Control template visibility across pages, posts, taxonomies, and custom post types
- **Site Parts** — Design reusable header, footer, sidebar, and 404 page sections
- **Theme Styles** — Apply global design tokens including colors, fonts, and spacing
- **Archive Templates** — Customize blog, product, and category page layouts dynamically
- **Single Templates** — Create unique post, product, and custom post type displays
- **Sticky Headers** — Configure persistent navigation with transparency options
- **Conditions UI** — Set granular rules determining when templates render
- **Template Library** — Access pre-built site parts and full-page templates

## Limits / gotchas
- Certain themes may conflict with Theme Builder's HTML structure or CSS cascade, requiring custom overrides
- Legacy plugins that output directly to header/footer hooks may duplicate content or break layouts
- Theme-specific customizer settings sometimes override Theme Builder styles; prioritization must be managed carefully
- Some third-party integrations (LMS plugins, ACF) need explicit configuration to display content properly within Theme Builder templates
