---
title: How to sync variables and global elements
source_url: https://elementor.com/help/how-to-sync-variables-and-global-elements/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:how-to-sync-variables-and-global-elements]
related_widgets: [global-widget]
---

## Purpose
Synchronise design variables (colors, fonts, spacing tokens) and global widgets across an Elementor site so that a single change propagates to every page and template that references them. Variables are defined in the Variables Manager and consumed via the style panel; global elements are widgets saved as "Global" that share one central definition.

## Use this when
- Updating a brand color across an entire site without touching each page manually
- Keeping a header CTA button identical on 30+ pages via a single Global Widget
- Migrating a design system token (e.g. `--color-primary`) to a new hex value
- Sharing variables between multiple sites via export/import

## Settings highlights
- **Variables Manager** (Site Settings > Variables): create, name, and assign color/font/number tokens
- **Variable reference syntax**: `var(--e-global-color-primary)` in CSS controls
- **Global Widget**: right-click any widget → Save as Global; subsequent edits sync to all instances
- **Sync indicator**: Global Widget instances show a link icon in the navigator
- **Unlink from Global**: breaks the sync for a specific instance without deleting the global definition
- **Export variables**: download as JSON from Variables Manager for cross-site reuse
- **Import variables**: upload JSON to a different site to replicate the token set

## Limits / gotchas
- Variables are a V4 / design system feature; older Elementor installs must upgrade to access them
- Unlinking a widget from Global creates an independent copy — future global edits no longer propagate to it
- Syncing variables does not automatically republish pages; a manual save/publish cycle is required per page
