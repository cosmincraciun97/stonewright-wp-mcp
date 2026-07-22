---
name: design-to-wordpress
description: >
  Stonewright Figma/image/brief → DesignEvidence → native plan → Elementor /
  Gutenberg / FSE apply → agent-owned Playwright verification loop.
---

# Design to WordPress (native-first, pixel-perfect)

Stonewright never embeds Figma APIs, Playwright, or pixel-diff modules. The
pipeline is:

```
external Figma MCP (client) / screenshot / brief
  → agent normalizes DesignEvidence 1.0 (figma_token_table, measured_targets)
  → stonewright-design-native-plan (target: elementor | gutenberg | fse)
  → native write (blueprint-apply / elementor build / FSE template)
  → agent Playwright/browser MCP verifies vs measured_targets
  → iterate
```

## Hard rules

1. Call `stonewright-task-start` first. Read `visual_build_gate` and
   `visual_quality_contract`.
2. **No raw Figma trees** after normalization — only DesignEvidence fields.
3. **CSS only with `native_gap`**. Call
   `stonewright-design-implementation-contract` with `action: validate` before
   shipping custom CSS. Missing gap → `stonewright_spec_invalid`.
4. Verification is **agent-owned** (Playwright MCP). Tolerances:
   - spacing ±2px
   - colors exact hex after token resolution
   - font-size exact
   - line-height ±0.05
5. Engines: `elementor` (alias elementor-v3), `gutenberg`, `fse`. Never silent
   fallback.

## Required task start

1. `stonewright-task-start` with request, surface, intent.
2. Confirm an external Playwright/browser MCP tool is visible before first
   write. If missing, tell the user to add
   `npx -y @playwright/mcp@latest --caps=testing,vision,devtools`, restart,
   and stop.
3. Elementor work: `stonewright-widget-intent-resolve` + implementation guide
   before writes.
4. Snapshot before Elementor / template / theme.json writes.

## Worked example: Figma frame → native page

### 1. Extract (external Figma MCP)

Use the client’s Figma MCP to read frames, tokens, and bounds. Do **not** pass
the raw document into Stonewright.

Tool map when one or both Figma MCP servers are connected:
[references/figma-mcp-extraction.md](references/figma-mcp-extraction.md).

Quick pick (always re-list MCP tools — names vary by server build):

| Need | Console-bridge (prefer if present) | Official Figma MCP |
|------|------------------------------------|--------------------|
| Color / spacing tokens | `figma_export_tokens`, `figma_get_token_values`, `figma_get_variables` | `get_variable_defs` |
| Type ramp | `figma_get_design_system_summary` + `figma_get_text_styles` | `get_variable_defs` + `get_design_context` |
| Bounds / layout | `figma_get_component_for_development_deep`, `figma_get_file_data` | `get_metadata`, `get_design_context` |
| Screenshot hash | `figma_capture_screenshot`, `figma_take_screenshot` | `get_screenshot` |
| Copy | file/dev tree text | `get_design_context` text |

One collection-level tokens call beats per-node color reads. Extract per
top-level section/frame. After normalizing DesignEvidence, do not re-fetch the
file.

### 2. Normalize DesignEvidence 1.0

Minimum shape:

