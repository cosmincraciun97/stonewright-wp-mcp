# Block Examples

## Parse a post's block tree

```json
{
  "ability": "stonewright/blocks-parse",
  "args": { "post_id": 42 }
}
```

Returns an array of block objects with `blockName`, `attrs`, `innerHTML`,
`innerBlocks` keys.

## Insert a heading at the top

```json
{
  "ability": "stonewright/blocks-insert",
  "args": {
    "post_id": 42,
    "block": {
      "name": "core/heading",
      "attrs": { "level": 2, "textAlign": "center" },
      "innerHTML": "<h2 class=\"wp-block-heading has-text-align-center\">Section title</h2>"
    },
    "path": [],
    "position": 0
  }
}
```

Returns `{ "post_id": 42, "snapshot_id": "snap_...", "path": [0] }`.

## Insert a group with nested columns

```json
{
  "ability": "stonewright/blocks-insert",
  "args": {
    "post_id": 42,
    "block": {
      "name": "core/group",
      "attrs": { "layout": { "type": "flex", "flexWrap": "nowrap" } },
      "innerHTML": "",
      "innerBlocks": [
        {
          "name": "core/column",
          "attrs": { "width": "50%" },
          "innerHTML": "",
          "innerBlocks": [
            {
              "name": "core/paragraph",
              "attrs": {},
              "innerHTML": "<p>Left column text.</p>"
            }
          ]
        },
        {
          "name": "core/column",
          "attrs": { "width": "50%" },
          "innerHTML": "",
          "innerBlocks": [
            {
              "name": "core/image",
              "attrs": { "id": 77, "sizeSlug": "large" },
              "innerHTML": "<figure class=\"wp-block-image size-large\"><img src=\"https://example.com/hero.jpg\" /></figure>"
            }
          ]
        }
      ]
    }
  }
}
```

## Update a block's attributes

```json
{
  "ability": "stonewright/blocks-update",
  "args": {
    "post_id": 42,
    "path": [0],
    "attrs": { "backgroundColor": "primary", "textColor": "background" }
  }
}
```

## Remove a block

```json
{
  "ability": "stonewright/blocks-remove",
  "args": {
    "post_id": 42,
    "path": [3]
  }
}
```

## Transform raw HTML to block markup

```json
{
  "ability": "stonewright/blocks-transform-html",
  "args": {
    "html": "<section><h1>Hello</h1><p>World</p></section>"
  }
}
```

Returns block comment markup ready for `post_content`.

## Create a synced pattern from block content

```json
{
  "ability": "stonewright/patterns-create",
  "args": {
    "title": "Promo banner",
    "slug": "promo-banner",
    "content": "<!-- wp:group --><div class=\"wp-block-group\"><!-- wp:heading --><h2>Special offer</h2><!-- /wp:heading --></div><!-- /wp:group -->",
    "status": "publish"
  }
}
```

Returns `{ "id": 201, "slug": "promo-banner" }`.

Use in page content: `<!-- wp:block {"ref":201} /-->`.
