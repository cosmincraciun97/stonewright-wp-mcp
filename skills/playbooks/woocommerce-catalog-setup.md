---
name: WooCommerce catalog setup
description: Set up products, categories, attributes, and catalog pages with Stonewright WooCommerce tooling.
enable_agentic: true
enable_prompt: true
---

# WooCommerce catalog setup

## Steps
1. Confirm WooCommerce via `stonewright/site-plugins-list` and `stonewright/wc-status`.
2. Create attributes and categories first with `stonewright/wc-attribute-save` and `stonewright/wc-term-save`; each call previews first (dry run), then repeat with `dry_run: false`.
3. Then create products with `stonewright/wc-product-save` and variations with `stonewright/wc-variation-save`. Use `stonewright-wp-cli-discover` with `commandFilter: ["wc","product"]` and tokenized `wp wc` only for a missing typed operation or a long batch.
4. Import product images with stock image abilities only as placeholders; prefer real product photos.
5. Flush caches/rewrites with tokenized WP-CLI when needed.

## Rules
- Never use shell `wp`; use `stonewright-wp-cli-run` argv tokens.