```json
{
  "sources": [
    {
      "id": "figma:hero",
      "type": "figma",
      "ref": "node:12:34",
      "hash": "<sha256 of export or node payload>",
      "captured_at": "2026-07-16T12:00:00Z"
    }
  ],
  "viewports": [
    { "id": "desktop", "width": 1440, "height": 900 },
    { "id": "mobile", "width": 390, "height": 844 }
  ],
  "global": {
    "colors": { "primary": "#0B1F3A" },
    "spacing_scale": { "md": 16, "lg": 24, "xl": 48 },
    "typography_ramp": { "h1": { "size_px": 56, "line_height": 1.1 } },
    "figma_token_table": {
      "spacing": { "section_y": 96 },
      "type": { "hero_title": 56 }
    },
    "provenance": {
      "colors.primary": {
        "source": "design",
        "source_id": "figma:hero",
        "confidence": 0.99,
        "requires_confirmation": false
      }
    }
  },
  "measured_targets": [
    {
      "viewport_id": "desktop",
      "node_id": "hero",
      "property": "padding_top",
      "value_px": 96,
      "tolerance_px": 2
    }
  ],
  "nodes": [
    {
      "id": "hero",
      "role": "container",
      "bounds": { "x": 0, "y": 0, "width": 1440, "height": 640 },
      "layout": {
        "type": "flex",
        "direction": "column",
        "align_items": "center",
        "justify_content": "center",
        "gap": 24,
        "content_width": 720
      },
      "style": { "gap": 24 },
      "provenance": {
        "gap": {
          "source": "design",
          "source_id": "figma:hero",
          "confidence": 0.99,
          "requires_confirmation": false
        }
      },
      "children": [
        {
          "id": "hero-cta",
          "role": "button",
          "bounds": { "x": 630, "y": 320, "width": 180, "height": 48 },
          "content": { "label": "Book visit" },
          "action": { "url": "https://example.test/book" },
          "style": { "background_color": "#E8A838" },
          "provenance": {
            "background_color": {
              "source": "design",
              "source_id": "figma:hero",
              "confidence": 0.99,
              "requires_confirmation": false
            }
          }
        }
      ]
    }
  ]
}
```

### 3. Native plan

```json
{
  "action": "plan",
  "target": "elementor",
  "evidence": { "...": "DesignEvidence above" }
}
```

Call `stonewright-design-native-plan`. Expect per-node:

- `native_mapping` — engine-native construct (widget/block/template), **or**
- `native_gap` — justified missing control (`css_allowed: true` only here)

Repeat for `gutenberg` or `fse` when the user picks that engine.

### 4. Apply

- Elementor: kit globals if approved → `elementor-v3-build-page-from-spec`
  dry-run then write (or `blueprint-apply` with `engine: elementor`).
- Gutenberg: `blueprint-apply` / `design-spec-to-gutenberg` with
  `engine: gutenberg`.
- FSE: `blueprint-apply` with `engine: fse` (constrained layout + transaction
  queue).

Validate specs with `stonewright-design-validate-spec`. Snapshot first.

### 5. When CSS is acceptable

CSS is allowed **only** when:

1. The native plan recorded `native_gap` for that element, **and**
2. The block includes `native_gap.reason` (or plan gap is promoted via
   `design-implementation-contract` validate), **and**
3. User approved phase-two customization.

Otherwise `action: validate` returns `stonewright_spec_invalid` /
`custom_css_without_native_gap`. Prefer native controls, composition, kit
tokens, and Theme Builder before CSS.

### 6. Verify (agent Playwright)

For each breakpoint frame in DesignEvidence:

1. Screenshot the **front-end** page (not wp-admin chrome).
2. Measure padding, gap, font-size, colors against `measured_targets`.
3. Fail the pass on horizontal overflow.
4. Iterate fixes until tolerances pass or the user accepts a documented gap.

## Decision rule: CSS

| Situation | Action |
|-----------|--------|
| Native widget covers the look | No CSS |
| Plan has `native_mapping` only | No CSS |
| Plan has `native_gap` + user approval | Scoped CSS / theme stylesheet |
| CSS without gap | Hard fail (`stonewright_spec_invalid`) |
| HTML widget | Only with explicit `allow_html_widget` |

## Related tools

- `stonewright-design-native-plan`
- `stonewright-design-implementation-contract` (`contract` | `validate`)
- `stonewright-blueprint-apply` (`engine`: auto|elementor|gutenberg|fse)
- `stonewright-elementor-v3-build-page-from-spec`
- `stonewright-brand-kit-apply` (`preview: true` for diffs)

## Backup / production-safe

- `Backup::snapshot_post` before Elementor/template writes.
- Confirmation tokens for destructive ops in production-safe mode.
- FSE/Elementor transactions rollback on readback failure.
