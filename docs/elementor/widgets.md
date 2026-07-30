# Elementor V3 Renderer — Supported Widgets

This table lists every widget the Stonewright Elementor V3 renderer supports.
The renderer lives in `plugin/includes/Elementor/Renderer.php` and routes
DesignSpec nodes to per-widget handler classes in
`plugin/includes/Elementor/Renderer/*.php`.

Each row shows the DesignSpec `type` value(s) that map to the widget,
the Elementor free/pro requirement, and the handler class.

| DesignSpec type(s) | Elementor widget | Free / Pro | Handler class | Notes |
|---|---|---|---|---|
| `section`, `row` | Section (flexbox container) | Free | `Renderer\Section` | Top-level layout shell. Uses Elementor V3 containers. |
| `column` | Column | Free | `Renderer\Column` | Inner column inside a section. |
| `group`, `container` | Container (flexbox) | Free | `Renderer\Container` | Generic flex wrapper; supports `direction: row|column`. |
| `heading`, `paragraph` | Heading | Free | `Renderer\Heading` | Maps to Elementor's heading widget regardless of tag; `paragraph` receives `h6` tag. |
| `text-editor`, `embed` | Text Editor | Free | `Renderer\TextEditor` | Renders arbitrary HTML content or embedded code. |
| `image` | Image | Free | `Renderer\Image` | Supports `url`, `alt`, `link`, responsive sizing via DesignSpec `size`. |
| `video` | Video | Free | `Renderer\Video` | Supports `url`; renderer maps to Elementor video widget autoplay/loop settings. |
| `button` | Button | Free | `Renderer\Button` | Supports `label`, `url`, `size`, `type` (primary/secondary). |
| `spacer` | Spacer | Free | `Renderer\Spacer` | Maps `height` in pixels to Elementor spacer height. |
| `divider` | Divider | Free | `Renderer\Divider` | Supports `style` (solid/dashed/dotted), `weight`, `color`. |
| `icon` | Icon | Free | `Renderer\Icon` | Maps to Elementor icon widget; supports Font Awesome icon names. |
| `icon-box` | Icon Box | Free | `Renderer\IconBox` | Icon + title + description. |
| `image-box` | Image Box | Free | `Renderer\ImageBox` | Image + title + description. |
| `testimonial` | Testimonial | Free | `Renderer\Testimonial` | Supports `quote`, `author`, `image`. |
| `tabs` | Tabs | Free | `Renderer\Tabs` | Array of `{title, content}` items. |
| `accordion` | Accordion | Free | `Renderer\Accordion` | Array of `{title, content}` items. |
| `toggle` | Toggle | Free | `Renderer\Toggle` | Single-open accordion variant. |
| `social-icons` | Social Icons | Free | `Renderer\SocialIcons` | Array of `{icon, url}` items. |
| `progress-bar` | Progress Bar | Free | `Renderer\ProgressBar` | Supports `label`, `percentage`, `color`. |
| `counter` | Counter | Free | `Renderer\Counter` | Supports `start`, `end`, `duration`, `suffix`, `prefix`. |
| `list` | Text Editor (fallback) | Free | `Renderer` (inline) | Elementor free has no dedicated list widget; rendered as `<ul>` inside a Text Editor widget. |
| `form`, `form-placeholder` | Form | **Pro required** | `Renderer\Form` | Returns an unsupported-node diagnostic when Elementor Pro is not active. |
| `slides`, `slider`, `card` | Slides | **Pro required** | `Renderer\Slides` | Returns an unsupported-node diagnostic when Elementor Pro is not active. |

## Pro-gated nodes

When `form`, `form-placeholder`, `slides`, `slider`, or `card` nodes appear in a spec
and Elementor Pro is not active, the renderer appends a diagnostic to the
`diagnostics` array rather than silently dropping the node:

```json
{
  "code": "unsupported_node_pro_required",
  "type": "form",
  "path": "s0.b2",
  "renderer": "elementor_v3",
  "message": "The Elementor Form widget requires Elementor Pro."
}
```

The ability still returns a partial result; only the pro-gated elements are absent.

## Unsupported nodes

Any `type` value not in the table above produces an `unsupported_node` diagnostic:

```json
{
  "code": "unsupported_node",
  "type": "custom-widget",
  "path": "s1.b0",
  "renderer": "elementor_v3",
  "message": "Spec node type is not supported by the Elementor V3 renderer."
}
```

## Adding new widget support

1. Create `plugin/includes/Elementor/Renderer/MyWidget.php` with a `static render()` method.
2. Add a `case 'my-type':` branch in `Renderer::render_block()`.
3. Write a snapshot test in `plugin/tests/Integration/ElementorV3RendererTest.php`.
4. Document the widget in this table.
