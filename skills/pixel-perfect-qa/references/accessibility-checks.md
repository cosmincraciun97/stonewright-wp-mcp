# Accessibility Checks

## WCAG criteria covered in the QA loop

The pixel-perfect-qa skill checks these criteria automatically:

| WCAG | Criterion | Check |
|---|---|---|
| 1.1.1 | Non-text Content | All `img` elements have non-empty `alt` attributes |
| 1.4.3 | Contrast (Minimum) | Text/background contrast ratio >= 4.5:1 (AA) |
| 1.4.11 | Non-text Contrast | UI component contrast ratio >= 3:1 |
| 2.4.6 | Headings and Labels | Page has a logical heading hierarchy (h1 -> h2 -> h3) |
| 4.1.2 | Name, Role, Value | Interactive elements have accessible names |

## Fixing missing alt text

```json
{
  "ability": "stonewright/media-set-alt",
  "args": {
    "attachment_id": 77,
    "alt": "A developer working at a standing desk with dual monitors"
  }
}
```

For decorative images, pass `"alt": ""` explicitly.

## Fixing color contrast (Elementor V3)

Read the current element color settings:

```json
{
  "ability": "stonewright/elementor-v3-get-element",
  "args": { "post_id": 42, "element_id": "b3c4d5e6" }
}
```

Then update with a passing color:

```json
{
  "ability": "stonewright/elementor-v3-update-element",
  "args": {
    "post_id": 42,
    "element_id": "b3c4d5e6",
    "settings": {
      "button_text_color": "#FFFFFF",
      "button_background_color": "#0057FF"
    }
  }
}
```

Contrast ratio for white (#FFFFFF) on #0057FF = 7.2:1. Passes AA and AAA.

## Fixing color contrast (Gutenberg)

Update the block's color attributes:

```json
{
  "ability": "stonewright/blocks-update",
  "args": {
    "post_id": 42,
    "path": [1, 0],
    "attrs": { "textColor": "white", "backgroundColor": "primary" }
  }
}
```

## Heading hierarchy check

Parse blocks and inspect heading levels:

```json
{ "ability": "stonewright/blocks-parse", "args": { "post_id": 42 } }
```

Find all `core/heading` blocks and verify `attrs.level` sequence is valid.
For Elementor, read the page structure and check widget_type = "heading" with
`settings.header_size` values.

## Contrast ratio formula

For reference when evaluating proposed color changes:
- Relative luminance: L = 0.2126*R + 0.7152*G + 0.0722*B (linearised)
- Contrast ratio: (L1 + 0.05) / (L2 + 0.05)  where L1 > L2

Tools: use the WebAIM contrast checker or pass the hex values through the
companion accessibility utility.
