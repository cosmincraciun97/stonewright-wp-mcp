# V4 Atomic Payload Examples

These shapes follow the Elementor V4 Atomic structure produced by Stonewright's
`AtomicRenderer`. The live props schema on the target site is authoritative.

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
          "id": "hero",
          "type": "hero",
          "background": { "color": "#0057FF" },
          "blocks": [
            { "type": "heading", "level": 1, "text": "Test heading" },
            { "type": "paragraph", "text": "Rendered from a validator-valid spec." }
          ]
        }
      ]
    },
    "dry_run": true
  }
}
```

## Typical rendered output shape

Abbreviated from `AtomicRenderer` output for a `row` section containing one
heading. Every prop is a typed `{ "$$type", "value" }` envelope; layout props
such as direction and gap become a local class style whose responsive and state
overrides live in `variants`.

```json
{
  "rendered": [
    {
      "id": "a1b2c3d",
      "elType": "e-flexbox",
      "version": "0.0",
      "isInner": false,
      "settings": {
        "classes": { "$$type": "classes", "value": ["e-a1b2c3d-style"] }
      },
      "styles": {
        "e-a1b2c3d-style": {
          "id": "e-a1b2c3d-style",
          "label": "Stonewright local style",
          "type": "class",
          "variants": [
            {
              "meta": { "breakpoint": "desktop", "state": null },
              "props": {
                "flex-direction": { "$$type": "string", "value": "row" },
                "gap": { "$$type": "size", "value": { "unit": "px", "size": 24 } }
              }
            }
          ]
        }
      },
      "editor_settings": {},
      "interactions": [],
      "elements": [
        {
          "id": "e4f5a6b",
          "elType": "widget",
          "widgetType": "e-heading",
          "settings": {
            "title": {
              "$$type": "html-v3",
              "value": { "content": { "$$type": "string", "value": "Test heading" }, "children": [] }
            },
            "tag": { "$$type": "string", "value": "h1" }
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

Atomic props reference variables through typed envelopes defined by the live
props schema, not through V3 kit globals such as
`var(--e-global-color-primary)`. Read the accepted envelope for a prop with
`stonewright/elementor-v4-describe-atomic-widget`, and read variable ids with
`stonewright/elementor-v4-list-variables`. Never guess a variable id or
envelope type.

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
