# Drift Detection Examples

## Comparing spec sections to Elementor V3 page structure

After `stonewright/elementor-v3-get-page-structure` returns the live summary
outline, walk the spec `sections` array and attempt to match each section to a
top-level container by type hint or heading text. Request `responseMode: "full"`
only when raw settings are needed to classify a drift.

Spec:
```json
{
  "sections": [
    { "type": "hero",         "heading": "Build faster." },
    { "type": "features",     "heading": "Everything you need." },
    { "type": "testimonials", "heading": "What our users say." }
  ]
}
```

Live structure (abbreviated):
```json
[
  { "elType": "container", "id": "c001", "settings": { "background_color": "#0057FF" },
    "elements": [ { "widgetType": "heading", "settings": { "title": "Build faster." } } ] },
  { "elType": "container", "id": "c002", "settings": {},
    "elements": [ { "widgetType": "heading", "settings": { "title": "Everything you need." } } ] }
]
```

Finding: `sections[2]` (testimonials) has no corresponding container. Report
as `missing-section`.

## Comparing spec tokens to kit colors

Spec tokens:
```json
{ "colors": { "primary": "#0057FF", "secondary": "#1A1A2E" } }
```

Kit colors from `stonewright/elementor-v3-get-kit-globals` (`colors.items`,
abbreviated; the kit keeps `system_colors` and `custom_colors` and no other list):
```json
{
  "system": [
    { "id": "primary",   "title": "Primary",   "color": "#0044CC" },
    { "id": "secondary", "title": "Secondary", "color": "#1A1A2E" }
  ],
  "custom": []
}
```

Finding: primary token drifted from `#0057FF` to `#0044CC`. Report as
`token-drift`. `primary`, `secondary`, `text` and `accent` are system colors, so
the proposed fix writes the `system` bucket:

```json
{
  "ability": "stonewright/elementor-v3-kit-batch-mutate",
  "args": {
    "operations": [
      {
        "group": "colors",
        "bucket": "system",
        "colors": [
          { "id": "primary", "title": "Primary", "color": "#0057FF" }
        ]
      }
    ]
  }
}
```

A drifted color of the `custom` list is fixed with
`stonewright/elementor-v3-update-kit-colors` and the same `colors` rows.

## Comparing spec assets to media library

Spec section:
```json
{ "type": "hero", "background": { "image": "https://source.com/hero.jpg" } }
```

Live element settings:
```json
{ "background_image": { "url": "https://mysite.com/wp-content/uploads/hero-v1.jpg", "id": 77 } }
```

Check:
1. Call MCP tool `stonewright-media-get` with `id: 77` to read the attachment's
   `url`, `width`, `height` and `alt`.
2. Compare against the spec asset: its `attachment_id` when it has one,
   otherwise the file name and the `width` and `height` it lists. An imported
   file gets a new address, so the spec URL itself is not expected to match.
3. If different, flag as `asset-mismatch` and let the user decide which is
   correct.

```json
{
  "ability": "stonewright/media-get",
  "args": { "id": 77 }
}
```

## Gutenberg drift detection

Parse live blocks:
```json
{ "ability": "stonewright/blocks-parse", "args": { "post_id": 42 } }
```

Walk the block tree. For each spec section, attempt to match against a top-level
group or cover block by heading text within innerBlocks.

Missing section -> flag as `missing-section`.

Token drift in a Gutenberg block:
```json
{
  "ability": "stonewright/blocks-update",
  "args": {
    "post_id": 42,
    "path": [0],
    "attrs": { "backgroundColor": "primary" }
  }
}
```

## Heading hierarchy check

After parsing blocks, extract all `core/heading` blocks:

```
h1 "Build faster."       <- level 1, ok
h3 "Feature A"           <- level 3 skips h2, flag as hierarchy-break
h2 "What users say"      <- level 2 after h3, disorder
```

Report each jump as a minor accessibility finding.
