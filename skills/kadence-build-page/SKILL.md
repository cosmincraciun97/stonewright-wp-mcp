---
name: kadence-build-page
description: Use when building pages with Kadence Blocks, live kadence/* schemas, and Kadence Theme chrome when that theme is active.
version_constraints: {"kadence-blocks": "required"}
---

# Kadence Build Page

Use this when Kadence Blocks is active and the task is a Gutenberg page.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "kadence"`. Stop if
   `active` is false.
2. `stonewright-blocks-library-list-blocks` with `library: "kadence"` and keep
   only `kadence/*` names.
3. `stonewright-blocks-library-get-schema` for each block you will insert. Use
   those attributes only; never invent controls.
4. If the active theme is Kadence, call `stonewright-theme-chrome-get` with
   `theme: "kadence"` and reuse live palette, type, header, and footer tokens.

## Compose

Build rows, sections, and inner content as `{name, attributes, innerBlocks}`
and queue them with `stonewright-blocks-queue-change`. Persist with
`stonewright-blocks-finalize-batch`. Do not serialize markup by hand.

Theme chrome writes use `stonewright-theme-chrome-update` with `dry_run: true`
first, and only keys returned as `writable`. Page body stays on the finalizer.

## Verify

Confirm each queued block name is in the live Kadence list, then check the
rendered page at desktop, tablet, and mobile.
