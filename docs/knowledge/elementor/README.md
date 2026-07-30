# Elementor Knowledge Base

Canonical markdown extracts of Elementor reference sources used by Stonewright
for native widget guidance, editor behavior, Theme Builder details, V3/V4
notes, developer APIs, and custom-widget recipes.

The harvested files feed two consumers:

1. **Build-time** — widget manifest enrichment and ability description copy.
2. **Run-time** — the `stonewright/elementor-knowledge-search`,
   `stonewright/elementor-describe-widget`, and
   `stonewright/elementor-explain-editor` abilities read from this directory
   so agents can answer "how does this work?" mid-build.

## Layout

```
docs/knowledge/elementor/
  README.md                       <- this file
  _links.json                     <- hub-link inventory
  _change_log.md                  <- append-log when content_hash changes
  widgets/<slug>.md               <- one per widget (free / pro / wc / theme)
  editor/<slug>.md                <- one per editor concept (canvas / navigator / kit / responsive / history / finder / theme-style)
  theme/<slug>.md                 <- one per Theme Builder topic (header / footer / single / archive / loop / popup / site-settings / display-conditions / global-styles)
  developer/<slug>.md             <- developer docs (widget API, control types, hooks, atomic widgets, V4 schema, prop transformers)
  custom-widget/recipe.md         <- the blog/custom-wordpress-widget article fully extracted
  custom-widget/templates/        <- pre-baked templates (pricing-card, feature-tile, icon-stat-counter, ...)
  meta/sources.json               <- { file -> source_url + fetched_at + content_hash }; powers refresh checks
```

## Frontmatter schema

Every `.md` file MUST start with:

```yaml
---
title: <article title>
source_url: https://elementor.com/help/...
fetched_at: 2026-05-22T00:00:00Z
content_hash: sha256-<hex>
applies_to: [widget:<slug>, editor:v3, editor:v4, theme-builder, developer, custom-widget]
related_widgets: [heading, button, ...]
---
```

Body — 200-400 words, dense, no marketing fluff — in this order:

1. `## Purpose` — one paragraph, what the feature is for.
2. `## Use this when` — 3-5 bullets, concrete scenarios.
3. `## Settings highlights` — key control keys and one-line explanations.
4. `## Limits / gotchas` — anything that would surprise a new user.

If the source page is purely marketing, billing, or pricing, write a stub with
`tombstone: true` in the frontmatter and skip the body. Refresh checks rely on
`content_hash`, so the tombstone is recorded too.

## Linking

Use `[[slug]]` cross-references between files (e.g. inside a widget article,
reference `[[editor/responsive-controls]]` if relevant). The link does not have
to exist yet — it marks a related topic for a future harvest pass.
