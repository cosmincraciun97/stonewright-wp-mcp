---
name: Blog series
description: Plan and create a multi-post blog series with shared structure, categories, and featured images.
enable_agentic: true
enable_prompt: true
---

# Blog series

## Steps
1. Define series outline with the user (titles + order).
2. Ensure the category/tag exists first (post tools take term ids): tokenized `stonewright-wp-cli-run` with `term create`.
3. Bulk create drafts with `stonewright/content-bulk-upsert-posts` (`post_type`, `items` with `slug` and `title`; items have no category field, so use `stonewright/content-create-post` with `categories`/`tags` ids when terms are needed at creation).
4. Attach featured images (`stock-image-search`/`import` with `parent_post_id`, or library reuse via `media-list`).
5. Link posts with a series intro block or related-posts section.

## Rules
- Keep posts as draft until the user asks to publish.
