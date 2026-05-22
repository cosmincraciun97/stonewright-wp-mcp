# Elementor Knowledge Base

Phase 0 of the Stonewright Elementor Mastery plan (see
`docs/superpowers/plans/2026-05-22-elementor-mastery-plan.md`). Canonical
markdown extracts of every official Elementor source — free, Pro, WooCommerce,
Theme Builder, V3, V4, developer docs, custom-widget recipe.

The harvested files feed two consumers:

1. **Build-time** — Phase A.4 manifest enrichment and Phase D.1 ability
   description copy.
2. **Run-time** — the `stonewright/elementor-knowledge-search`,
   `stonewright/elementor-describe-widget`, and
   `stonewright/elementor-explain-editor` abilities (Phase 0.4) read from this
   directory so the LLM can answer "how does this work?" mid-build.

## Layout

```
docs/knowledge/elementor/
  README.md                       <- this file
  _links.json                     <- Phase 0.2 hub-link inventory
  _change_log.md                  <- Phase H append-log when content_hash changes
  widgets/<slug>.md               <- one per widget (free / pro / wc / theme)
  editor/<slug>.md                <- one per editor concept (canvas / navigator / kit / responsive / history / finder / theme-style)
  theme/<slug>.md                 <- one per Theme Builder topic (header / footer / single / archive / loop / popup / site-settings / display-conditions / global-styles)
  developer/<slug>.md             <- developer docs (widget API, control types, hooks, atomic widgets, V4 schema, prop transformers)
  custom-widget/recipe.md         <- the blog/custom-wordpress-widget article fully extracted
  custom-widget/templates/        <- Phase G.4 pre-baked templates (pricing-card, feature-tile, icon-stat-counter, ...)
  meta/sources.json               <- { file -> source_url + fetched_at + content_hash }; powers Phase H refresh loop
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

If the source page is purely marketing / billing / pricing, write a stub with
`tombstone: true` in the frontmatter and skip the body. Phase H's diff awareness
relies on `content_hash`, so the tombstone is recorded too.

## Linking

Use `[[slug]]` cross-references between files (e.g. inside a widget article,
reference `[[editor/responsive-controls]]` if relevant). The link does not have
to exist yet — it marks a related topic for a future harvest pass.
