---
name: agent-operating-rules
description: >
  Permanent product operating rules for every Stonewright agent: single-target
  environment, no ad-hoc plugins, HTTP-first automation, additive content models,
  and Elementor native-first discipline. Use for any WordPress implementation,
  content-model, or Elementor layout task.
---

# Agent operating rules (product defaults)

These rules ship with the plugin. They are **not** site Safety Memory entries.

## Environment scope

- Mutate **only** the site the user named.
- Do not also edit local/staging/another host “for consistency” unless asked.
- If you hit the wrong environment, report it and offer restore.

## No ad-hoc plugins

- Never scaffold, zip, upload, or activate custom plugins as a workaround.
- Prefer tools already on the site and typed Stonewright abilities.
- New CPT/taxonomy/field-group **registration** needs server-side PHP or existing admin tools (CPT UI Add New, ACF UI). Core REST has no registration endpoint.

## HTTP-first automation

1. WP REST / Application Password  
2. Official plugin REST/APIs  
3. Stonewright typed abilities (`elementor-v3-*`, content, php-execute when appropriate)  
4. Authenticated admin form POST + nonces  
5. Browser clicks only as last resort; screenshots and visual verification are fine  

## Content model: additive only

- Never CPT UI **full Import** to add one type — import **replaces** entire option bags.
- Use **Add New** / targeted edit (`cpt_original` + `cpt_type_status=edit` for CPT UI edits).
- Never bulk-transfer models/options/content across environments without explicit user request.

## Elementor native-first

1. **Native widgets/controls** first.  
2. **Scoped CSS** under a parent section class in child-theme `style.css` only when native controls cannot express the need.  
3. **Scripts/HTML/JS** only as last resort with required approvals.

### Responsive typography

- One widget per text role.
- Use Style → Typography responsive device controls for size / line-height / letter-spacing.
- Do **not** dual-widget + `hide_desktop` / `hide_mobile` for typography-only breakpoint diffs.

### Nested Carousel

- Use native Direction, Offset Sides, Offset Width (infinite often required for offset).
- Do not fake peek with CSS padding on the track.

### Swiper arrows

- Keep `.elementor-main-swiper` overflow hidden.
- Position native arrows inside the track — never `overflow:visible` to expose outside arrows.

### Tree integrity

- Every V3 node needs a non-empty unique `id`.
- Never raw `_elementor_data` via `php-execute`; use typed Elementor abilities + backup.
