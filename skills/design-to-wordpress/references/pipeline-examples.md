# Pipeline Examples

## 1. Figma URL to new page

### Step 1: import Figma node

```json
{
  "ability": "stonewright/design-import-figma-node",
  "args": {
    "url": "https://www.figma.com/file/ABC123/My-Site?node-id=10%3A42",
    "figma_token": "figd_..."
  }
}
```

Response: a spec stub with `sections`, `tokens`, `page` keys.

### Step 2: extract tokens (if Figma variables export available)

```json
{
  "ability": "stonewright/design-extract-tokens",
  "args": {
    "source": "figma_variables",
    "payload": { "variables": [...] }
  }
}
```

Response: `{ "colors": {...}, "typography": {...}, "spacing": {...} }`.

### Step 3: build spec

```json
{
  "ability": "stonewright/design-build-spec",
  "args": {
    "page": { "title": "Home", "slug": "home" },
    "tokens": { "colors": { "primary": "#0057FF" } },
    "sections": [
      {
        "type": "hero",
        "heading": "Welcome",
        "subheading": "Build faster.",
        "background": { "color": "#0057FF" }
      }
    ]
  }
}
```

### Step 4: validate

```json
{
  "ability": "stonewright/design-validate-spec",
  "args": { "spec": { ...spec from step 3... } }
}
```

### Step 5: choose renderer

```json
{
  "ability": "stonewright/design-choose-renderer",
  "args": { "spec": { ...normalized spec... } }
}
```

Returns: `{ "renderer": "gutenberg" }` or `{ "renderer": "elementor_v3" }`.

### Step 6: normalize assets

```json
{
  "ability": "stonewright/design-normalize-assets",
  "args": { "spec": { ...normalized spec... } }
}
```

### Step 7a: render to Gutenberg

```json
{
  "ability": "stonewright/design-spec-to-gutenberg",
  "args": {
    "spec": { ...normalized spec... },
    "post_id": 0,
    "dry_run": true
  }
}
```

Set `dry_run: false` only after confirming the output looks correct.

### Step 7b: render to Elementor V3 (when active)

```json
{
  "ability": "stonewright/design-spec-to-elementor-v3",
  "args": {
    "spec": { ...normalized spec... },
    "post_id": 42
  }
}
```

### Step 8: create page

```json
{
  "ability": "stonewright/content-create-page",
  "args": {
    "title": "Home",
    "slug": "home",
    "status": "draft",
    "content": "<!-- wp:paragraph --><p>placeholder</p><!-- /wp:paragraph -->"
  }
}
```

## 2. Image reference to page

```json
{
  "ability": "stonewright/design-import-image",
  "args": {
    "source": "url",
    "url": "https://example.com/mockup.png"
  }
}
```

Returns a minimal spec stub. Complete the `sections` array using the vision
pipeline, then follow steps 3-8 above.
