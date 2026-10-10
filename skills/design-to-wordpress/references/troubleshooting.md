# Troubleshooting

## invalid_spec

`design-build-spec` or `design-validate-spec` returned errors.

- Read the `errors` array carefully; each entry has `path_string`, `keyword` and `message`.
- Common causes: missing required `sections` items, an unsupported block
  `type`, token colors that do not start with `#`, `rgb`, `hsl` or `var(`.
- Fix the spec argument and retry `design-build-spec`. Do not pass an invalid
  spec downstream.

## asset_not_found / unresolved_url

A section references an image URL that `design-normalize-assets` could not
sideload. In production-safe mode a call that sideloads needs a
`confirmation_token` issued for its arguments; `sideload: false` needs none.

- Check the URL is publicly accessible from the WordPress server (not localhost
  or behind auth).
- Upload the asset manually with `stonewright/media-upload` and replace the URL
  in the spec with the returned attachment URL.

## renderer_missing

`design-spec-to-elementor-v4` errored with `renderer_missing`.

- This build does not include the V4 renderer. Fall back to `elementor_v3` or
  `gutenberg`.
- Check `stonewright/site-capabilities` to confirm available renderers.

## feature_disabled (V4)

The `stonewright_elementor_v4_atomic` option is off.

- Either enable it in wp-options or use the V3/Gutenberg path.
- Never enable it on production without testing on staging first.

## write_failed (Elementor)

`elementor-v3-build-page-from-spec` could not save Elementor data.

- Confirm post exists: call `stonewright-content-get-page` with the post_id.
- Confirm Elementor is active: call `stonewright-elementor-v3-status`.
- Confirm the snapshot succeeded (snapshot_id is present in the response).

## Confirmation token flow

When updating an existing page you must emit a confirmation token before
writing:

```
"Confirm update:
  post_id: 42
  snapshot_id: snap_1716300000_42
  action: replace_page_content
Reply YES to proceed."
```

Only call the write ability after the user replies YES. In production-safe mode
the write also carries a `confirmation_token` issued for exactly its arguments;
a dry run (`dry_run: true`) needs none, and a token issued for a dry run does not
authorize the write.
