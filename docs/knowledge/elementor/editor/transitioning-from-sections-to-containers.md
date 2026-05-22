---
title: Transitioning from sections to containers
source_url: https://elementor.com/help/transitioning-from-sections-to-containers/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

This guide covers the migration path from the legacy Elementor V3 section/column layout model to the V4 Flexbox and Grid container architecture. Containers are the recommended building block in Elementor 3.x+ and mandatory in V4; this article walks through coexistence strategies, automatic conversion tools, and manual migration patterns.

## Use this when

- You have existing V3 pages with sections and columns and want to move to containers
- Setting up a new project and deciding between keeping V3 structures or fully adopting containers
- Troubleshooting layout differences after using the Convert to Containers feature
- Ensuring third-party widgets still work after migration

## Settings highlights

- **Convert to Containers tool** — right-click any section in the editor → "Convert to Container" to migrate one section at a time
- **Bulk conversion** — available via Elementor > Tools > Replace Sections with Containers in dashboard
- **Flexbox container equivalent** — a V3 section maps to a Flexbox container; a V3 column maps to a nested inner container
- **Width behavior** — V3 section boxed width set via `.site-inner` or content width; containers use self-contained `max-width` / `width` props
- **Column gap** — V3 used column gutter; V4 container uses `gap` prop; values may differ after conversion
- **Responsive behavior** — V4 containers inherit responsive controls differently; re-check tablet/mobile layouts post-migration
- **Widget compatibility** — most free widgets function in containers; some complex V3 widgets (inner section nesting) need review
- **Sections and containers coexist** — you can keep both on the same page during transition

## Limits / gotchas

- Automatic conversion does not guarantee pixel-perfect parity; visual review is mandatory
- V3 column percentage widths convert to flex-grow values, which may behave differently at edge breakpoints
- Nested inner sections converted to nested containers may introduce extra wrapper elements affecting z-index or overflow
- After full migration, disabling V3 sections experiment may break fallback for any remaining legacy structures
