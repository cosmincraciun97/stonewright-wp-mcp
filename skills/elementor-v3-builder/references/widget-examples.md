# Widget Examples

## Add a container (section row)

```json
{
  "ability": "stonewright/elementor-v3-add-container",
  "args": {
    "post_id": 42,
    "settings": {
      "flex_direction": "row",
      "flex_gap": { "size": 20, "unit": "px" },
      "padding": { "top": "40", "right": "0", "bottom": "40", "left": "0", "unit": "px", "isLinked": false }
    }
  }
}
```

Returns `{ "element_id": "a1b2c3d4" }`.

## Add a heading widget inside a container

```json
{
  "ability": "stonewright/elementor-v3-add-widget",
  "args": {
    "post_id": 42,
    "parent_id": "a1b2c3d4",
    "widget_type": "heading",
    "settings": {
      "title": "Build faster.",
      "header_size": "h1",
      "align": "center",
      "title_color": "#0057FF"
    }
  }
}
```

## Add a text-editor widget

```json
{
  "ability": "stonewright/elementor-v3-add-widget",
  "args": {
    "post_id": 42,
    "parent_id": "a1b2c3d4",
    "widget_type": "text-editor",
    "settings": {
      "editor": "<p>Your content here.</p>"
    }
  }
}
```

## Add an image widget

```json
{
  "ability": "stonewright/elementor-v3-add-widget",
  "args": {
    "post_id": 42,
    "parent_id": "a1b2c3d4",
    "widget_type": "image",
    "settings": {
      "image": { "url": "https://example.com/hero.jpg", "id": 77 },
      "image_size": "large",
      "align": "center"
    }
  }
}
```

## Add a button widget

```json
{
  "ability": "stonewright/elementor-v3-add-widget",
  "args": {
    "post_id": 42,
    "parent_id": "a1b2c3d4",
    "widget_type": "button",
    "settings": {
      "text": "Get started",
      "link": { "url": "/contact", "is_external": false },
      "button_type": "default",
      "align": "center"
    }
  }
}
```

## Update an existing element's settings

```json
{
  "ability": "stonewright/elementor-v3-update-element",
  "args": {
    "post_id": 42,
    "element_id": "a1b2c3d4",
    "settings": {
      "background_color": "#F5F5F5"
    }
  }
}
```

## Get available widget types

```json
{ "ability": "stonewright/elementor-v3-list-widgets", "args": {} }
```

## Get schema for a specific widget

```json
{
  "ability": "stonewright/elementor-v3-get-widget-schema",
  "args": { "name": "heading" }
}
```
