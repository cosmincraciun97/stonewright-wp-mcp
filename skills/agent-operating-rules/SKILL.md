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
entries and are **not** controlled by Custom Instructions. The first
canonical block is injected as permanent client guidance. The generalized
operational repairs below live in the native digest registry, so
`stonewright-rules-get` loads them once and `knownDigest` avoids resending their
bodies on every task.

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
  single-use custom-code grant issued by the human operator from authenticated
  wp-admin.
- Grant is bound to path + candidate `after_sha256`.
- First run `dry_run`. Show the user `approval_url`, exact target path, byte
  counts, and a short change summary. Then stop.
- Never open the approval page, issue or retrieve a grant, or apply the candidate
  unless the user explicitly asks the agent to perform that approval step.
- This applies to theme files, Customizer CSS, WPCode, Code Snippets, and
  equivalent code surfaces.
- Never write theme/plugin/core code files through `php-execute`.
- Use the approval-gated typed tool with full validation, atomic write, smoke,
  and rollback. Direct mode may inspect custom CSS but must not write it because
  pluginless mode has no authenticated wp-admin grant boundary.

### Elementor write closure

- Plan from the live widget/container schema. For Figma, screenshots, or other
  visual evidence, send `settings_evidence` and `require_evidence:true`.
- Consolidate one post into one dry-run batch and one apply. Never run parallel
  Elementor writes.
- After apply, call `stonewright-elementor-post-write-verify` with every touched
  element id. It invalidates post HTML/CSS caches, regenerates targeted CSS,
  warms Elementor frontend HTML, and checks those ids without returning content.
- Then verify desktop, tablet, and mobile in the separate frontend tab. For a
  boxed container, measure the outer element and its direct `.e-con-inner`.
- A successful meta readback is not completion.

## Native digest-registry rules

### Elementor transaction discipline

- Read live schema first; group all planned controls for one post into one
  dry-run batch and one apply.
- Serialize writes to the same document; never launch sibling write calls in
  parallel.
- Resolve each `schema_requests` item once, replace only rejected settings, then
  rerun one consolidated dry-run.
- Never fall back to raw metadata, REST runners, `php-execute`, or shell WP-CLI.

### Responsive semantic integrity

- Keep one semantic widget for each image, title, button, or field.
- Style it with native breakpoint controls; Advanced responsive controls are
  visibility, not styling.
- When valid live editor behavior contradicts a cached schema, stop and repair
  schema truth. Do not duplicate widgets.

### Verified content models

- After saving a field group or registration, reset cached runtime state and
  read it back.
- Prove one canonical record and the expected ordered fields.
- Snapshot before removing an incomplete duplicate.

### Asset source integrity

- Inspect SVG/design exports before upload: viewBox, dimensions, paths, fill,
  stroke, and backgrounds.
- Preserve source geometry and colors. Never invent tints, fills, backgrounds,
  or crops.

### Query-hook safety

- Mutate the provided builder query.
- Never start a nested query that triggers the same callback again.
- Use a non-reentrant, memoized lookup for required IDs and verify rendering.

### Temporary code lifecycle

- Sandbox is for review and recovery, not permanent ownership.
- Move approved durable code through the supported typed workflow to the
  appropriate operator-owned theme/plugin/MU location.
- Deactivate the temporary copy and verify behavior plus rollback.

### Dynamic architecture preservation

- Preserve existing query, loop-template, and dynamic-data relationships.
- A schema/renderer defect never permits static replacement cards or raw
  document writes.

### Native controls need rendered proof

- Use exact live-schema enum values for carousel, form, icon, and responsive
  controls.
- Measure rendered output and account for runtime margins, opacity, and
  `currentColor`.
- Never guess visibility values, position keys, or group-control activators.
  Resolve the exact live control and conditions first.
- A carousel peek is evidence-driven: after live schema resolution, keep one
  complete mobile card, derive the native offset from the measured reveal, and
  include parent padding/full-bleed geometry.

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
