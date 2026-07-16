---
{
  "name": "Gutenberg authoring",
  "description": "Build Gutenberg content in Direct mode with typed compose and post-write validation.",
  "triggers": ["gutenberg", "block", "page content", "landing"],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# Gutenberg authoring (pluginless)

1. Prefer `stonewright-gutenberg-compose` typed specs for headings, paragraphs, images, columns, groups, buttons, and lists.
2. Use raw block markup only for blocks you have verified exist (core list or seen on this site via a GET).
3. After every content write, run `stonewright-gutenberg-validate` and fix any `suspicious` findings before claiming done.
4. Unknown third-party block → web-research its attributes first; never guess attribute JSON.
5. On repeated failures, call `stonewright-learning-record` and change approach.
