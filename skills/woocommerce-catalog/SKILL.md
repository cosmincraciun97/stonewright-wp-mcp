---
name: woocommerce-catalog
description: Use Stonewright for WooCommerce products, product variations, SKUs, prices, stock, categories, tags, attributes, attribute terms, shipping classes, catalog audits, and bulk product updates.
---

# WooCommerce Catalog

Use this when task mentions WooCommerce, products, product variations, SKUs,
prices, stock, sale state, categories, tags, attributes, attribute terms, or
shipping classes.

## First Call

Call MCP tool `stonewright-task-start` with surface `woocommerce` and the task
intent. Read the returned `woocommerce` specialization.

Then call:
- `stonewright-wc-status`
- `stonewright-wc-catalog-audit` for audit or cleanup tasks
- `stonewright-site-plugins-list` when another builder, block library, field
  plugin, or SEO plugin participates in the store

Use typed `stonewright-wc-*` abilities first. They use native WooCommerce
objects, strict field allowlists, dry-run previews, context/permission gates,
audit logging, and readback. Use tokenized `stonewright-wp-cli-*` only for a
missing typed operation or long batch. Direct mode intentionally keeps
WooCommerce read-only.

Useful docs:
- https://developer.woocommerce.com/docs/apis/rest-api/v3/
- https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/
- https://developer.woocommerce.com/docs/apis/rest-api/v3/product-shipping-classes/
- https://woocommerce.com/document/variable-product/

## Discovery Checklist

Before writing:
- Confirm WooCommerce is active.
- Check product type: simple, variable, grouped, external, or downloadable.
- Check SKU uniqueness before create/update.
- Read existing categories, tags, attributes, terms, and shipping classes.
- For variable products, confirm parent product attributes before variations.

## Write Pattern

1. Keep the `stonewright_context_token` returned by `stonewright-task-start`
   and pass it to write tools.
2. Preview every `wc-*-save` call; then repeat with `dry_run:false`.
3. Create or update global attributes and terms first.
4. Create or update product categories/tags/shipping classes next.
5. Create variable parent product with variation attributes.
6. Generate product variations from the confirmed attribute matrix.
7. Set default variation only after variations exist.
8. Read back parent product, attributes, variations, prices, stock, and default
   attributes.

Use `stonewright-php-execute` only when the typed catalog surface does not cover
an official WooCommerce API operation and a short runtime snippet is clearer
than WP-CLI. Use `stonewright-wp-cli-run` with argv tokens only. Do not run
`wp ...` in a normal shell as Stonewright recovery, and do not use another PHP
adapter to replace Stonewright tools.
Never use `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, or
`--require`.
For long imports, cache rebuilds, or bulk catalog maintenance, use
`stonewright-wp-cli-job-start` and poll `stonewright-wp-cli-job-status`.

## Delete Policy

Soft-delete by default. Move products, variations, terms, or classes to trash
or equivalent reversible state when available. Permanent deletion requires an
explicit user request and the production-safe confirmation token when that mode
is active.

## Storefront Layout Routing

Catalog data and storefront layout are separate writes.

- Elementor: confirm Elementor Pro/Woo widgets in `stonewright-wc-status`, read
  each live widget schema, then use `stonewright-elementor-v3-batch-mutate`.
  Never guess product, cart, checkout, or account widget settings.
- Gutenberg: list registered `woocommerce/*` block types, read the exact block
  schema, then use native Gutenberg abilities. Do not reproduce a Woo block
  with `core/html`.
- FSE/block themes: read the active template and template-part structure before
  changing shop, product, cart, checkout, or account templates. Keep Woo blocks
  native and preserve unknown block attributes.
- Other builders: `stonewright-wc-status` reports detected integrations and
  whether support is typed or discovery-only. Discovery-only means read the
  builder's official live schema/API and stop before blind writes.

## Audit Pattern

For audits, return:
- product count by type and stock status
- duplicate or missing SKUs
- products missing required images, categories, prices, or attributes
- variable parents with missing variations or invalid default attributes
- empty categories, unused attributes, and unassigned shipping classes
- exact command/output evidence used for the audit

## Bulk Updates

Batch when the official surface supports it. For each batch, return changed,
skipped, failed, and per-item error details. After bulk writes, sample-read a
few changed products and read total counts again.
