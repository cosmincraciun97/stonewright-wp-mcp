# V4 Atomic Troubleshooting

## feature_disabled

`permission_callback` rejected the call because `stonewright_elementor_v4_atomic`
is not set in wp-options.

Resolution: ask the user to run in WP-CLI:
```
wp option update stonewright_elementor_v4_atomic 1
```
or toggle the option via the Stonewright settings screen.

## renderer_missing

The `ElementorV4SpecRenderer` class is absent from this plugin build. This
renderer is conditionally included. Check the plugin version and whether
the V4 add-on was bundled.

Resolution: downgrade to `design-spec-to-elementor-v3` for the same spec.

## stonewright_v4_unknown_node

A block type has no certified Atomic schema. The message names the block type
and its spec path (for example `sections.0.blocks.1`) and lists the block types
that render: `heading`, `paragraph`, `image`, `button`, `separator`, `icon`,
`row`, `column`, `card`. `spacer`, `list`, `video`, `embed` and `slider` do not.

Resolution: remove the block, replace it with a supported one, or build the
page with the V3 renderer. Do not retry the same spec.

## stonewright_v4_unsupported_property

A section, row, column, card or leaf block carries a property the V4 renderer
cannot write (for example `margin`, a `boxed` or `narrow` `width`, a `grid`
layout, a background image, `css_classes`, `hide_on`, or any styling key on a
leaf block). The message and `data.path` name the property and its spec path.
Nothing was written.

Resolution: remove the property, move styling to the parent container, or use
the V3 renderer. Supported container styling is `layout`/`direction`, `gap`,
`padding`, `background.color`, `width: full`, `justify_content`,
`align_items` and `z_index`.

## elementor_v4 integration false

`stonewright/site-capabilities` returned `integrations.elementor_v4: false`.
Elementor is either not active or below version 4.0.0.

Resolution: use the Elementor V3 builder skill.

## Stale element IDs in dry-run output

V4 element IDs are generated at render time. They are not stable across
multiple dry-run calls. Do not cache element IDs for use in subsequent
editing operations.

## Writing V4 data with V3 abilities

Do not pass V4 atomic JSON to `elementor-v3-build-page-from-spec` or
`elementor-v3-add-container`. The data structures are incompatible and will
corrupt the page's Elementor meta. Write V4 data only through the V4 write
path (companion layer or approved WP-CLI command).
