---
name: blocksy-build-page
description: Use when building Blocksy pages with live block schemas, theme chrome tokens, and the Gutenberg finalizer.
version_constraints: {"blocksy": "required"}
---

# Blocksy Build Page

Use this when the active theme is Blocksy and the task is a Gutenberg page,
not an Elementor canvas.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "blocksy"`. Stop if
   `active` is false.
2. `stonewright-theme-chrome-get` with `theme: "blocksy"` and reuse the returned
   colors, typography, header, and footer tokens. Do not invent Customizer keys.
3. If GenerateBlocks, Kadence Blocks, or Spectra is also active, call
   `stonewright-blocks-library-list-blocks` then
   `stonewright-blocks-library-get-schema` for each block you will insert.
   Otherwise list core types with `stonewright-blocks-list-registered`.

## Compose

Queue every section as `{name, attributes, innerBlocks}` through
`stonewright-blocks-queue-change`. Persist only with
`stonewright-blocks-finalize-batch`. Do not hand-write block HTML.

Style with Blocksy tokens from theme chrome and `theme.json`. Header and footer
chrome stays on `stonewright-theme-chrome-update` (dry-run first). The page
body never goes through a theme-specific write path.

## Verify

Read back the queued batch, then confirm desktop, tablet, and mobile in a
browser tab. If Blocksy chrome changed, re-read `stonewright-theme-chrome-get`.
