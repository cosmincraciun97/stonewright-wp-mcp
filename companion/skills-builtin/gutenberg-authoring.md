---
{
  "name": "Gutenberg authoring",
  "description": "Build Gutenberg content in Direct mode with typed compose and post-write validation.",
  "triggers": ["gutenberg", "block", "page content", "landing"],
  "enabled": true,
  "updated_at": "2026-10-12T00:00:00.000Z"
}
---

# Gutenberg authoring (pluginless)

1. Prefer `stonewright-gutenberg-compose` typed specs for headings, paragraphs, images, columns, groups, buttons, and lists. It returns `markup` only; pass it to `stonewright-content-create` or `-update`. Custom code in `html` or `raw` markup (script, style, iframe, event handlers, `javascript:` URLs) is refused.
2. Use raw block markup only for blocks you have verified exist (core list or seen on this site via a GET).
3. After every content write, run `stonewright-gutenberg-validate` and fix any `diagnostics` and `suspicious` findings before claiming done.
4. To change part of an existing page, use `stonewright-blocks-batch-mutate`: run `dry_run:true` first, then write with `expected_content_hash` set to the `before_hash` it returned. On a remote site the write asks for a `confirmation_id`: repeat the same call, unchanged, with it. A path such as `[1, 0]` is the first inner block of the second top-level block.
5. Unknown third-party block → web-research its attributes first; never guess attribute JSON.
6. On repeated failures, call `stonewright-learning-record` and change approach.
