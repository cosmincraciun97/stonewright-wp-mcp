# Kit Color and Typography Examples

Kit mutations are global. Always confirm with the user before writing.
Each kit mutation takes its own snapshot and returns `snapshot_id`. In
`production-safe` mode it also needs a `confirmation_token` issued for the exact call.

## Update kit colors

```json
{
  "ability": "stonewright/elementor-v3-update-kit-colors",
  "args": {
    "colors": [
      { "id": "primary", "title": "Primary", "color": "#0057FF" },
      { "id": "secondary", "title": "Secondary", "color": "#1A1A2E" },
      { "id": "accent",    "title": "Accent",    "color": "#FF6B35" },
      { "id": "text",      "title": "Text",       "color": "#1A1A1A" },
      { "id": "background","title": "Background", "color": "#FFFFFF" }
    ]
  }
}
```

## Update kit typography

```json
{
  "ability": "stonewright/elementor-v3-update-kit-typography",
  "args": {
    "fonts": [
      {
        "id": "primary",
        "title": "Primary",
        "font_family": "Inter",
        "font_size": { "size": 16, "unit": "px" },
        "font_weight": "400"
      },
      {
        "id": "h1",
        "title": "Heading 1",
        "font_family": "Inter",
        "font_size": { "size": 56, "unit": "px" },
        "font_weight": "700"
      }
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
