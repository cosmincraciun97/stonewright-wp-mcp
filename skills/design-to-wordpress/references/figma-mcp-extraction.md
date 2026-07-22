# Figma MCP extraction map for DesignEvidence 1.0

Stonewright never calls Figma. The AI client extracts design facts with
whatever Figma MCP servers it has, normalizes them into DesignEvidence 1.0,
and hands that to `stonewright-design-native-plan`. This reference maps
evidence fields to the best extraction tool when one or both common Figma
MCP servers are connected.

Detect what you have: list your MCP tools. Official Figma MCP tools are
commonly `get_design_context`, `get_screenshot`, `get_variable_defs`, and
`get_metadata` (older server builds may expose `get_code` / `get_image`
aliases). A console-bridge Figma MCP exposes a wider `figma_*` surface
(e.g. `figma_export_tokens`, `figma_get_design_system_summary`,
`figma_capture_screenshot`).

(verify against your server's tool list)

| DesignEvidence field | Console-bridge MCP (preferred when present) | Official Figma MCP | Neither |
| --- | --- | --- | --- |
| `global.color_tokens`, `figma_token_table` | `figma_export_tokens` / `figma_get_token_values` / `figma_get_variables` (or one-shot `figma_get_design_system_kit`) | `get_variable_defs` on the selected frame; `search_design_system` for library tokens | Sample screenshot pixels; mark provenance `inference`, `requires_confirmation: true` |
| `global.typography_ramp` | `figma_get_design_system_summary` + `figma_get_text_styles` / `figma_get_styles` | `get_variable_defs` + `get_design_context` on text nodes | Measure from screenshot; `inference` |
| `nodes[].bounds`, `layout` | `figma_get_component_for_development_deep` / `figma_get_component_for_development` / `figma_get_file_data` | `get_metadata` for structure; `get_design_context` for layout intent | Screenshot measurement; `inference` |
| `measured_targets[]` | Same as bounds source | Same | Screenshot measurement |
| `sources[].ref` + screenshot hash | `figma_capture_screenshot` / `figma_take_screenshot` / `figma_get_component_image` | `get_screenshot` (or screenshot embedded in `get_design_context`) | User-provided screenshot |
| `nodes[].content` (copy) | `figma_get_file_data` text chars / development tree text | `get_design_context` output text | User brief |
| Post-write verification | `figma_check_design_parity` + browser MCP screenshots | `get_screenshot` + browser MCP screenshot diff | Browser MCP screenshots vs. reference |

## Rules that always apply

1. **Stonewright never embeds Figma.** Extraction happens only in the AI client
   via external MCP tools. Pass **DesignEvidence 1.0** into Stonewright — never
   a raw Figma document tree.
2. Prefer **console-bridge** tools when both servers are connected: they expose
   collection-level tokens, deep component trees, and runtime screenshots that
   match the open file.
3. Every numeric or color value needs **provenance**. Use `source: design` only
   when a tool returned the value; use `inference` +
   `requires_confirmation: true` when you measured from pixels or guessed.
4. Hash reference screenshots into `sources[].hash` so verification can prove
   which export the targets came from.
5. After WordPress writes, verify against `measured_targets` with a **browser
   MCP** (Playwright). Figma parity tools are optional helpers, not a substitute
   for front-end screenshots.

## Efficiency rules (token budget)

- One collection-level tokens call beats dozens of per-node color reads.
  Prefer `figma_export_tokens`, `figma_get_variables`, or
  `figma_get_design_system_kit` over walking every leaf fill.
- Extract per top-level section/frame, not per leaf node. Use
  `get_metadata` / `figma_get_file_data` (summary + shallow depth) to find
  frames, then deep-extract only the sections you will build.
- Do not re-fetch the Figma file after normalization. Keep the DesignEvidence
  object as the single source of truth for plan → apply → verify.
- Cap depth on file trees. Prefer `verbosity=summary` / shallow `depth` first;
  expand only for the frames that need measured targets.
- When official MCP is the only option, one `get_design_context` call per
  section frame is usually enough for layout + copy + a reference screenshot;
  add `get_variable_defs` once for the kit, not once per leaf.
