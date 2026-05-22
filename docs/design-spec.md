# Stonewright Design Spec v1.0.0

The Stonewright Design Spec is a renderer-agnostic JSON format that describes a WordPress page. You produce one spec and Stonewright can materialize it as Gutenberg block markup, Elementor V3 widget JSON, or (experimentally) Elementor V4 atomic elements.

Schema location: `plugin/schemas/stonewright.schema.json`
Schema ID: `https://stonewright.dev/schemas/design-spec/1.0.0.json`
JSON Schema draft: 2020-12

---

## Top-level structure

```json
{
  "version": "1.0.0",
  "source": { ... },
  "page": { ... },
  "tokens": { ... },
  "responsive": { ... },
  "sections": [ ... ]
}
```

### Required fields

| Field | Type | Description |
|---|---|---|
| `version` | `"1.0.0"` | Must be the literal string `"1.0.0"`. |
| `sections` | array | One or more section objects. |

### Optional top-level fields

| Field | Type | Description |
|---|---|---|
| `source` | object | Traceability metadata — where this spec came from. |
| `page` | object | Page-level metadata (title, slug, template, status). |
| `tokens` | object | Design tokens: colors, typography, spacing, radius, shadow. |
| `responsive` | object | Breakpoint pixel values for mobile, tablet, and desktop. |

---

## source

Traceability metadata. Not required but recommended when the spec was generated from Figma or an image.

```json
"source": {
  "type": "figma",
  "url": "https://www.figma.com/file/...",
  "node_id": "123:456",
  "captured_at": "2026-05-21T12:00:00Z"
}
```

| Field | Type | Values |
|---|---|---|
| `type` | string | `figma`, `image`, `html`, `manual` |
| `url` | string (URI) | Source URL |
| `node_id` | string | Figma node ID |
| `captured_at` | string (date-time) | ISO 8601 timestamp |

---

## page

```json
"page": {
  "title": "Home",
  "slug": "home",
  "template": "full-width",
  "status": "draft"
}
```

| Field | Type | Constraint | Description |
|---|---|---|---|
| `title` | string | max 255 chars | Page title. Required if you want the renderer to set it. |
| `slug` | string | max 200 chars | URL slug. |
| `template` | string | — | Page template filename (without `.php`). |
| `status` | string | `draft`, `publish`, `private`, `pending` | Post status. |

---

## tokens

Design tokens used by renderers to populate theme-level settings and per-element styles.

```json
"tokens": {
  "colors": {
    "primary": "#1a1a2e",
    "accent": "rgb(255, 90, 31)"
  },
  "typography": {
    "heading": {
      "font_family": "Inter",
      "font_weight": "700",
      "font_size": "48px",
      "line_height": "1.1",
      "letter_spacing": "-0.02em"
    }
  },
  "spacing": {
    "section-padding": "80px",
    "column-gap": "24px"
  },
  "radius": {
    "card": "12px"
  },
  "shadow": {
    "card": "0 4px 24px rgba(0,0,0,0.08)"
  }
}
```

Color values must match the pattern `^(#|rgb|hsl|var\().*`. All other token values are free-form strings.

---

## responsive

```json
"responsive": {
  "breakpoints": {
    "mobile": 480,
    "tablet": 768,
    "desktop": 1200
  }
}
```

Integer pixel values. Used by `QA/ResponsiveCheck` to determine screenshot widths and by renderers that support responsive overrides.

---

## sections

An array of section objects. Sections map to Elementor V3 sections/containers or Gutenberg Group blocks depending on the renderer.

```json
"sections": [
  {
    "id": "hero",
    "name": "Hero section",
    "width": "full",
    "layout": "row",
    "gap": "32px",
    "padding": { "top": "80px", "bottom": "80px", "left": "0", "right": "0" },
    "background": {
      "color": "#0d0d0d"
    },
    "blocks": [ ... ]
  }
]
```

### Section fields

| Field | Type | Default | Description |
|---|---|---|---|
| `id` | string | required | Unique identifier within the spec. Used for renderer mapping. |
| `name` | string | — | Human-readable label for debugging. |
| `width` | string | `"boxed"` | `full`, `boxed`, or `narrow`. |
| `layout` | string | `"stack"` | `stack`, `row`, or `grid`. |
| `gap` | string or number | — | Gap between blocks (CSS value or px integer). |
| `padding` | dimensions | — | Per-side padding. See dimensions definition below. |
| `background` | background | — | Background color, image, overlay. |
| `blocks` | array | required | Block objects nested inside this section. |

### dimensions

```json
{ "top": "40px", "right": "20px", "bottom": "40px", "left": "20px" }
```

All four sides accept a string or integer.

### background

```json
{
  "color": "#ffffff",
  "image": "https://example.com/bg.jpg",
  "image_id": 123,
  "overlay": "rgba(0,0,0,0.4)",
  "position": "center center",
  "size": "cover"
}
```

---

## blocks

Blocks are the leaf nodes of a section or nested inside `row` and `column` blocks.

### Common fields

| Field | Type | Description |
|---|---|---|
| `type` | string | Required. One of the 14 block types listed below. |
| `style` | object | Free-form style overrides passed through to the renderer. |
| `id` | integer or string | Optional ID (e.g., media attachment ID for image blocks). |

### Block types

| Type | Extra fields | Description |
|---|---|---|
| `heading` | `text`, `level` (1-6) | Section or page heading. |
| `paragraph` | `text` | Body copy. |
| `image` | `url`, `alt`, `id` | Static image. |
| `button` | `text`, `url` | Call-to-action button. |
| `spacer` | `height` | Vertical whitespace. |
| `separator` | — | Horizontal rule. |
| `list` | `items` (array of strings) | Unordered or ordered list. |
| `icon` | `url` or SVG via `style` | Icon element. |
| `video` | `url` | Embedded video (YouTube, Vimeo, or self-hosted). |
| `embed` | `url` | oEmbed-compatible URL. |
| `slider` | `items` (array of block objects) | Carousel container. |
| `card` | `blocks` | Card container with nested blocks. |
| `row` | `blocks` | Horizontal flex container with nested blocks. |
| `column` | `blocks` | Vertical column for nesting inside a `row`. |

---

## Validation

Use the `stonewright/design/validate-spec` ability to validate a spec before rendering:

```json
{
  "tool": "stonewright/design/validate-spec",
  "arguments": {
    "spec": { "version": "1.0.0", "sections": [] }
  }
}
```

On failure the ability returns a `WP_Error` with the `stonewright_spec_invalid` code and an array of JSON schema violations. On success it returns the spec unchanged so you can pipe it directly to a renderer.

---

## Minimal valid example

```json
{
  "version": "1.0.0",
  "page": { "title": "Test Page", "status": "draft" },
  "sections": [
    {
      "id": "intro",
      "blocks": [
        { "type": "heading", "text": "Hello", "level": 1 },
        { "type": "paragraph", "text": "Welcome to Stonewright." }
      ]
    }
  ]
}
```
