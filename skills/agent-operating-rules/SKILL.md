---
name: agent-operating-rules
description: >
  Permanent product operating rules for every Stonewright agent: responsive
  device tabs, separate verification tabs, Figma section isolation, breakpoint
  isolation, native-first styling, fastest safe interface, verified learning,
  single-target environment, no ad-hoc plugins, HTTP-first automation, and
  Elementor native discipline. Use for any WordPress implementation task.
---

# Agent operating rules (product defaults)

These rules ship with the plugin and companion. They are **not** site Safety Memory
entries and are **not** controlled by Custom Instructions.

## Canonical permanent rules

### Elementor responsive preview

- When editing responsive Elementor settings through the UI, switch the device
  with the editor top-toolbar device tabs (`role=tab`, discover at runtime).
- Never resize the whole editor browser window to select an Elementor breakpoint.
- Verify the selected tab via `aria-selected=true`.

### Separate verification tab

- Keep the Elementor editor tab dedicated to editing (`editor_page`).
- Open or reuse a separate frontend tab (`verification_page`) for rendered checks.
- Resize only the verification tab; never resize or navigate away from the editor
  window for viewport checks.

### Figma section isolation

- Treat any multi-section Figma page/node as an ordered section manifest
  (node id, name, bounds, breakpoints).
- Capture one screenshot and extract layout/typography/assets/colors/spacing
  per section.
- Implement and verify one section per guarded transaction, then full-page regression.

### Breakpoint isolation

- Design evidence for one breakpoint authorizes changes only to that breakpoint.
- Preserve every other breakpoint exactly (hash non-target values before/after).
- If a native control is not responsive, perform no write, return
  `unsupported_responsive_control`, and notify the user — never fall back to
  base values or Custom CSS.

### Native-first styling

- Use native Elementor, Gutenberg, or FSE controls before Custom CSS or code.
- If native implementation is impossible, stop and explain the proven native gap
  before adding Custom CSS or code.

### Fastest safe interface

Order:

1. `typed_api` — typed Stonewright/native APIs
2. `editor_command_bus` — Elementor editor command bus
3. `admin_form` — authenticated admin form POST
4. `browser_ui` — Playwright locators only when no safe programmatic path exists

Never skip permission, backup, validation, confirmation, audit, or readback
gates for speed. Never implement via DOM mutation through browser `evaluate()`.

### Verified learning

- When the user explicitly asks Stonewright to remember a correction or stable
  preference, call `stonewright-learning-record` in the active mode.
- Read it back and report `memory_id`, `scope`, and `verified:true`.
- Never claim it was remembered without verification.

### Custom code operator grant

- Custom PHP/CSS/JS/HTML may run only after a proven native gap and a short-lived
  single-use custom-code grant issued from authenticated wp-admin.
- Grant is bound to path + candidate `after_sha256`.
- Never write theme/plugin/core code files through `php-execute`.
- Use `stonewright/theme-file-patch` with `dry_run`, full-file validation, atomic
  write, smoke, and rollback.

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
