---
name: Landing page — Agency
description: Agency or studio landing page with case studies, services, and contact CTA.
enable_agentic: true
enable_prompt: true
---

# Landing page — Agency

## Steps
1. `stonewright-task-start` → detect builder via `stonewright/site-plugins-list`.
2. Import hero/case imagery with `stonewright/stock-image-search` + `stonewright/stock-image-import` when no brand assets exist.
3. Spec sections: hero, services grid, selected work, process, testimonials, contact.
4. Validate with `stonewright/design-validate-spec`, then build with Elementor or Gutenberg composite abilities.
5. Wire contact form shortcode only after `stonewright/site-shortcodes-discover`.

## Rules
- Portfolio cards need real aspect ratios and consistent gaps.
- Prefer CPT + Loop Grid for case studies when content will grow.
