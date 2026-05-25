# Kit Color and Typography Examples

Kit mutations are global. Always confirm with the user before writing.
Call MCP tool `stonewright-elementor-v3-backup-page` on the kit post before mutating
if you need a rollback point.

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
    "typography": [
      {
        "id": "primary",
        "title": "Primary",
        "typography_font_family": "Inter",
        "typography_font_size": { "size": 16, "unit": "px" },
        "typography_font_weight": "400"
      },
      {
        "id": "h1",
        "title": "Heading 1",
        "typography_font_family": "Inter",
        "typography_font_size": { "size": 56, "unit": "px" },
        "typography_font_weight": "700"
      }
    ]
  }
}
```

## Read current page structure before editing

Always read the tree before making surgical edits:

```json
{
  "ability": "stonewright/elementor-v3-get-page-structure",
  "args": { "post_id": 42 }
}
```

Returns the full element tree with IDs, types, and settings.
