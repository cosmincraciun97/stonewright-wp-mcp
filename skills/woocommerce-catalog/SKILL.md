---
name: woocommerce-catalog
description: Use Stonewright for WooCommerce products, product variations, SKUs, prices, stock, categories, tags, attributes, attribute terms, shipping classes, catalog audits, and bulk product updates.
---

# WooCommerce Catalog

Use this when task mentions WooCommerce, products, product variations, SKUs,
prices, stock, sale state, categories, tags, attributes, attribute terms, or
shipping classes.

## First Call

Call `stonewright/workflow-preflight` with surface `woocommerce` and the task
intent. Read the returned `woocommerce` specialization.

Then call:
- `stonewright/site-plugins-list`
- `stonewright/wp-cli-status`
- `stonewright/wp-cli-discover`

Prefer WooCommerce official REST v3 or `wp wc` commands when present.

Useful docs:
- https://developer.woocommerce.com/docs/apis/rest-api/v3/
- https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/
- https://developer.woocommerce.com/docs/apis/rest-api/v3/product-shipping-classes/
- https://woocommerce.com/document/variable-product/

## Discovery Checklist

Before writing:
- Confirm WooCommerce is active.
- Discover `wp wc` command availability.
- Check product type: simple, variable, grouped, external, or downloadable.
- Check SKU uniqueness before create/update.
- Read existing categories, tags, attributes, terms, and shipping classes.
- For variable products, confirm parent product attributes before variations.

## Write Pattern

1. Call `stonewright/context-bootstrap`; pass `stonewright_context_token` to
   write tools.
2. Create or update global attributes and terms first.
3. Create or update product categories/tags/shipping classes next.
4. Create variable parent product with variation attributes.
5. Generate product variations from the confirmed attribute matrix.
6. Set default variation only after variations exist.
7. Read back parent product, attributes, variations, prices, stock, and default
   attributes.

Use `stonewright/wp-cli-run` with argv tokens only. Never use `wp eval`,
`wp eval-file`, `wp shell`, `wp package`, `--exec`, or `--require`.

## Delete Policy

Soft-delete by default. Move products, variations, terms, or classes to trash
or equivalent reversible state when available. Permanent deletion requires an
explicit user request and the production-safe confirmation token when that mode
is active.

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
