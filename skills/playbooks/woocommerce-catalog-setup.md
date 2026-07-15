---
name: WooCommerce catalog setup
description: Set up products, categories, attributes, and catalog pages with Stonewright WooCommerce tooling.
enable_agentic: true
enable_prompt: true
---

# WooCommerce catalog setup

## Steps
1. Confirm WooCommerce via `stonewright/site-plugins-list`.
2. Use `stonewright-wp-cli-discover` with `commandFilter: ["wc","product"]` or official REST via WP-CLI.
3. Create categories/attributes first, then products with `stonewright/content-bulk-upsert-posts` when CPT-based.
4. Import product images with stock image abilities only as placeholders; prefer real product photos.
5. Flush caches/rewrites with tokenized WP-CLI when needed.

## Rules
- Never use shell `wp`; use `stonewright-wp-cli-run` argv tokens.
