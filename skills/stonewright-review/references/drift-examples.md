# Drift Detection Examples

## Comparing spec sections to Elementor V3 page structure

After `stonewright/elementor-v3-get-page-structure` returns the live tree,
walk the spec `sections` array and attempt to match each section to a top-level
container by type hint or heading text.

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

Kit colors from `stonewright/elementor-v3-get-page-structure` (kit section):
```json
[
  { "id": "primary",   "color": "#0044CC" },
  { "id": "secondary", "color": "#1A1A2E" }
]
```

Finding: primary token drifted from `#0057FF` to `#0044CC`. Report as
`token-drift`. Proposed fix:

```json
{
  "ability": "stonewright/elementor-v3-update-kit-colors",
  "args": {
    "colors": [
      { "id": "primary", "title": "Primary", "color": "#0057FF" }
    ]
  }
}
```

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
1. Call MCP tool `stonewright-media-get` with `attachment_id: 77` to get the original
   source URL.
2. Compare against spec image URL.
3. If different, flag as `asset-mismatch` and let the user decide which is
   correct.

```json
{
  "ability": "stonewright/media-get",
  "args": { "attachment_id": 77 }
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
