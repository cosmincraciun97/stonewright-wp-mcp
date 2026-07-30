---
name: Landing page — SaaS
description: Build a SaaS marketing landing page with hero, features, pricing, and CTA using Stonewright design tools.
enable_agentic: true
enable_prompt: true
---

# Landing page — SaaS

## Goal
Ship a conversion-focused SaaS landing page with clear hierarchy.

## Steps
1. Call `stonewright-task-start` with the brief and surface `elementor` or `gutenberg`.
2. `stonewright/site-info` + `stonewright/site-plugins-list` to detect builder.
3. Prefer existing media via `stonewright/media-list`; fill gaps with `stonewright/stock-image-search` then `stonewright/stock-image-import`.
4. Build via `stonewright/design-validate-spec` then:
   - Elementor: `stonewright/elementor-v3-build-page-from-spec` (`dry_run` first)
   - Gutenberg: `stonewright/gutenberg-apply-to-post`
5. Sections: hero → social proof → features (3–6) → pricing → FAQ → final CTA.
6. Snapshot before writes; re-read page structure after apply.

## Rules
- Use kit globals / theme.json tokens — no one-off hex soup.
- One primary CTA per section.
