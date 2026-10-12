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

## Queue a heading at the top

Static blocks are queued, then serialized by the block queue console and
persisted with `stonewright-blocks-finalize-batch`. Do not send `innerHTML`.

```json
{
  "ability": "stonewright/blocks-queue-change",
  "args": {
    "post_id": 42,
    "action": "insert",
    "path": [],
    "position": 0,
    "expected_content_hash": "<hash from blocks-parse>",
    "block_spec": {
      "name": "core/heading",
      "attributes": { "level": 2, "content": "Section title", "align": "center" },
      "innerBlocks": []
    }
  }
}
```

Returns `{ "queued": true, "change_id": "...", "status": "queued", "finalizer_url": "..." }`.

## Queue a group with nested columns

```json
{
  "ability": "stonewright/blocks-queue-change",
  "args": {
    "post_id": 42,
    "action": "insert",
    "block_spec": {
      "name": "core/group",
      "attributes": { "layout": { "type": "flex", "flexWrap": "nowrap" } },
      "innerBlocks": [
        {
          "name": "core/column",
          "attributes": { "width": "50%" },
          "innerBlocks": [
            { "name": "core/paragraph", "attributes": { "content": "Left column text." }, "innerBlocks": [] }
          ]
        },
        {
          "name": "core/column",
          "attributes": { "width": "50%" },
          "innerBlocks": [
            { "name": "core/image", "attributes": { "id": 77, "sizeSlug": "large" }, "innerBlocks": [] }
          ]
        }
      ]
    }
  }
}
```

Take the image `id` and `url` from a live attachment. Do not invent ids.

## Insert a dynamic block on the server

Only for a `save:null` type (for example `core/latest-posts`):

```json
{
  "ability": "stonewright/blocks-insert",
  "args": {
    "post_id": 42,
    "block": { "name": "core/latest-posts", "attrs": { "postsToShow": 3 } },
    "path": [],
    "position": 0
  }
}
```

Returns `{ "post_id": 42, "snapshot_id": "snap_...", "path": [0] }`. A block that
needs the finalizer returns `queued: true` and a `change_id` instead.

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
