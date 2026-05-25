# FSE and theme.json Examples

## Read current theme.json

```json
{ "ability": "stonewright/fse-get-theme-json", "args": {} }
```

Returns the merged theme.json object (theme defaults + user overrides).

## Update global styles (colors + typography)

Always call MCP tool `stonewright-site-backup-page` on a representative page before
this, and confirm with the user. Changes are site-wide.

```json
{
  "ability": "stonewright/fse-update-global-styles",
  "args": {
    "styles": {
      "color": {
        "background": "#FFFFFF",
        "text": "#1A1A1A"
      },
      "typography": {
        "fontFamily": "Inter, sans-serif",
        "fontSize": "16px",
        "lineHeight": "1.6"
      }
    },
    "settings": {
      "color": {
        "palette": [
          { "slug": "primary",    "color": "#0057FF", "name": "Primary" },
          { "slug": "secondary",  "color": "#1A1A2E", "name": "Secondary" },
          { "slug": "background", "color": "#FFFFFF",  "name": "Background" }
        ]
      },
      "typography": {
        "fontFamilies": [
          {
            "slug": "inter",
            "name": "Inter",
            "fontFamily": "Inter, sans-serif"
          }
        ]
      }
    }
  }
}
```

## List all templates

```json
{ "ability": "stonewright/fse-list-templates", "args": {} }
```

Returns an array of `{ "id": "theme//slug", "slug": "...", "title": "...", "type": "wp_template" }`.

## Update a template

```json
{
  "ability": "stonewright/fse-update-template",
  "args": {
    "id": "twentytwentyfour//home",
    "type": "wp_template",
    "content": "<!-- wp:template-part {\"slug\":\"header\",\"theme\":\"twentytwentyfour\",\"area\":\"header\"} /-->\n<!-- wp:group {\"tagName\":\"main\"} --><main class=\"wp-block-group\"><!-- wp:post-content /--></main><!-- /wp:group -->\n<!-- wp:template-part {\"slug\":\"footer\",\"theme\":\"twentytwentyfour\",\"area\":\"footer\"} /-->"
  }
}
```

## Create a new template part

```json
{
  "ability": "stonewright/fse-create-template-part",
  "args": {
    "slug": "promo-bar",
    "title": "Promo Bar",
    "area": "uncategorized",
    "content": "<!-- wp:group {\"backgroundColor\":\"primary\"} --><div class=\"wp-block-group has-primary-background-color has-background\"><!-- wp:paragraph {\"textColor\":\"background\"} --><p class=\"has-background-color has-text-color\">Free shipping on orders over $50</p><!-- /wp:paragraph --></div><!-- /wp:group -->"
  }
}
```

## Use template part in a template

Reference by area + slug:
```
<!-- wp:template-part {"slug":"promo-bar","area":"uncategorized"} /-->
```
