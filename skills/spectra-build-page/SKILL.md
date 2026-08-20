---
name: spectra-build-page
description: Use when building pages with Spectra (uagb/*) blocks, live schemas, and the Gutenberg finalizer.
version_constraints: {"spectra": "required"}
---

# Spectra Build Page

Use this when Spectra is active and the task is a Gutenberg page. For the
Spectra One block theme itself, follow `gutenberg-fse-builder` instead of
duplicating Full Site Editing steps here.

## First call

Call `stonewright-task-start` with surface `gutenberg`. Then:

1. `stonewright-blocks-library-check-setup` with `library: "spectra"`. Stop if
   `active` is false.
2. `stonewright-blocks-library-list-blocks` with `library: "spectra"` and keep
   only `uagb/*` names.
3. `stonewright-blocks-library-get-schema` for each block you will insert. Use
   registered attributes only.

If the active theme is Blocksy, Kadence, or GeneratePress, read tokens with
`stonewright-theme-chrome-get`. Otherwise use `stonewright-fse-get-theme-json`.

## Compose

Queue Spectra blocks as `{name, attributes, innerBlocks}` through
`stonewright-blocks-queue-change`. Persist with
`stonewright-blocks-finalize-batch`. Do not bypass the finalizer with raw
markup writes.

## Verify

Confirm every inserted `uagb/*` name is in the live list, then check desktop,
tablet, and mobile in a browser tab.
