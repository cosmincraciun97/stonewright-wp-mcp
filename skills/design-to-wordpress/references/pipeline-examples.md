# Pipeline Examples

## Image or brief to page

1. Bootstrap context:

```json
{
  "ability": "stonewright/context-bootstrap",
  "mcp_tool": "stonewright-context-bootstrap",
  "args": {
    "task": "Build a responsive Elementor landing section from this design reference",
    "surface": "elementor",
    "intent": "write"
  }
}
```

2. Resolve widget intent before writing:

```json
{
  "ability": "stonewright/widget-intent-resolve",
  "mcp_tool": "stonewright-widget-intent-resolve",
  "args": {
    "prompt": "Header with logo, desktop menu, CTA, and mobile hamburger"
  }
}
```

3. Generate a widget implementation guide:

```json
{
  "ability": "stonewright/elementor-widget-implementation-guide",
  "mcp_tool": "stonewright-elementor-widget-implementation-guide",
  "args": {
    "task": "Header navigation",
    "candidate_widgets": ["nav-menu", "button", "image"],
    "design_context": "Sticky desktop header with mobile hamburger"
  }
}
```

4. Use WP-CLI when useful:

```json
{
  "ability": "stonewright/wp-cli-run",
  "mcp_tool": "stonewright-wp-cli-run",
  "args": {
    "command": ["post", "list", "--post_type=page", "--format=json"],
    "parseJson": true
  }
}
```
