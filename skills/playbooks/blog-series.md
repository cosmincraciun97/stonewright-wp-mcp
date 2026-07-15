---
name: Blog series
description: Plan and create a multi-post blog series with shared structure, categories, and featured images.
enable_agentic: true
enable_prompt: true
---

# Blog series

## Steps
1. Define series outline with the user (titles + order).
2. Ensure category/tag via taxonomy tools or WP-CLI.
3. Bulk create drafts with `stonewright/content-bulk-upsert-posts`.
4. Attach featured images (`stock-image-search`/`import` or library reuse).
5. Link posts with a series intro block or related-posts section.

## Rules
- Keep posts as draft until the user asks to publish.
