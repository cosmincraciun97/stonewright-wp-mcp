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
