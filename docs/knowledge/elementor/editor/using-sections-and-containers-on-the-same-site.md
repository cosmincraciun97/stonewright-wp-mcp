---
title: Using sections and containers on the same site
source_url: https://elementor.com/help/using-sections-and-containers-on-the-same-site/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Elementor supports coexisting V3 sections/columns and V4 Flexbox/Grid containers on the same website during migration. This article explains how to enable both, which settings control visibility, and the caveats of mixed-mode sites — helping teams migrate incrementally without breaking existing pages.

## Use this when

- Maintaining a legacy V3 page library while building new pages with containers
- Gradually migrating pages to containers without a full-site relaunch
- Debugging layout regressions caused by mixed-mode markup conflicts
- Testing container behavior on isolated pages before committing site-wide

## Settings highlights

- **Flexbox Containers experiment** — must be enabled in Elementor > Settings > Experiments for containers to be available in editor
- **Both modes active simultaneously** — sections and containers render side-by-side on the same page
- **Editor widget panel** — when containers active, the "+ Add" flow offers both container and section starting points
- **Import/Export** — exported templates retain their original structure type (section or container); importing works across modes
- **Theme Builder templates** — header/footer can mix structure types as long as experiments are active
- **Responsive controls** — sections use legacy breakpoint system; containers use V4 responsive breakpoints; these coexist without conflict
- **Global Styles** — CSS custom properties from V4 variables apply to both sections and containers

## Limits / gotchas

- Disabling the Flexbox Containers experiment after using containers on live pages will break those layouts
- Third-party plugins that inject into sections may not auto-detect containers; test per plugin
- Performance: rendering both section and container CSS adds to stylesheet weight
- V4 Editor mode (full V4) removes the ability to add new V3 sections; the coexistence mode is only available in V3.x compatibility releases
