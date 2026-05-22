---
title: Variables Manager
source_url: https://elementor.com/help/variables-manager/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Variables Manager is the V4 editor panel for centrally creating, organizing, editing, and deleting design token variables (colors, spacings, font sizes) used across the site. It provides a structured interface for maintaining the CSS custom property system that powers V4's design-token-driven styling architecture.

## Use this when

- Setting up a new site's design system before building pages
- Auditing all defined variables to find unused or conflicting tokens
- Batch-updating a color palette (e.g. rebranding a client site's primary color)
- Grouping related variables into named categories (Colors, Spacing, Typography)
- Exporting the variable set to share with a developer or import to a staging site

## Settings highlights

- **Access** — editor toolbar icon (variable icon) or the gear icon in any variable picker field
- **Variable groups** — organize variables into named groups (e.g. "Brand Colors", "Spacing Scale")
- **Add variable** — name + type (Color/Size/Custom) + value; generates CSS custom property immediately
- **Edit variable** — inline value editor; changes propagate to all referencing elements in real time
- **Delete variable** — removes the CSS custom property; a confirmation warning lists elements that reference it
- **Search** — filter variable list by name or value for large design systems
- **Import/Export** — JSON export of all or selected variable groups; import merges into existing set
- **Sync** — sync variables with global elements that were imported from another site [[how-to-sync-variables-and-global-elements]]
- **Responsive values** — edit per-breakpoint values for size-type variables within the Manager

## Limits / gotchas

- The Variables Manager is V4-only; it does not appear in the V3 editor
- Variables and Global Colors are separate systems; Global Colors (V3 legacy) are not converted to V4 Variables automatically
- Bulk delete of multiple variables is not supported in the UI; must delete one at a time
- Variables are stored in the Elementor kit; if the kit is reset or replaced, all variables are lost unless exported beforehand
