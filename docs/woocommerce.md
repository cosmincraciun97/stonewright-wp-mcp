# WooCommerce support

Stonewright separates store data from storefront layout. Plugin mode provides
native catalog abilities; Direct mode provides read-only product, order, and
sales access. Layout writes use the active builder's live schema.

## Support matrix

| Surface | Plugin mode | Direct mode |
|---|---|---|
| Products | Native list/get/create/update/trash/delete | Read-only list |
| Variations | Native list/create/update/delete | Not exposed |
| Categories and tags | Native list/create/update/delete | Core REST where available |
| Shipping classes | Native list/create/update/delete | Not exposed |
| Global attributes and terms | Native list/create/update/delete | Not exposed |
| Catalog audit | Bounded SKU/price/image/category/stock/variation checks | Not exposed |
| Orders | HPOS-compatible read-only list; no customer contact fields | Read-only |
| Sales | HPOS-compatible bounded summary; no customer data | Read-only |
| Storefront layout | Live builder/block schema required | Core REST/WP-CLI surface only |

Orders, refunds, customers, payments, checkout settings, taxes, shipping zones,
coupons, and extension-specific product data are not writable through the
typed WooCommerce surface in this release.

## Native abilities

Start with `stonewright-task-start`, keep its context token, then call
`stonewright-wc-status`.

- Status and audit: `wc-status`, `wc-catalog-audit`
- Products: `wc-product-list`, `wc-product-get`, `wc-product-save`,
  `wc-product-delete`
- Variations: `wc-variation-list`, `wc-variation-save`,
  `wc-variation-delete`
- Catalog terms: `wc-term-list`, `wc-term-save`, `wc-term-delete`
- Global attributes: `wc-attribute-list`, `wc-attribute-save`,
  `wc-attribute-delete`
- Commerce reads: `wc-order-list`, `wc-sales-report`

MCP tool names replace the slash with a hyphen, for example
`stonewright-wc-product-save`.

## Write contract

Catalog saves and deletes:

1. Require WooCommerce's management capability through Stonewright
   permissions.
2. Require the task context token.
3. Preview by default. Apply only with `dry_run: false`.
4. Use native WooCommerce product objects or allowlisted product taxonomies.
5. Require a confirmation token for destructive operations in
   `production-safe` mode.
6. Record an audit entry and return applied-state readback.

Product deletion moves to trash unless `force: true` explicitly requests
permanent deletion. Variations, catalog terms, and global attributes do not
have a universal trash lifecycle; their delete tools therefore preview first
and treat apply as destructive.

Use tokenized `stonewright-wp-cli-*` only for a missing typed operation or a
large batch. Use `stonewright-php-execute` only when a short call to an official
WooCommerce API is the smaller correct path. Never write hidden WooCommerce
meta by guessing keys.

## Elementor, Gutenberg, and FSE

Catalog data and presentation are different mutations:

- Elementor: inspect the installed WooCommerce widgets and each live control
  schema, then use surgical V3 batch mutation. Do not invent widget controls.
- Gutenberg: inspect registered `woocommerce/*` blocks and their exact
  attributes, then use native block abilities. Do not replace a Woo block with
  `core/html`.
- Full Site Editing: read the active template and template parts before
  changing product, catalog, cart, checkout, or account layouts. Preserve
  unknown Woo block attributes.
- Other builders: discovery proves presence only. Read the builder's official
  runtime schema/API before a write; stop if no typed or documented path exists.

## Integration discovery

`stonewright-wc-status` reports integration rows with one of three states:

- `supported`: Stonewright has a typed adapter.
- `discovery-only`: integration is detected, but live schema or official API
  discovery is required before writing.
- `unavailable`: integration was not detected.

Typed adapters currently cover Elementor, Elementor Pro, WooCommerce, and ACF.
Discovery-only inventory covers:

- Builders: Bricks, Divi 5, Beaver Builder, Breakdance, WPBakery, Etch, Mosaic.
- Themes: GeneratePress, Astra, Kadence, Avada, OceanWP, Spectra One.
- Blocks: GenerateBlocks, Kadence Blocks, Spectra.
- Forms: WPForms, Contact Form 7, Gravity Forms, Fluent Forms, Ninja Forms,
  Formidable Forms.
- Fields: JetEngine, Meta Box, ACPT, Pods, ASE.
- Add-ons/data/code: Bricksforge, Dynamic Shortcodes, Code Snippets.
- SEO: Yoast SEO, Rank Math, All in One SEO, SEOPress.

This list is detection coverage, not a claim that Stonewright can safely write
every integration.

## Compatibility and packaging

The beta CI fixture installs WooCommerce 10.9.4 on WordPress 6.9, activates
WooCommerce and Stonewright together, and checks the MCP endpoint. Release
archives are built from a clean `composer install --no-dev`; the Jetpack
Autoloader is a production dependency and every generated manifest path must
exist inside the packaged plugin.

This prevents the activation failure reported in
[issue #20](https://github.com/cosmincraciun97/stonewright-wp-mcp/issues/20),
where a stale package manifest referenced a development-only file absent from
the ZIP.

Official references:

- [WooCommerce REST API v3](https://developer.woocommerce.com/docs/apis/rest-api/v3/)
- [WooCommerce CLI commands](https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/)
- [WooCommerce project structure](https://developer.woocommerce.com/docs/getting-started/project-structure/)
- [WooCommerce plugin requirements](https://wordpress.org/plugins/woocommerce/)
