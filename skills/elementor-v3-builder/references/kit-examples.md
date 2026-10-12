# Kit Color and Typography Examples

Kit mutations are global. Always confirm with the user before writing.
Each kit mutation takes its own snapshot and returns `snapshot_id`. In
`production-safe` mode it also needs a `confirmation_token` issued for the exact call.

## Update kit colors

`update-kit-colors` merges into the custom colors (`custom_colors`) by `id`; it
does not change the system colors (`primary`, `secondary`, `text`, `accent`).

```json
{
  "ability": "stonewright/elementor-v3-update-kit-colors",
  "args": {
    "colors": [
      { "id": "brand-blue", "title": "Brand blue", "color": "#0057FF" },
      { "id": "brand-ink",  "title": "Brand ink",  "color": "#1A1A2E" },
      { "id": "brand-warm", "title": "Brand warm", "color": "#FF6B35" }
    ]
  }
}
```

## Update kit typography

Writes the custom typography (`custom_typography`) by `id`.

```json
{
  "ability": "stonewright/elementor-v3-update-kit-typography",
  "args": {
    "fonts": [
      {
        "id": "body-text",
        "title": "Body text",
        "font_family": "Inter",
        "font_size": { "size": 16, "unit": "px" },
        "font_weight": "400"
      },
      {
        "id": "display",
        "title": "Display",
        "font_family": "Inter",
        "font_size": { "size": 56, "unit": "px" },
        "font_weight": "700"
      }
    ]
  }
}
```

## Change system colors, fonts and layout together

`stonewright/elementor-v3-kit-batch-mutate` writes the system lists
(`bucket: "system"`; the default is `custom`) and kit layout settings in one
transaction. Dry-run first, then repeat with `dry_run: false`; undo with
`rollback: true` and the write's `snapshot_id` as `rollback_snapshot`.

```json
{
  "ability": "stonewright/elementor-v3-kit-batch-mutate",
  "args": {
    "dry_run": true,
    "operations": [
      {
        "group": "colors",
        "bucket": "system",
        "colors": [ { "id": "primary", "title": "Primary", "color": "#0057FF" } ]
      },
      { "group": "layout", "container_width": { "size": 1200, "unit": "px" } }
    ]
  }
}
```

## Read current page structure before editing

Always read the compact outline before making surgical edits:

```json
{
  "ability": "stonewright/elementor-v3-get-page-structure",
  "args": { "post_id": 42, "responseMode": "summary" }
}
```

Returns IDs, paths, widget types, labels, child counts, and setting keys without
the raw Elementor tree. Use `responseMode: "full"` only when raw settings are
needed for the next edit.
