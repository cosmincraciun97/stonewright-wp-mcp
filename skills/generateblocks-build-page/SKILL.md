---
name: generateblocks-build-page
description: Use when building pages with GenerateBlocks, live generateblocks/* schemas, and GeneratePress chrome when that theme is active.
version_constraints: {"generateblocks": "required"}
---

# GenerateBlocks Build Page

Use this when GenerateBlocks is active and the task is a Gutenberg page.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "generateblocks"`.
   Stop if `active` is false.
2. `stonewright-blocks-library-list-blocks` with `library: "generateblocks"`.
3. `stonewright-blocks-library-get-schema` for every `generateblocks/*` block
   you will insert. Keep the live attribute set; do not add undocumented keys.
4. If GeneratePress is active, call `stonewright-theme-chrome-get` with
   `theme: "generatepress"` and map spacing, color, and type from those tokens.

## Compose

Prefer GenerateBlocks containers and grid over generic groups when the live
list includes them. Queue `{name, attributes, innerBlocks}` through
`stonewright-blocks-queue-change` and persist with
`stonewright-blocks-finalize-batch`.

GeneratePress header, footer, and global color writes go through
`stonewright-theme-chrome-update` (dry-run first) and only `writable` keys.
The page body stays on the finalizer.

## Verify

Read the schema you queued against the live GenerateBlocks list, then confirm
desktop, tablet, and mobile rendering.
