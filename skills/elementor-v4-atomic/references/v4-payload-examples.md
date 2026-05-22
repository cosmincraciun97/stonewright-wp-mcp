# V4 Atomic Payload Examples

These are illustrative shapes based on the Elementor V4 atomic structure.
The actual keys returned by `design-spec-to-elementor-v4` depend on the
bundled renderer version.

## Minimal dry-run call

```json
{
  "ability": "stonewright/design-spec-to-elementor-v4",
  "args": {
    "spec": {
      "version": "1.0.0",
      "page": { "title": "Staging test" },
      "tokens": {
        "colors": { "primary": "#0057FF" },
        "typography": { "body": { "font_family": "Inter" } }
      },
      "sections": [
        {
          "type": "hero",
          "heading": "Test heading",
          "background": { "color": "#0057FF" }
        }
      ]
    },
    "dry_run": true
  }
}
```

## Typical rendered output shape

```json
{
  "rendered": [
    {
      "id": "e1000001",
      "type": "container",
      "settings": {
        "flex_direction": "column",
        "align_items": "center",
        "background_color": "var(--e-global-color-primary)",
        "padding": { "top": "80px", "bottom": "80px" }
      },
      "classes": ["e-hero-section"],
      "elements": [
        {
          "id": "e1000002",
          "type": "widget",
          "widgetType": "heading",
          "settings": {
            "title": "Test heading",
            "typography_font_family": "var(--e-global-typography-h1-font-family)",
            "typography_font_weight": "700"
          },
          "elements": []
        }
      ]
    }
  ],
  "dry_run": true
}
```

## Variable references

V4 elements reference global variables with CSS custom property syntax:

| Context | Example value |
|---|---|
| Color token | `var(--e-global-color-primary)` |
| Typography | `var(--e-global-typography-h1-font-family)` |
| Spacing | `var(--e-global-spacing-lg)` |

## Checking feature flags before calling

```json
{
  "ability": "stonewright/site-capabilities",
  "args": {}
}
```

Inspect:
- `integrations.elementor_v4` must be `true`
- `feature_flags.elementor_v4_atomic` must be `true`

If either is false, do not call the V4 renderer.
