---
name: design-to-wordpress
description: >
  Orchestrates the full design-to-published-page pipeline from a Figma URL or
  reference image. Routes to Gutenberg by default, Elementor V3 when active.
---

# Design to WordPress

Takes a Figma node URL or an image and produces a live WordPress page. Handles
source ingestion, token extraction, spec assembly, renderer selection, asset
normalisation, and final write. Each step gates on the previous; the pipeline
aborts on validation failure.

## Pipeline

```
source (Figma / image)
  -> stonewright/design-import-figma-node  OR  stonewright/design-import-image
  -> stonewright/design-extract-tokens
  -> stonewright/design-build-spec
  -> stonewright/design-validate-spec          (gate: reject on WP_Error)
  -> stonewright/design-choose-renderer
  -> stonewright/design-normalize-assets
  -> stonewright/design-spec-to-gutenberg      (default)
     OR stonewright/design-spec-to-elementor-v3  (when Elementor V3 active)
  -> stonewright/content-create-page  OR  stonewright/content-update-page
```

## Renderer selection

Call `stonewright/design-choose-renderer` with the validated spec before
writing. The ability returns `{ "renderer": "gutenberg" | "elementor_v3" |
"elementor_v4" }`. Pass the result to the correct spec-to-* ability. Never
hard-code a renderer; site state may differ.

## Backup rule

Before any write to an existing page, call `stonewright/elementor-v3-backup-page`
(Elementor) or `stonewright/site-backup-page` (Gutenberg/FSE). The snapshot_id
returned is required in the confirmation payload for destructive actions.

## Validation gate

`stonewright/design-validate-spec` returns `{ "valid": bool, "errors": [...],
"normalized": {...} }`. Use `normalized` as the spec for downstream steps. If
`valid` is false, surface all errors to the user and stop.

## Confirmation token

For writes to existing pages, emit a confirmation token containing
`snapshot_id` + `post_id` before proceeding. Wait for user acknowledgement.

## Ability summary (most relevant)

| Ability | Purpose |
|---|---|
| `stonewright/design-import-figma-node` | Fetch Figma node, return spec stub |
| `stonewright/design-import-image` | Image -> spec stub for vision pipeline |
| `stonewright/design-extract-tokens` | Parse colors/typography/spacing tokens |
| `stonewright/design-build-spec` | Assemble spec from sections + tokens |
| `stonewright/design-validate-spec` | Validate against JSON schema |
| `stonewright/design-choose-renderer` | Pick renderer by site state |
| `stonewright/design-normalize-assets` | Sideload remote URLs -> media library |
| `stonewright/design-spec-to-gutenberg` | Render spec to block content |
| `stonewright/design-spec-to-elementor-v3` | Render spec to Elementor V3 JSON |
| `stonewright/content-create-page` | Create new WP page |
| `stonewright/content-update-page` | Update existing WP page |
| `stonewright/site-backup-page` | Snapshot page before write |

## Error handling

- `invalid_spec`: fix each listed error and re-run `design-build-spec`.
- `renderer_missing`: fall back to Gutenberg.
- `asset_not_found`: run `design-normalize-assets` before rendering.
- `feature_disabled` (V4): use V3 or Gutenberg path.

See `references/pipeline-examples.md` for complete JSON payloads.
See `references/troubleshooting.md` for common failure modes.
