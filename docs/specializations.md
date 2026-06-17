# Plugin Specializations

Stonewright keeps plugin-specific behavior as guidance over direct runtime and
typed WordPress primitives.
Agents must discover the installed plugin, available REST routes, WP-CLI
commands, schemas, and value targets before writing.

Use `stonewright-workflow-preflight` first. It returns matched specialization
guidance for ACF, ACPT, Meta Box, ASE, Pods, and WooCommerce catalog work.

## Shared Workflow

- Use `stonewright-context-bootstrap` at the start of every task.
- If `stonewright-context-bootstrap` is not visible in the MCP tool list, stop
  and ask for a client reload or Stonewright MCP config fix.
- Do not inspect private client config files, create scratch helper scripts,
  create helper JSON argument files, launch the companion through ad hoc shell
  scripts, create action scripts, inspect plugin/companion source to
  reverse-engineer tool schemas, hand-roll JSON-RPC, call the REST runner from
  shell, or run shell `wp ...` commands as a Stonewright MCP workaround.
- Pass `stonewright_context_token` to write abilities.
- Use `stonewright/site-plugins-list`, `stonewright/wp-cli-status`, and
  `stonewright/wp-cli-discover` before relying on plugin commands.
- Use `stonewright/wp-cli-run` only with argv tokens.
- Use `stonewright/php-execute` for short plugin API or `$wpdb` snippets when
  direct runtime access is faster than many typed calls.
- Never use `wp eval`, `wp eval-file`, `wp shell`, `wp package`, `--exec`, or
  `--require`.
- Do not invent hidden storage keys. Discover schema first.
- Read back changed schema or values after each write pass.

## Content Model Plugins

| Plugin | Stonewright workflow | Official docs |
|---|---|---|
| ACF | Prefer ACF REST fields when enabled. Use Stonewright content/meta writes only after discovering field group, target, and field shape. Handle repeaters, groups, and flexible content by schema. | [REST integration](https://www.advancedcustomfields.com/resources/wp-rest-api-integration/), [get_field](https://www.advancedcustomfields.com/resources/get_field/), [update_field](https://www.advancedcustomfields.com/resources/update_field/), [post types and taxonomies](https://www.advancedcustomfields.com/resources/post-types-and-taxonomies/) |
| ACPT | Preserve post type, taxonomy, option page, meta group, meta box, and meta field hierarchy. Prefer documented ACPT APIs or REST integration when configured. | [Docs](https://docs.acpt.io/), [custom post types](https://docs.acpt.io/basics/custom-post-types), [custom APIs](https://docs.acpt.io/tools/custom-apis), [field types](https://docs.acpt.io/meta-fields/field-types), [REST field integration](https://docs.acpt.io/integrations/api-rest-field-integration) |
| Meta Box | Prefer MB REST API when active. Treat relationships and settings pages as schema objects. Use core meta writes only for simple registered fields. | [Custom fields](https://docs.metabox.io/custom-fields/), [MB Builder](https://docs.metabox.io/extensions/meta-box-builder/), [MB REST API](https://docs.metabox.io/extensions/mb-rest-api/), [relationships](https://docs.metabox.io/extensions/mb-relationships/), [settings pages](https://docs.metabox.io/extensions/mb-settings-page/) |
| ASE | Discover ASE custom content types, field groups, target scopes, and field shapes before editing. Keep post, term, and option-page values separate. | [Custom content types](https://www.wpase.com/features/custom-content-types/), [custom field types](https://www.wpase.com/documentation/custom-field-types/), [documentation](https://www.wpase.com/documentation/) |
| Pods | Prefer Pods REST endpoints or `wp pods` / `wp pods-api` commands. Create groups before fields and verify pod, group, and field state after writes. | [Pods](https://pods.io/), [REST API](https://docs.pods.io/advanced-topics/rest-api/), [REST endpoints](https://docs.pods.io/code/rest-api-endpoints/), [WP-CLI commands](https://docs.pods.io/code/wp-cli-commands/), [wp pods-api](https://docs.pods.io/code/wp-cli-commands/wp-pods-api/) |

## WooCommerce Catalog

For WooCommerce, prefer official REST v3 or `wp wc` commands when available.
Before writing, confirm WooCommerce is active, discover command support, check
SKU uniqueness, create attributes before variations, and read back parent
products plus generated variations.

Covered catalog scope:

- products
- product variations
- categories and tags
- global attributes and attribute terms
- shipping classes
- SKU, price, stock, sale, and image audits

Official docs:

- [WooCommerce REST API v3](https://developer.woocommerce.com/docs/apis/rest-api/v3/)
- [WooCommerce CLI commands](https://developer.woocommerce.com/docs/wc-cli/wc-cli-commands/)
- [Product shipping classes](https://developer.woocommerce.com/docs/apis/rest-api/v3/product-shipping-classes/)
- [Variable products](https://woocommerce.com/document/variable-product/)

## Skills

These built-in skills are seeded into the Stonewright Skills admin page:

- `stonewright-content-model-integrations`
- `stonewright-woocommerce-catalog`

Agents should call `stonewright-skills-get` for the matching skill when
`stonewright-context-bootstrap` or `stonewright-workflow-preflight` routes a
task to one of these specializations.
